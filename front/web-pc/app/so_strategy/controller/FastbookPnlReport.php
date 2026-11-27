<?php
declare(strict_types=1);

namespace app\so_strategy\controller;

use app\so_strategy\service\FastbookAccounts;
use app\so_strategy\service\FastbookPnlReportQuery;
use think\admin\Controller;
use think\facade\Db;
use think\Paginator;

/**
 * 快盘盈亏报表。
 *
 * 用户盈亏报表：客户视角，按账号汇总，盈亏为客户 PNL（卖出-买入+赢家净份额），降序。
 * 盘口盈亏报表：做市商视角，按 Outcome（一轮开盘窗口）汇总，盈亏为公司快盘 PNL，默认按创建时间降序。
 *
 * 查询全部走 FastbookPnlReportQuery 的索引下推：先用 market_results / market_options
 * 的时间索引求出候选 Outcome，再只聚合这些 Outcome 的成交，避免 trade_records 全表聚合。
 */
class FastbookPnlReport extends Controller
{
    private const CONNECTION = 'db_fastbook';
    private const PAGE_SIZE = 20;
    /** 最大可查询跨度（天），超过需自行缩短范围。 */
    private const MAX_RANGE_DAYS = 90;
    /** 未指定范围时的默认跨度（天）。 */
    private const DEFAULT_RANGE_DAYS = 30;

    /**
     * 用户盈亏报表。
     * @auth true
     * @menu true
     */
    public function userPnl()
    {
        return $this->renderReport('user');
    }

    /**
     * 盘口盈亏报表。
     * @auth true
     * @menu true
     */
    public function outcomePnl()
    {
        return $this->renderReport('outcome');
    }

