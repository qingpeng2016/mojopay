<?php
declare(strict_types=1);

namespace app\so_strategy\controller;

use app\so_strategy\service\FastbookAccounts;
use app\so_strategy\service\FastbookPnlQuery;
use app\so_strategy\service\FastbookTradeFields;
use think\admin\Controller;
use think\facade\Db;

/** 快盘采集数据只读后台。 */
class FastbookData extends Controller
{
    private const CONNECTION = 'db_fastbook';
    private const PAGE_SIZE = 20;

    /** @auth true @menu true */
    public function marketInfo() { return $this->renderDataset('market_info'); }

    /** @auth true @menu true */
    public function tradeRecords() { return $this->renderDataset('trade_records'); }

    /** @auth true @menu true */
    public function accountPnl() { return $this->renderDataset('account_pnl'); }

    /** @auth true @menu true */
    public function sysConfig() { return $this->renderDataset('sys_config'); }

    /**
     * 按列表当前筛选条件导出 CSV。
     * @auth true
     */
    public function export(): void
    {
        @set_time_limit(0);
        $key = (string)$this->request->get('dataset', '');
        $definition = $this->definition($key);
        $get = $this->request->get();
        unset($get['dataset'], $get['page'], $get['pageSize']);

        $query = $this->datasetQuery($definition);
        $this->applyFilters($query, $definition, $get);
        $this->applySelect($query, $definition);

        $filename = 'fastbook_' . $key . '_' . date('YmdHis') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, array_column($definition['columns'], 'label'), ',', '"', '');