    /**
     * 按列表当前筛选条件导出 CSV。
     * @auth true
     */
    public function export(): void
    {
        @set_time_limit(0);
        $key = (string)$this->request->get('dataset', '');
        try {
            $definition = $this->definition($key);
        } catch (\InvalidArgumentException $e) {
            $this->error('未知的报表类型');
            return;
        }
        $filter = $this->buildFilter($this->request->get(), $key);
        $excluded = FastbookAccounts::excludedIds();
        $query = new FastbookPnlReportQuery();

        // 与页面同一套列定义：页面与导出都用原始精度，去掉「详情」操作列。
        $columns = [];
        foreach ($definition['columns'] as $column) {
            if (empty($column['detail'])) {
                $columns[] = ['field' => $column['field'], 'label' => $column['label']];
            }
        }

        $filename = 'fastbook_' . $key . '_pnl_' . date('YmdHis') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $handle = fopen('php://output', 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_column($columns, 'label'), ',', '"', '');

        // 不带 LIMIT：只跑一次聚合，再用非缓冲游标逐行取，避免 OFFSET 分批反复重算同一条聚合 SQL。
        $connection = $this->db();
        $pdo = $connection->getPdo();
        $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $rowStmt = $key === 'user'
            ? $query->userPnlSql($filter, $excluded)
            : $query->outcomePnlSql($filter, $excluded);
        $statement = $pdo->prepare($rowStmt['sql']);
        $statement->execute($rowStmt['binds']);
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $row = $this->decorate($key, [$row])[0];
            $line = [];
            foreach ($columns as $column) {
                $line[] = $this->csvValue($row[$column['field']] ?? '', $column['field']);
            }
            fputcsv($handle, $line, ',', '"', '');
        }
        fclose($handle);
        exit;
    }

    /** 与 FastbookData 一致：转义公式型文本，负数金额保持数值便于汇总。 */
    private function csvValue($value, string $field): string
    {
        if ($value === null) {
            return '';
        }
        $text = is_scalar($value) ? (string)$value : '';
        // 时间列：先去掉 datetime(6) 的微秒，再补一个制表符前缀。
        // 前缀沿用 FastbookData 里 ID 列的做法，让 Excel 按文本原样显示，
        // 否则 Excel 会把它当日期时间重新格式化成 2026/9/28 8:00，秒被吃掉。
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)?$/', $text, $m)) {
            return "\t" . $m[1];
        }
        if ($text !== '' && !is_numeric($text) && preg_match('/^[=+\-@]/', $text)) {
            $text = "'" . $text;
        }
        // CSV 引号不能指定单元格类型；盈/亏计数（如 1 / 6）会被 Excel/WPS 推断为日期。
        // 沿用时间列的文本保护方式，保持页面的「盈利数 / 亏损数」显示。
        if ($field === 'win_lose_outcomes' && $text !== '') {
            return "\t" . $text;
        }
        return (string)$text;
    }

    private function renderReport(string $key)
    {
        $definition = $this->definition($key);
        $this->title = $definition['title'];

        $get = $this->request->get();
        unset($get['pageSize'], $get['page']);
        $page = max(1, (int)($this->request->get('page', 1)));
        $filter = $this->buildFilter($get, $key);
        $excluded = FastbookAccounts::excludedIds();
        $query = new FastbookPnlReportQuery();

        // 把排序的当前值回填到 $get，使表单下拉正确选中、导出链接也带上这些参数。
        $get['sort_field'] = $filter['sort_field'];
        $get['sort_dir'] = $filter['sort_dir'];

        foreach ($definition['filters'] as &$item) {
            $item['value'] = (string)($get[$item['name']] ?? '');
        }
        unset($item);

        $rows = [];
        $total = 0;
        $candidateCount = 0;
        $notice = $definition['notice']
            . sprintf(' 实际查询范围 %s ~ %s（UTC），单次跨度上限 %d 天。', $filter['start'], $filter['end'], self::MAX_RANGE_DAYS);

        $candidateStmt = $query->candidateCountSql($filter);
        $candidateCount = (int)($this->db()->query($candidateStmt['sql'], $candidateStmt['binds'])[0]['total'] ?? 0);

        if ($candidateCount > 0) {
            $countStmt = $key === 'user'
                ? $query->userPnlCountSql($filter, $excluded)
                : $query->outcomePnlCountSql($filter, $excluded);
            $total = (int)($this->db()->query($countStmt['sql'], $countStmt['binds'])[0]['total'] ?? 0);
            if ($total > 0) {
                $offset = ($page - 1) * self::PAGE_SIZE;
                $rowStmt = $key === 'user'
                    ? $query->userPnlSql($filter, $excluded, self::PAGE_SIZE, $offset)
                    : $query->outcomePnlSql($filter, $excluded, self::PAGE_SIZE, $offset);
                $rows = $this->db()->query($rowStmt['sql'], $rowStmt['binds']);
                $rows = $this->decorate($key, $rows);
            }
        }

        $pageOptions = [
            'path' => '/admin.html#/so_strategy/fastbook_pnl_report/' . ($key === 'user' ? 'userpnl' : 'outcomepnl') . '.html',
            'query' => $get,
        ];
        $this->assign([
            'dataset' => $key,
            'columns' => $definition['columns'],
            'filters' => $definition['filters'],
            'list' => $rows,
            'notice' => $notice,
            'range_start' => $filter['start'],
            'range_end' => $filter['end'],
            'export_query' => http_build_query(['dataset' => $key] + $get),
            'pagehtml' => Paginator::make($rows, self::PAGE_SIZE, $page, $total, false, $pageOptions)->render(),
            'navigation_path' => $pageOptions['path'],
        ]);
        return $this->fetch('fastbook_pnl_report/index');
    }

    /**
     * 展示层加工：拼接盈/亏盘口数与详情链接。
     *
     * 金额一律保留数据库原始精度（decimal(28,10)），与成交记录、账号 PNL 明细以及 CSV 导出口径一致；
     * 页面不做二次四舍五入，避免同一份数据出现两套数值。
     */
    private function decorate(string $key, array $rows): array
    {
        $tradeUrl = '/so_strategy/fastbook_data/traderecords.html';
        foreach ($rows as &$row) {
            if (isset($row['win_outcomes'], $row['lose_outcomes'])) {
                $row['win_lose_outcomes'] = $row['win_outcomes'] . ' / ' . $row['lose_outcomes'];
            }
            $params = [
                'market_config_id' => $row['market_config_id'] ?? '',
                'outcome_id' => $row['outcome_id'] ?? '',
            ];
            if ($key === 'user') {
                $params = ['account_id' => $row['account_id'] ?? ''];
            }
            $row['detail_url'] = $tradeUrl . '?' . http_build_query(array_filter($params, static fn($v) => (string)$v !== ''));
        }
        unset($row);
        return $rows;
    }

    /** 筛选项清洗：时间范围必填且受跨度上限约束，其余值全部作为参数绑定。 */
    private function buildFilter(array $get, string $key = 'user'): array
    {
        // 与数据洞察一致：页面提交的是两个独立的 UTC 日期，不再解析拼接字符串。
        [$start, $end] = $this->resolveRange(
            $this->text((string)($get['range_start'] ?? ''), 10),
            $this->text((string)($get['range_end'] ?? ''), 10)
        );
        return [
            'start' => $start,
            'end' => $end,
            'account_id' => $this->text($get['account_id'] ?? '', 64),
            'market_config_id' => $this->text($get['market_config_id'] ?? '', 32),
            'outcome_id' => $this->text($get['outcome_id'] ?? '', 160),
            'direction' => in_array(($get['direction'] ?? ''), ['profit', 'loss'], true) ? $get['direction'] : '',
            // sort 仅允许白名单字段，杜绝注入。盘口报表默认按创建时间（盘口开始时间）降序，用户报表仍按盈亏金额降序。
            'sort_field' => in_array(($get['sort_field'] ?? ''), ['pnl', 'trade_count', 'user_count', 'window_start_time', 'last_trade_at'], true)
                ? $get['sort_field']
                : ($key === 'outcome' ? 'window_start_time' : 'pnl'),
            'sort_dir' => strtolower((string)($get['sort_dir'] ?? '')) === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** 无显式范围时回退到近 30 天，并强制不超过 MAX_RANGE_DAYS。 */
    private function resolveRange(string $pickedStart, string $pickedEnd): array
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $end = $today->format('Y-m-d');
        $defaultDays = min(self::DEFAULT_RANGE_DAYS, self::MAX_RANGE_DAYS);
        $start = $today->modify('-' . ($defaultDays - 1) . ' days')->format('Y-m-d');

        if ($this->validDate($pickedStart)) {
            $start = $pickedStart;
        }
        if ($this->validDate($pickedEnd)) {
            $end = $pickedEnd;
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        $maxEnd = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))
            ->modify('+' . (self::MAX_RANGE_DAYS - 1) . ' days')->format('Y-m-d');
        if ($end > $maxEnd) {
            $end = $maxEnd;
        }
        return [$start, $end];
    }

    private function validDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function text(string $value, int $maxLength): string
    {
        $value = trim($value);
        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength) : $value;
    }

    private function db()
    {
        return Db::connect(self::CONNECTION);
    }

    private function definition(string $key): array
    {
        $directionFilter = $this->filter('direction', '盈亏方向', 'select', 'direction', [
            '' => '全部', 'profit' => '仅盈利', 'loss' => '仅亏损',
        ]);
        $definitions = [
            'user' => [
                'title' => '快盘用户盈亏报表',
                'notice' => '口径：客户 PNL = 卖出成交额 - 买入成交额 + COALESCE(赢家净份额,0)，正数为用户盈利；'
                    . '已自动排除做市商账号。默认按盈亏金额降序，时间为盘口结束时间（UTC）；可按最近成交时间 / 盈亏金额 / 成交笔数切换升序或降序。',
                'filters' => [
                    $this->filter('account_id', '账号ID'),
                    $this->filter('market_config_id', '市场内部ID'),
                    $this->filter('outcome_id', 'Outcome ID'),
                    $this->filter('sort_field', '排序字段', 'select', 'sort_field', ['last_trade_at' => '最近成交时间', 'pnl' => '盈亏金额', 'trade_count' => '成交笔数']),
                    $this->filter('sort_dir', '排序方向', 'select', 'sort_dir', ['desc' => '降序', 'asc' => '升序']),
                    $directionFilter,
                ],
                'columns' => [
                    ['field' => 'account_id', 'label' => '账号ID'],
                    ['field' => 'pnl', 'label' => '用户总盈亏', 'sign' => 'pnl'],
                    ['field' => 'win_lose_outcomes', 'label' => '用户盈/亏盘口数'],
                    ['field' => 'max_win_pnl', 'label' => '最大单盘口盈利', 'sign' => 'max_win_pnl'],
                    ['field' => 'max_win_outcome', 'label' => '最大盈利 Outcome'],
                    ['field' => 'max_loss_pnl', 'label' => '最大单盘口亏损', 'sign' => 'max_loss_pnl'],
                    ['field' => 'max_loss_outcome', 'label' => '最大亏损 Outcome'],
                    ['field' => 'trade_count', 'label' => '成交笔数'],
                    ['field' => 'trade_amount', 'label' => '交易总金额'],
                    ['field' => 'fee_amount', 'label' => '手续费（未计入PNL）'],
                    ['field' => 'first_trade_at', 'label' => '首次成交时间'],
                    ['field' => 'last_trade_at', 'label' => '最近成交时间'],
                    ['field' => 'detail', 'label' => '操作', 'detail' => true],
                ],
            ],
            'outcome' => [
                'title' => '快盘盘口盈亏报表',
                'notice' => '口径：盘口盈亏 = 公司快盘 PNL = -SUM(排除做市商账号后的客户 PNL)，正数为公司盈利，'
                    . '与数据洞察页一致，不含手续费；交易用户数同样不含做市商账号。默认隐藏交易笔数为 0 的盘口，'
                    . '默认按创建时间（盘口开始时间）降序；可按创建时间 / 盘口盈亏 / 交易笔数 / 交易用户数切换升序或降序。',
                'filters' => [
                    $this->filter('market_config_id', '市场内部ID'),
                    $this->filter('outcome_id', 'Outcome ID'),
                    $this->filter('sort_field', '排序字段', 'select', 'sort_field', ['pnl' => '盘口盈亏', 'trade_count' => '交易笔数', 'user_count' => '交易用户数', 'window_start_time' => '创建时间']),
                    $this->filter('sort_dir', '排序方向', 'select', 'sort_dir', ['desc' => '降序', 'asc' => '升序']),
                    $directionFilter,
                ],
                'columns' => [
                    ['field' => 'outcome_id', 'label' => 'Outcome ID'],
                    ['field' => 'market_id', 'label' => 'Market ID'],
                    ['field' => 'market_name', 'label' => '市场名称'],
                    ['field' => 'pnl', 'label' => '盘口盈亏', 'sign' => 'pnl', 'sort' => 'pnl'],
                    ['field' => 'trade_count', 'label' => '交易笔数', 'sort' => 'trade_count'],
                    ['field' => 'user_count', 'label' => '交易用户数', 'sort' => 'user_count'],
                    ['field' => 'fee_amount', 'label' => '手续费（未计入PNL）'],
                    ['field' => 'result', 'label' => '结算结果'],
                    ['field' => 'settlement_type', 'label' => '结算类型'],
                    ['field' => 'window_start_time', 'label' => '盘口开始时间'],
                    ['field' => 'end_time', 'label' => '盘口结束时间'],
                    ['field' => 'detail', 'label' => '操作', 'detail' => true],
                ],
            ],
        ];
        if (!isset($definitions[$key])) {
            throw new \InvalidArgumentException('Unknown fastbook pnl report');
        }
        return $definitions[$key];
    }

    private function filter(string $name, string $label, string $type = 'exact', ?string $field = null, array $options = []): array
    {
        return [
            'name' => $name,
            'field' => $field ?? $name,
            'label' => $label,
            'type' => $type,
            'options' => $options,
        ];
    }
}