        $offset = 0;
        $limit = 500;
        while (true) {
            $rows = (clone $query)->order($definition['order'])->limit($offset, $limit)->select()->toArray();
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $line = [];
                foreach ($definition['columns'] as $column) {
                    $field = $column['field'];
                    $line[] = $this->csvValue($row[$field] ?? '', $field);
                }
                fputcsv($fp, $line, ',', '"', '');
            }
            if (count($rows) < $limit) {
                break;
            }
            $offset += $limit;
        }
        fclose($fp);
        exit;
    }

    private function renderDataset(string $key)
    {
        $definition = $this->definition($key);
        $this->title = $definition['title'];
        $get = $this->request->get();
        unset($get['pageSize']);
        $pageSize = self::PAGE_SIZE;

        $query = $this->datasetQuery($definition);
        $this->applyFilters($query, $definition, $get);
        $this->applySelect($query, $definition);
        $result = $query->order($definition['order'])->paginate([
            'list_rows' => $pageSize,
            'page'      => max(1, (int)($get['page'] ?? 1)),
            'query'     => $get,
            // ThinkAdmin 页面运行在 /admin.html#/... 的 Hash 路由壳内。
            // 分页必须继续指向后台壳，否则浏览器会把局部模板当整页打开。
            'path'      => $this->paginationPath($key),
        ], false);

        foreach ($definition['filters'] as &$filter) {
            $filter['value'] = (string)($get[$filter['name']] ?? '');
        }
        unset($filter);
        $exportGet = $get;
        unset($exportGet['page']);
        $exportGet['dataset'] = $key;
        $this->assign([
            'dataset'   => $key,
            'columns'   => $definition['columns'],
            'filters'   => $definition['filters'],
            'list'      => $result->items(),
            'pagehtml'  => $result->render(),
            'page_size' => $pageSize,
            'get'       => $get,
            'export_query' => http_build_query($exportGet),
            'notice' => $definition['notice'] ?? '',
            'navigation_path' => $this->paginationPath($key),
        ]);
        return $this->fetch('fastbook_data/index');
    }

    private function paginationPath(string $key): string
    {
        $actions = [
            'market_info' => 'marketinfo',
            'trade_records' => 'traderecords',
            'account_pnl' => 'accountpnl',
            'sys_config' => 'sysconfig',
        ];
        if (!isset($actions[$key])) {
            throw new \InvalidArgumentException('Unknown fastbook pagination dataset');
        }
        return '/admin.html#/so_strategy/fastbook_data/' . $actions[$key] . '.html';
    }

    private function applyFilters($query, array $definition, array $get): void
    {
        if (!empty($definition['exclude_configured_accounts'])) {
            $excluded = FastbookAccounts::excludedIds();
            if ($excluded !== []) {
                $query->whereNotIn('account_id', $excluded);
            }
        }
        if (!empty($definition['fastbook_only'])) {
            $query->where('market_type', 'FASTBOOK')->where('vendor', 'FOREGATE');
        }
        foreach ($definition['filters'] as $filter) {
            $name = $filter['name'];
            $value = trim((string)($get[$name] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($filter['type'] === 'date' || $filter['type'] === 'date_raw') {
                $range = $this->dateRange($value);
                if ($range !== null) {
                    if ($filter['type'] === 'date_raw') {
                        $query->whereRaw($filter['field'] . ' BETWEEN ? AND ?', [
                            $range[0] . ' 00:00:00', $range[1] . ' 23:59:59.999999',
                        ]);
                    } else {
                        $query->whereBetween($filter['field'], [$range[0] . ' 00:00:00', $range[1] . ' 23:59:59.999999']);
                    }
                }
            } elseif ($filter['type'] === 'like') {
                $query->whereLike($filter['field'], '%' . $value . '%');
            } elseif ($filter['type'] === 'account') {
                $query->where(function ($q) use ($value) {
                    $q->where('t.buyer_account_id', $value)->whereOr('t.seller_account_id', $value);
                });
            } else {
                $query->where($filter['field'], $value);
            }
        }
    }

    private function datasetQuery(array $definition)
    {
        if (!empty($definition['pnl_source'])) {
            return FastbookPnlQuery::query();
        }
        if (!empty($definition['trade_enrichment'])) {
            return Db::connect(self::CONNECTION)->table('trade_records t')
                ->leftJoin('market_options mo', 'mo.id = t.market_option_id AND mo.market_config_id = t.market_config_id')
                ->leftJoin('market_results mr', 'mr.market_config_id = t.market_config_id AND mr.outcome_id = mo.outcome_id')
                ->leftJoin('market_config mc', 'mc.id = t.market_config_id')
                // 左关联 predictfun 价差告警表：以成交记录的 trade_id 关联，alerts 无数据时成交记录照常展示。
                ->leftJoin('predictfun_spread_alerts psa', 'psa.trade_id = t.trade_id');
        }
        if (!empty($definition['join'])) {
            $j = $definition['join'];
            $query = Db::connect(self::CONNECTION)->table($j['from'] . ' ' . $j['alias']);
            foreach ($j['joins'] as [$table, $condition, $type]) {
                $query->join($table, $condition, $type);
            }
            return $query;
        }
        return Db::connect(self::CONNECTION)->table($definition['table']);
    }

    private function applySelect($query, array $definition): void
    {
        if (!empty($definition['trade_enrichment'])) {
            $query->fieldRaw(FastbookTradeFields::select(FastbookAccounts::makerIds()));
            return;
        }
        if (!empty($definition['select'])) {
            $query->fieldRaw($definition['select']);
        }
    }

    private function dateRange(string $value): ?array
    {
        $value = str_replace(['+-+', '%2B-%2B'], ' - ', $value);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})\s+-\s+(\d{4}-\d{2}-\d{2})$/', trim($value), $m)) {
            return null;
        }
        return $m[1] <= $m[2] ? [$m[1], $m[2]] : null;
    }

    private function csvValue($value, string $field): string
    {
        if ($value === null) {
            return '';
        }
        $text = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);
        // 时间列：去掉 datetime(6) 的微秒，只保留到秒的完整时间，并以制表符前缀让 Excel 原样显示，
        // 否则 Excel 会重排成 2026/9/28 8:00 把秒吃掉。与用户盈亏报表（FastbookPnlReport）口径一致。
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)?$/', $text, $m)) {
            return "\t" . $m[1];
        }
        // 仅转义公式型文本；负数金额必须保持数值，便于导出后直接汇总。
        if (!is_numeric($text) && preg_match('/^[=+\-@]/', $text)) {
            $text = "'" . $text;
        }
        if ($field === 'id' || str_ends_with($field, '_id') || $field === 'trade_id') {
            return "\t" . $text;
        }
        return $text;
    }

    private function definition(string $key): array
    {
        $commonMarket = [
            $this->filter('market_config_id', '市场内部ID'),
            $this->filter('outcome_id', 'Outcome ID'),
        ];
        $definitions = [
            'market_info' => [
                'title' => '快盘市场信息', 'table' => 'market_options', 'order' => 'mo.id desc',
                // 以市场选项为主体，LEFT JOIN 市场配置与结算结果，三表关键信息同屏展示。
                'join' => [
                    'from' => 'market_options',
                    'alias' => 'mo',
                    'joins' => [
                        ['market_config mc', 'mc.id = mo.market_config_id', 'LEFT'],
                        ['market_results mr', 'mr.market_config_id = mo.market_config_id AND mr.outcome_id = mo.outcome_id', 'LEFT'],
                    ],
                ],
                'select' => 'mo.id AS id, mo.option_id AS option_id, mo.option_code AS option_code, mo.option_name AS option_name,'
                    . ' mo.status AS option_status, mo.market_config_id AS market_config_id, mo.outcome_id AS outcome_id,'
                    . ' mc.market_id AS market_id, mc.market_name AS market_name, mc.market_status AS market_status, mc.market_type AS market_type,'
                    . ' mr.result AS result, mr.settlement_type AS settlement_type, mr.window_start_time AS window_start_time, mr.window_end_time AS window_end_time,'
                    . ' mo.trading_start_at AS trading_start_at, mo.trading_end_at AS trading_end_at, mo.closed_at AS closed_at',
                'filters' => [
                    $this->filter('market_config_id', '市场内部ID', 'exact', 'mo.market_config_id'),
                    $this->filter('outcome_id', 'Outcome ID', 'exact', 'mo.outcome_id'),
                    $this->filter('mo_id', '选项内部ID', 'exact', 'mo.id'),
                    $this->filter('market_name', '市场名称', 'like', 'mc.market_name'),
                ],
                'columns' => $this->columns([
                    'id'=>'选项内部ID','option_id'=>'Option ID','option_code'=>'选项','option_name'=>'选项名称','option_status'=>'选项状态',
                    'market_config_id'=>'市场内部ID','outcome_id'=>'Outcome ID','market_id'=>'Market ID','market_name'=>'市场名称',
                    'market_status'=>'市场状态','market_type'=>'市场类型',
                    'result'=>'结算结果','settlement_type'=>'结算类型',
                    'window_start_time'=>'盘口开始时间','window_end_time'=>'盘口结束时间',
                    'trading_start_at'=>'选项交易开始','trading_end_at'=>'选项交易结束','closed_at'=>'选项关闭时间',
                ]),
            ],
            'trade_records' => [
                'title'=>'快盘成交记录','table'=>'trade_records','trade_enrichment'=>true,'order'=>'t.id desc',
                'filters'=>[$this->filter('market_config_id','市场内部ID','exact','t.market_config_id'),$this->filter('outcome_id','Outcome ID','exact','mo.outcome_id'),$this->filter('market_option_id','选项内部ID','exact','t.market_option_id'),$this->filter('trade_id','Trade ID','exact','t.trade_id'),$this->filter('account_id','账号ID','account'),$this->filter('trade_time','成交时间','date','t.trade_time')],
                'columns'=>$this->columns([
                    'id'=>'ID','market_config_id'=>'市场内部ID','outcome_id'=>'Outcome ID','window_start_time'=>'盘口开始时间','window_end_time'=>'盘口结束时间','market_name'=>'市场名称','option_code'=>'选项','market_option_id'=>'选项内部ID','trade_id'=>'Trade ID','predictfun_optimal_price'=>'predictfun最优价','buyer_account_id'=>'买方账号',
                    'seller_account_id'=>'卖方账号','maker_side'=>'（做市商）买卖方向','is_win'=>'is_win','trade_price'=>'成交价格','trade_quantity'=>'成交数量','trade_amount'=>'成交金额','buy_fee_amount'=>'买方手续费',
                    'sell_fee_amount'=>'卖方手续费','trade_time'=>'成交时间','source_updated_at'=>'上游更新时间','ingested_at'=>'采集时间','raw_payload'=>'原始报文',
                ]),
            ],
            'account_pnl' => [
                'title'=>'快盘用户账号 PNL','table'=>'','pnl_source'=>true,'order'=>'window_end_time desc, market_config_id desc, outcome_id desc, account_id desc',
                'exclude_configured_accounts'=>true,
                'fastbook_only'=>true,
                'select'=>'pnl.*,(-trading_cashflow + COALESCE(settlement_cashflow,0)) AS calculated_pnl,'
                    . 'COALESCE(window_start_time,(SELECT MIN(mo.trading_start_at) FROM market_options mo WHERE mo.market_config_id=pnl.market_config_id AND mo.outcome_id=pnl.outcome_id)) AS calculation_start_time,'
                    . 'COALESCE(window_end_time,(SELECT MAX(mo.trading_end_at) FROM market_options mo WHERE mo.market_config_id=pnl.market_config_id AND mo.outcome_id=pnl.outcome_id)) AS calculation_end_time',
                'notice'=>'口径：仅 Foregate 快盘已成交数据，不限制市场状态，自动排除 fastbook_pnl_excluded_account_ids 中的做市商账号；本页为客户原子明细，客户PNL=卖出成交额-买入成交额+COALESCE(赢家净份额,0)。数据洞察中的公司快盘PNL为客户PNL汇总取反；手续费单独展示且不计入 PNL。',
                'filters'=>array_merge($commonMarket, [$this->filter('account_id','账号ID'),$this->filter('result','结果'),$this->filter('settlement_type','结算类型'),$this->filter('pnl_status','PNL状态'),$this->filter('window_end_time','市场结束时间','date_raw','COALESCE(window_end_time,(SELECT MAX(mo.trading_end_at) FROM market_options mo WHERE mo.market_config_id=pnl.market_config_id AND mo.outcome_id=pnl.outcome_id))')]),
                'columns'=>$this->columns([
                    'market_config_id'=>'市场内部ID','market_id'=>'Market ID','market_name'=>'市场名称','outcome_id'=>'Outcome ID','account_id'=>'账号ID',
                    'buy_trade_amount'=>'买入金额','sell_trade_amount'=>'卖出金额','trade_fee_amount'=>'手续费（未计入PNL）','trading_cashflow'=>'净交易成本（买入-卖出）',
                    'net_position_quantity'=>'净持仓总份额','settlement_cashflow'=>'赢家净份额','calculated_pnl'=>'客户PNL','result'=>'结果','settlement_type'=>'结算类型','pnl_status'=>'上游PNL状态',
                    'calculation_start_time'=>'市场开始时间','calculation_end_time'=>'市场结束时间','market_type'=>'市场类型','vendor'=>'数据源',
                ]),
            ],
            'sys_config' => [
                'title'=>'快盘运行配置','table'=>'sys_config','order'=>'config_id desc',
                'filters'=>[$this->filter('config_type','配置类型'),$this->filter('config_key','配置键','like')],
                'columns'=>$this->columns(['config_id'=>'配置ID','config_type'=>'配置类型','config_name'=>'配置名称','config_key'=>'配置键','config_value'=>'配置值','remark'=>'备注','created_at'=>'创建时间','updated_at'=>'更新时间']),
            ],
        ];
        if (!isset($definitions[$key])) {
            throw new \InvalidArgumentException('Unknown fastbook dataset');
        }
        return $definitions[$key];
    }

    private function filter(string $name, string $label, string $type = 'exact', ?string $field = null): array
    {
        return ['name'=>$name, 'field'=>$field ?? $name, 'label'=>$label, 'type'=>$type];
    }

    /**
     * 列定义：值为字符串时仅指定表头；值为 [表头, 最小列宽(px)] 时放宽该列并解除单元格宽度上限。
     */
    private function columns(array $columns): array
    {
        $out = [];
        foreach ($columns as $field => $label) {
            if (is_array($label)) {
                $out[] = ['field'=>$field, 'label'=>(string)$label[0], 'width'=>(int)($label[1] ?? 0)];
            } else {
                $out[] = ['field'=>$field, 'label'=>$label];
            }
        }
        return $out;
    }
}
