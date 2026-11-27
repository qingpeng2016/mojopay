<?php

declare (strict_types=1);

namespace app\bitfinex\service;

use think\facade\Db;

/**
 * 客户周账单：指标聚合 + PDF 生成/读取
 */
final class WeeklyStatementService
{
    private const TZ_UTC = 'UTC';

    /** @var array<string, string> */
    private const FLOATING_RATE_BANDS = [
        'FLEXIBLE' => '5.0%-6.0%',
        'D7'       => '5.5%-7.0%',
        'D14'      => '6.0%-8.5%',
        'D30'      => '7.0%-9.5%',
        'D60'      => '8.0%-10.5%',
        'D90'      => '8.5%-11.0%',
        'D120'     => '8.5%-11.0%',
    ];

    /**
     * 账单周：按 UTC 自然周（周一至周日）解析；空输入不默认本周
     *
     * @return array{0:string,1:string}
     */
    public function resolveWeek(string $weekStartInput = ''): array
    {
        $normalized = $this->normalizeDateInput($weekStartInput);
        if ($normalized === '') {
            return ['', ''];
        }

        $dt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $normalized,
            new \DateTimeZone(self::TZ_UTC)
        );
        if (!($dt instanceof \DateTimeImmutable)) {
            return ['', ''];
        }

        return $this->utcWeekRange($dt);
    }

    /**
     * 当前 UTC 自然周（周一至周日）
     *
     * @return array{0:string,1:string}
     */
    public function currentUtcWeek(): array
    {
        return $this->utcWeekRange($this->utcNow());
    }

    /**
     * @return array{0:string,1:string}
     */
    private function utcWeekRange(\DateTimeImmutable $day): array
    {
        $day = $day->setTimezone(new \DateTimeZone(self::TZ_UTC));
        $dow = (int)$day->format('N'); // 1=Mon ... 7=Sun
        $weekStart = $day->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');
        $weekEnd   = $day->modify('+' . (7 - $dow) . ' days')->format('Y-m-d');

        return [$weekStart, $weekEnd];
    }

    private function normalizeDateInput(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }
        // 兼容 2026/07/25、2026.07.25
        $value = str_replace(['/', '.'], '-', $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        return $value;
    }

    private function utcNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::TZ_UTC));
    }

    private function utcNowFormat(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->utcNow()->format($format);
    }

    /**
     * 将库中无时区 datetime 按 UTC 解释后格式化
     */
    private function formatUtcDatetime(?string $datetime, string $format = 'Y-m-d H:i:s'): string
    {
        if ($datetime === null || $datetime === '') {
            return '-';
        }

        try {
            $dt = new \DateTimeImmutable($datetime, new \DateTimeZone(self::TZ_UTC));
        } catch (\Exception) {
            $ts = strtotime($datetime);
            if ($ts === false) {
                return '-';
            }
            $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(self::TZ_UTC));
        }

        return $dt->format($format);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildListRows(string $weekStart, string $weekEnd, array $filters = []): array
    {
        if ($weekStart === '' || $weekEnd === '') {
            return $this->buildAllListRows($filters);
        }

        return $this->buildWeekPairRows($weekStart, $weekEnd, $filters);
    }

    /**
     * 进入列表页时：若当前 UTC 周尚无周账单行，则按客户×类型补写占位记录（无 PDF）
     *
     * @return int 新写入条数
     */
    public function ensureCurrentWeekStubs(): int
    {
        [$weekStart, $weekEnd] = $this->currentUtcWeek();
        $pairs = $this->listClientStatementPairs([]);
        if ($pairs === []) {
            return 0;
        }

        $db = Db::connect('db_bitfinex');
        $existing = $db->name('fund_client_weekly_statement')
            ->where('week_start', $weekStart)
            ->where('week_end', $weekEnd)
            ->field('client_id, statement_type')
            ->select()
            ->toArray();

        $existingKeys = [];
        foreach ($existing as $row) {
            $existingKeys[$this->pairKey((int)$row['client_id'], (string)$row['statement_type'])] = true;
        }

        $inserted = 0;
        foreach ($pairs as $pair) {
            $clientId      = (int)($pair['client_id'] ?? 0);
            $statementType = (string)($pair['statement_type'] ?? '');
            if ($clientId <= 0 || !in_array($statementType, ['FLOATING', 'GUARANTEED'], true)) {
                continue;
            }
            $key = $this->pairKey($clientId, $statementType);
            if (isset($existingKeys[$key])) {
                continue;
            }

            $clientName = (string)($pair['client_name'] ?? '');
            $metrics   = $this->buildMetrics($clientId, $statementType, $weekStart, $weekEnd);
            $metrics['client_name']          = $clientName;
            $metrics['account_no']           = (string)$clientId;
            $metrics['week_start']           = $weekStart;
            $metrics['week_end']             = $weekEnd;
            $metrics['week_range_fmt']       = $weekStart . ' ~ ' . $weekEnd;
            $metrics['statement_type']       = $statementType;
            $metrics['statement_type_label'] = $statementType === 'FLOATING' ? '进取浮动型' : '稳健保底型';

            $db->name('fund_client_weekly_statement')->insert([
                'client_id'          => $clientId,
                'week_start'         => $weekStart,
                'week_end'           => $weekEnd,
                'statement_type'     => $statementType,
                'total_invest'       => $metrics['closing_principal'] ?? 0,
                'total_profit'       => $metrics['period_net_profit'] ?? 0,
                'closing_principal'  => $metrics['closing_principal'] ?? 0,
                'metrics_json'       => json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'pdf_filename'       => '',
                'pdf_content'        => null,
                'pdf_size'           => 0,
                'generated_at'       => null,
                'generated_by'       => '',
            ]);
            $existingKeys[$key] = true;
            $inserted++;
        }

        return $inserted;
    }

    /**
     * 未指定账单周：列出 fund_client_weekly_statement 全部记录
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildAllListRows(array $filters = []): array
    {
        $db = Db::connect('db_bitfinex');

        $query = $db->name('fund_client_weekly_statement')
            ->alias('w')
            ->leftJoin('fund_client c', 'c.id = w.client_id')
            ->field('w.*, c.name AS client_name')
            ->order('w.week_start', 'desc')
            ->order('c.name', 'asc')
            ->order('w.statement_type', 'asc');

        if (!empty($filters['client_id'])) {
            $clientId = (int)$filters['client_id'];
            if ($clientId > 0) {
                $query->where('w.client_id', $clientId);
            }
        }

        if (!empty($filters['statement_type']) && in_array($filters['statement_type'], ['FLOATING', 'GUARANTEED'], true)) {
            $query->where('w.statement_type', $filters['statement_type']);
        }

        if (!empty($filters['client_name'])) {
            $query->whereLike('c.name', '%' . $filters['client_name'] . '%');
        }

        $rows = [];
        foreach ($query->select()->toArray() as $saved) {
            $rows[] = $this->formatSavedListRow($saved);
        }

        return $rows;
    }

    /**
     * @return list<array{client_id:int|string,statement_type:string,client_name?:string}>
     */
    private function listClientStatementPairs(array $filters = []): array
    {
        $db = Db::connect('db_bitfinex');

        $pairsQuery = $db->name('fund_client_product_subscription')
            ->alias('s')
            ->leftJoin('fund_products p', 'p.id = s.product_id')
            ->leftJoin('fund_client c', 'c.id = s.client_id')
            ->whereIn('p.product_type', ['FLOATING', 'GUARANTEED'])
            ->group('s.client_id, p.product_type, c.name')
            ->field('s.client_id, p.product_type AS statement_type, c.name AS client_name');

        if (!empty($filters['client_id'])) {
            $clientId = (int)$filters['client_id'];
            if ($clientId > 0) {
                $pairsQuery->where('s.client_id', $clientId);
            }
        }

        if (!empty($filters['statement_type']) && in_array($filters['statement_type'], ['FLOATING', 'GUARANTEED'], true)) {
            $pairsQuery->where('p.product_type', $filters['statement_type']);
        }

        if (!empty($filters['client_name'])) {
            $pairsQuery->whereLike('c.name', '%' . $filters['client_name'] . '%');
        }

        return $pairsQuery->select()->toArray();
    }

    /**
     * 指定周：按客户×产品类型展开，未生成 PDF 也可点「生成报表」
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildWeekPairRows(string $weekStart, string $weekEnd, array $filters = []): array
    {
        $db = Db::connect('db_bitfinex');

        $pairs = $this->listClientStatementPairs($filters);
        if ($pairs === []) {
            return [];
        }

        $savedMap = [];
        $savedRows = $db->name('fund_client_weekly_statement')
            ->where('week_start', $weekStart)
            ->where('week_end', $weekEnd)
            ->select()
            ->toArray();
        foreach ($savedRows as $saved) {
            $key = $this->pairKey((int)$saved['client_id'], (string)$saved['statement_type']);
            $savedMap[$key] = $saved;
        }

        $rows = [];
        foreach ($pairs as $pair) {
            $clientId      = (int)$pair['client_id'];
            $statementType = (string)$pair['statement_type'];
            $key           = $this->pairKey($clientId, $statementType);
            $saved         = $savedMap[$key] ?? null;

            if ($saved !== null && !empty($saved['metrics_json'])) {
                $metrics = json_decode((string)$saved['metrics_json'], true);
                if (!is_array($metrics)) {
                    $metrics = $this->buildMetrics($clientId, $statementType, $weekStart, $weekEnd);
                }
            } else {
                $metrics = $this->buildMetrics($clientId, $statementType, $weekStart, $weekEnd);
            }

            $hasPdf = (int)($saved['pdf_size'] ?? 0) > 0;

            $rows[] = [
                'id'                   => (int)($saved['id'] ?? 0),
                'client_id'            => $clientId,
                'client_name'          => (string)($pair['client_name'] ?? ''),
                'week_start'           => $weekStart,
                'week_end'             => $weekEnd,
                'week_range_fmt'       => $weekStart . ' ~ ' . $weekEnd,
                'statement_type'       => $statementType,
                'statement_type_label' => $statementType === 'FLOATING' ? '进取浮动型' : '稳健保底型',
                'total_invest_fmt'     => $this->fmtMoney($metrics['closing_principal'] ?? 0),
                'total_profit_fmt'     => $this->fmtMoney($metrics['period_net_profit'] ?? 0),
                'has_pdf'              => $hasPdf,
                'generated_at'         => !empty($saved['generated_at'])
                    ? $this->formatUtcDatetime((string)$saved['generated_at']) : '-',
                'generated_by'         => (string)($saved['generated_by'] ?? ''),
            ];
        }

        usort($rows, static function ($a, $b) {
            $cmp = strcmp((string)$a['client_name'], (string)$b['client_name']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)$a['statement_type'], (string)$b['statement_type']);
        });

        return $rows;
    }

    /**
     * @param array<string, mixed> $saved
     *
     * @return array<string, mixed>
     */
    private function formatSavedListRow(array $saved): array
    {
        $weekStart     = (string)$saved['week_start'];
        $weekEnd       = (string)$saved['week_end'];
        $statementType = (string)$saved['statement_type'];
        $metrics       = [];

        if (!empty($saved['metrics_json'])) {
            $decoded = json_decode((string)$saved['metrics_json'], true);
            if (is_array($decoded)) {
                $metrics = $decoded;
            }
        }

        $closingPrincipal = $metrics['closing_principal'] ?? $saved['total_invest'] ?? 0;
        $periodNetProfit  = $metrics['period_net_profit'] ?? $saved['total_profit'] ?? 0;

        return [
            'id'                   => (int)($saved['id'] ?? 0),
            'client_id'            => (int)$saved['client_id'],
            'client_name'          => (string)($saved['client_name'] ?? ''),
            'week_start'           => $weekStart,
            'week_end'             => $weekEnd,
            'week_range_fmt'       => $weekStart . ' ~ ' . $weekEnd,
            'statement_type'       => $statementType,
            'statement_type_label' => $statementType === 'FLOATING' ? '进取浮动型' : '稳健保底型',
            'total_invest_fmt'     => $this->fmtMoney($closingPrincipal),
            'total_profit_fmt'     => $this->fmtMoney($periodNetProfit),
            'has_pdf'              => (int)($saved['pdf_size'] ?? 0) > 0,
            'generated_at'         => !empty($saved['generated_at'])
                ? $this->formatUtcDatetime((string)$saved['generated_at']) : '-',
            'generated_by'         => (string)($saved['generated_by'] ?? ''),
        ];
    }

    /**
     * 上一完整 UTC 自然周（周一至周日）
     *
     * @return array{0:string,1:string}
     */
    public function previousUtcWeek(): array
    {
        $day = $this->utcNow()->modify('-7 days');

        return $this->utcWeekRange($day);
    }

    /**
     * 指定周账单 PDF 是否已生成（pdf_size > 0）
     */
    public function hasGeneratedPdf(int $clientId, string $statementType, string $weekStart): bool
    {
        $row = Db::connect('db_bitfinex')
            ->name('fund_client_weekly_statement')
            ->where('client_id', $clientId)
            ->where('week_start', $weekStart)
            ->where('statement_type', $statementType)
            ->field('pdf_size, generated_at')
            ->find();

        return !empty($row) && (int)($row['pdf_size'] ?? 0) > 0;
    }

    /**
     * 批量为上一 UTC 周补生成缺失的 PDF（已生成则跳过）
     *
     * @param array{force?:bool,client_id?:int,statement_type?:string} $options
     * @return array{
     *   week_start:string,
     *   week_end:string,
     *   skipped_all:bool,
     *   message:string,
     *   generated:int,
     *   skipped:int,
     *   failed:int,
     *   details:list<string>
     * }
     */
    public function generatePreviousWeekPdfs(string $operator = 'cron', array $options = []): array
    {
        [$weekStart, $weekEnd] = $this->previousUtcWeek();
        $force = !empty($options['force']);
        $filters = [];
        if (!empty($options['client_id'])) {
            $filters['client_id'] = (int)$options['client_id'];
        }
        if (!empty($options['statement_type'])) {
            $filters['statement_type'] = (string)$options['statement_type'];
        }

        $pairs = $this->listClientStatementPairs($filters);
        $out = [
            'week_start'  => $weekStart,
            'week_end'    => $weekEnd,
            'skipped_all' => false,
            'message'     => '',
            'generated'   => 0,
            'skipped'     => 0,
            'failed'      => 0,
            'details'     => [],
        ];

        if ($pairs === []) {
            $out['skipped_all'] = true;
            $out['message'] = sprintf(
                '上一周 %s ~ %s 无客户×账单类型可生成，跳过',
                $weekStart,
                $weekEnd
            );

            return $out;
        }

        $pending = [];
        foreach ($pairs as $pair) {
            $clientId      = (int)($pair['client_id'] ?? 0);
            $statementType = (string)($pair['statement_type'] ?? '');
            if ($clientId <= 0 || !in_array($statementType, ['FLOATING', 'GUARANTEED'], true)) {
                continue;
            }
            if (!$force && $this->hasGeneratedPdf($clientId, $statementType, $weekStart)) {
                $out['skipped']++;
                continue;
            }
            $pending[] = [
                'client_id'      => $clientId,
                'statement_type' => $statementType,
                'client_name'    => (string)($pair['client_name'] ?? ''),
            ];
        }

        if ($pending === []) {
            $out['skipped_all'] = true;
            $out['message'] = sprintf(
                '上一周 %s ~ %s 的 PDF 均已生成（共 %d 份），无需重复执行',
                $weekStart,
                $weekEnd,
                $out['skipped']
            );

            return $out;
        }

        foreach ($pending as $item) {
            $label = sprintf(
                'client_id=%d (%s) %s',
                $item['client_id'],
                $item['client_name'] !== '' ? $item['client_name'] : '-',
                $item['statement_type']
            );
            $result = $this->generate(
                $item['client_id'],
                $item['statement_type'],
                $weekStart,
                $operator
            );
            if (!empty($result['ok'])) {
                $out['generated']++;
                $out['details'][] = 'OK ' . $label;
            } else {
                $out['failed']++;
                $out['details'][] = 'FAIL ' . $label . ': ' . (string)($result['message'] ?? 'unknown');
            }
        }

        $out['message'] = sprintf(
            '上一周 %s ~ %s：新生成 %d，跳过已生成 %d，失败 %d',
            $weekStart,
            $weekEnd,
            $out['generated'],
            $out['skipped'],
            $out['failed']
        );

        return $out;
    }

    /**
     * 生成 PDF 报表
     *
     * @return array{ok:bool,message:string,id?:int}
     */
    public function generate(int $clientId, string $statementType, string $weekStart, string $operator): array
    {
        if (!in_array($statementType, ['FLOATING', 'GUARANTEED'], true)) {
            return ['ok' => false, 'message' => '账单类型无效'];
        }

        [, $weekEnd] = $this->resolveWeek($weekStart);
        $client = Db::connect('db_bitfinex')->name('fund_client')->where('id', $clientId)->find();
        if (empty($client)) {
            return ['ok' => false, 'message' => '客户不存在'];
        }

        $metrics = $this->buildMetrics($clientId, $statementType, $weekStart, $weekEnd);
        $metrics['client_name']          = (string)($client['name'] ?? '');
        $metrics['account_no']           = (string)$clientId;
        $metrics['week_start']           = $weekStart;
        $metrics['week_end']             = $weekEnd;
        $metrics['week_range_fmt']       = $weekStart . ' ~ ' . $weekEnd;
        $metrics['statement_type']       = $statementType;
        $metrics['statement_type_label'] = $statementType === 'FLOATING' ? '进取浮动型' : '稳健保底型';
        $metrics['generated_at_fmt']     = $this->utcNowFormat();

        try {
            $pdf = $this->renderPdf($metrics);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'PDF 生成失败：' . $e->getMessage()];
        }

        $db = Db::connect('db_bitfinex');
        $payload = [
            'client_id'          => $clientId,
            'week_start'         => $weekStart,
            'week_end'           => $weekEnd,
            'statement_type'     => $statementType,
            'total_invest'       => $metrics['closing_principal'],
            'total_profit'       => $metrics['period_net_profit'],
            'closing_principal'  => $metrics['closing_principal'],
            'metrics_json'       => json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'pdf_filename'       => $pdf['filename'],
            'pdf_content'        => $pdf['content'],
            'pdf_size'           => strlen($pdf['content']),
            'generated_at'       => $this->utcNowFormat(),
            'generated_by'       => $operator,
        ];

        $existing = $db->name('fund_client_weekly_statement')
            ->where('client_id', $clientId)
            ->where('week_start', $weekStart)
            ->where('statement_type', $statementType)
            ->find();

        if (!empty($existing)) {
            $db->name('fund_client_weekly_statement')->where('id', (int)$existing['id'])->update($payload);
            $id = (int)$existing['id'];
        } else {
            $id = (int)$db->name('fund_client_weekly_statement')->insertGetId($payload);
        }

        return ['ok' => true, 'message' => '报表已生成', 'id' => $id];
    }

    /**
     * @return array{ok:bool,message:string,filename?:string,content?:string}
     */
    public function resolveDownload(int $id): array
    {
        $row = Db::connect('db_bitfinex')->name('fund_client_weekly_statement')->where('id', $id)->find();
        if (empty($row)) {
            return ['ok' => false, 'message' => '记录不存在'];
        }

        $filename = (string)($row['pdf_filename'] ?? '');
        $pdfSize  = (int)($row['pdf_size'] ?? 0);
        $content  = $row['pdf_content'] ?? null;
        if (is_resource($content)) {
            $content = stream_get_contents($content) ?: '';
        }
        if (!is_string($content) || $content === '' || $pdfSize <= 0) {
            return ['ok' => false, 'message' => '尚未生成报表'];
        }

        return [
            'ok'       => true,
            'message'  => 'ok',
            'filename' => $filename !== '' ? $filename : 'weekly_statement.pdf',
            'content'  => $content,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildMetrics(int $clientId, string $statementType, string $weekStart, string $weekEnd): array
    {
        $db = Db::connect('db_bitfinex');

        // 期初本金：有上一周账单则取上一周 closing_principal；否则按当前持仓计算
        $openingPrincipal = $this->resolveOpeningPrincipal($clientId, $statementType, $weekStart);

        $subscribeInWeekFixed = (float)($this->subscriptionSumQuery($clientId, $statementType)
            ->where('p.term', '<>', 'FLEXIBLE')
            ->whereBetween('s.subscribe_date', [$weekStart, $weekEnd])
            ->where('s.status', 'ACTIVE')
            ->sum('s.subscribe_amount') ?: 0);
        $subscribeInWeekFlexible = (float)(Db::connect('db_bitfinex')
            ->name('fund_client_product_subscription_flexibel')
            ->alias('s')
            ->leftJoin('fund_products p', 'p.id = s.product_id')
            ->where('s.client_id', $clientId)
            ->where('p.product_type', $statementType)
            ->whereBetween('s.subscribe_date', [$weekStart, $weekEnd])
            ->where('s.status', 'ACTIVE')
            ->sum('s.subscribe_amount') ?: 0);
        $subscribeInWeek = $subscribeInWeekFixed + $subscribeInWeekFlexible;

        $closingPrincipal = (float)($this->subscriptionSumQuery($clientId, $statementType)
            ->where('s.status', 'ACTIVE')
            ->sum('s.subscribe_amount') ?: 0);

        $redeemInWeek = (float)$db->name('fund_client_product_redemption')
            ->alias('r')
            ->leftJoin('fund_products p', 'p.id = r.product_id')
            ->where('r.client_id', $clientId)
            ->where('p.product_type', $statementType)
            ->where('r.status', 'paid')
            ->whereNotNull('r.settled_at')
            ->whereBetween('r.settled_at', ["{$weekStart} 00:00:00", "{$weekEnd} 23:59:59"])
            ->sum('r.redemption_amount');

        $closingHoldingNetProfit = $this->calcClosingHoldingNetProfit($clientId, $statementType, $weekEnd);

        $holdings = $this->buildHoldings($clientId, $statementType, $weekStart, $weekEnd);
        $records  = $this->buildActivityRecords($clientId, $statementType, $weekStart, $weekEnd);
        $rollovers = $this->buildRolloverRecords($clientId, $statementType, $weekStart, $weekEnd);
        $redemptions = $this->buildRedemptionRecords($clientId, $statementType, $weekStart, $weekEnd);

        // 账户总览「本期净收益」= 期末持仓明细各行展示值（两位小数）之和，避免与肉眼累加差 0.01/0.02
        $periodGross = 0.0;
        foreach ($holdings as $holding) {
            $periodGross += round((float)($holding['period_profit'] ?? 0), 2);
        }
        // 「累计净收益」= 该客户 fund_client_subscription_accrual_daily 历史利息合计（含已赎回申购，截止 week_end）
        $cumulativeProfit = $this->clientHistoricalAccrualInterestSum($clientId, $weekEnd);
        $mgmtFee  = 0.0;
        $earlyFee = 0.0;
        $otherFee = 0.0;
        $periodNet = $periodGross - $mgmtFee - $earlyFee - $otherFee;

        return [
            'opening_principal'     => $openingPrincipal,
            'subscribe_in_week'     => $subscribeInWeek,
            'redeem_in_week'        => $redeemInWeek,
            'closing_principal'     => $closingPrincipal,
            'period_gross_profit'   => $periodGross,
            'mgmt_fee'              => $mgmtFee,
            'early_redeem_fee'      => $earlyFee,
            'other_fee'             => $otherFee,
            'period_net_profit'          => $periodNet,
            'closing_holding_net_profit' => $closingHoldingNetProfit,
            'cumulative_net_profit'    => $cumulativeProfit,
            'closing_total_return'  => $closingPrincipal + $closingHoldingNetProfit,
            'holdings'              => $holdings,
            'activity_records'      => $records,
            'rollover_records'      => $rollovers,
            'redemption_records'    => $redemptions,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildHoldings(int $clientId, string $statementType, string $weekStart, string $weekEnd): array
    {
        $activeRows = Db::connect('db_bitfinex')
            ->name('fund_client_product_subscription')
            ->alias('s')
            ->leftJoin('fund_products p', 'p.id = s.product_id')
            ->where('s.client_id', $clientId)
            ->where('p.product_type', $statementType)
            ->where('s.status', 'ACTIVE')
            ->field('s.id AS subscription_id, p.term, p.product_name, s.product_id, s.subscribe_amount, s.floating_shares, s.end_interest_date, s.redeemable_amount, s.compound_date, s.subscribe_date, s.guaranteed_rate_snapshot')
            ->order('s.id', 'asc')
            ->select()
            ->toArray();

        $weekFullRedeemedRows = $this->fetchWeekFullRedeemedHoldingRows(
            $clientId,
            $statementType,
            $weekStart,
            $weekEnd
        );

        $rows = array_merge($activeRows, $weekFullRedeemedRows);

        $subscriptionIds = [];
        foreach ($rows as $row) {
            $sid = (int)($row['subscription_id'] ?? 0);
            if ($sid > 0) {
                $subscriptionIds[$sid] = $sid;
            }
        }
        $periodBySub = $this->subscriptionAccrualSums(
            $clientId,
            array_values($subscriptionIds),
            $weekStart,
            $weekEnd
        );
        $cumulativeBySub = $this->subscriptionAccrualSums(
            $clientId,
            array_values($subscriptionIds),
            null,
            $weekEnd
        );

        $productIdsForRates = [];
        foreach ($rows as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            if ($pid > 0) {
                $productIdsForRates[$pid] = $pid;
            }
        }
        $clientRates = [];
        if ($statementType === 'GUARANTEED' && $productIdsForRates !== []) {
            $clientRates = Db::connect('db_bitfinex')
                ->name('fund_client_guaranteed_rate')
                ->where('client_id', $clientId)
                ->whereIn('product_id', array_values($productIdsForRates))
                ->column('guaranteed_rate,bonus', 'product_id');
        }

        $termMap = [];
        foreach ($rows as $row) {
            $term = (string)($row['term'] ?? 'FLEXIBLE');
            $subscriptionId = (int)($row['subscription_id'] ?? 0);
            if (!isset($termMap[$term])) {
                $termMap[$term] = [
                    'term'                => $this->termLabel($term),
                    'principal'           => 0.0,
                    'period_profit'       => 0.0,
                    'cumulative_profit'   => 0.0,
                    'maturity'            => '-',
                    'principal_lines'     => [],
                    'maturity_lines'      => [],
                    'period_profit_lines' => [],
                    'cumulative_profit_lines' => [],
                    'rate_lines'          => [],
                    'bonus_lines'         => [],
                    'rate_display'        => $statementType === 'FLOATING'
                        ? (self::FLOATING_RATE_BANDS[$term] ?? '-')
                        : '-',
                    'bonus_display'       => '-',
                ];
            }
            $weekFullRedeemed = !empty($row['week_full_redeemed']);
            $amount = $weekFullRedeemed
                ? $this->resolveFullRedemptionPrincipalAmount($row)
                : (float)($row['subscribe_amount'] ?? 0);
            $termMap[$term]['principal'] += $amount;
            $termMap[$term]['principal_lines'][] = $this->fmtMoney($amount);
            $maturity = (string)($row['end_interest_date'] ?? '');
            $termMap[$term]['maturity_lines'][] = $weekFullRedeemed ? '-' : ($maturity !== '' ? $maturity : '-');
            if ($maturity !== '') {
                $termMap[$term]['maturity'] = $maturity;
            }

            if ($statementType === 'GUARANTEED') {
                $rate = $this->resolveGuaranteedRateForHoldingRow($row, $clientRates);
                $bonus = $this->resolveGuaranteedBonusForHoldingRow($row, $clientRates);
                $termMap[$term]['rate_lines'][]  = $this->formatAnnualRate($rate);
                $termMap[$term]['bonus_lines'][] = $this->formatAnnualRate($bonus);
            }

            if ($term === 'FLEXIBLE') {
                $holdingStart = $this->resolveFlexibleHoldingInterestStartDate($row);
                $periodStart    = $weekStart;
                if ($holdingStart !== '' && $holdingStart > $periodStart) {
                    $periodStart = $holdingStart;
                }
                $periodEnd = $this->resolveHoldingPeriodEndDate($row, $weekEnd);
                $periodProfit = $this->sumSubscriptionAccrualInRange(
                    $clientId,
                    $subscriptionId,
                    $periodStart,
                    $periodEnd
                );
                $cumulativeEnd = $weekFullRedeemed ? $periodEnd : $weekEnd;
                $cumulativeProfit = $this->sumSubscriptionAccrualInRange(
                    $clientId,
                    $subscriptionId,
                    $holdingStart,
                    $cumulativeEnd
                );
            } else {
                $periodEnd = $this->resolveHoldingPeriodEndDate($row, $weekEnd);
                if ($weekFullRedeemed) {
                    $periodProfit = $this->subscriptionAccrualSums(
                        $clientId,
                        [$subscriptionId],
                        $weekStart,
                        $periodEnd
                    )[$subscriptionId] ?? 0.0;
                    $cumulativeProfit = $this->subscriptionAccrualSums(
                        $clientId,
                        [$subscriptionId],
                        null,
                        $periodEnd
                    )[$subscriptionId] ?? 0.0;
                } else {
                    $periodProfit     = (float)($periodBySub[$subscriptionId] ?? 0);
                    $cumulativeProfit = (float)($cumulativeBySub[$subscriptionId] ?? 0);
                }
            }
            $termMap[$term]['period_profit'] += $periodProfit;
            $termMap[$term]['cumulative_profit'] += $cumulativeProfit;
            $termMap[$term]['period_profit_lines'][] = $this->fmtMoney($periodProfit);
            $termMap[$term]['cumulative_profit_lines'][] = $this->fmtMoney($cumulativeProfit);
        }

        $ordered = [];
        foreach (['FLEXIBLE', 'D7', 'D14', 'D30', 'D60', 'D90', 'D120'] as $term) {
            if (isset($termMap[$term])) {
                $item = $termMap[$term];
                if ($statementType === 'GUARANTEED') {
                    $item['rate_display']  = $this->joinHtmlLines($item['rate_lines'] ?? []);
                    $item['bonus_display'] = $this->joinHtmlLines($item['bonus_lines'] ?? []);
                }
                $item['principal_fmt']             = $this->fmtMoney($item['principal']);
                $item['principal_display']         = $this->joinHtmlLines($item['principal_lines'] ?? []);
                $item['maturity_display']          = $this->joinHtmlLines($item['maturity_lines'] ?? []);
                $item['period_profit_fmt']         = $this->fmtMoney($item['period_profit']);
                $item['cumulative_profit_fmt']     = $this->fmtMoney($item['cumulative_profit']);
                $item['period_profit_display']     = $this->joinHtmlLines($item['period_profit_lines'] ?? [], '0.00');
                $item['cumulative_profit_display'] = $this->joinHtmlLines($item['cumulative_profit_lines'] ?? [], '0.00');
                unset(
                    $item['principal_lines'],
                    $item['maturity_lines'],
                    $item['period_profit_lines'],
                    $item['cumulative_profit_lines'],
                    $item['rate_lines'],
                    $item['bonus_lines']
                );
                $ordered[] = $item;
            }
        }

        return $ordered;
    }

    /**
     * 本期已全部赎回、不再 ACTIVE 的申购：仍计入期末持仓明细（本金取本期全赎记录）
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchWeekFullRedeemedHoldingRows(
        int $clientId,
        string $statementType,
        string $weekStart,
        string $weekEnd
    ): array {
        $redemptions = Db::connect('db_bitfinex')
            ->name('fund_client_product_redemption')
            ->alias('r')
            ->leftJoin('fund_products p', 'p.id = r.product_id')
            ->where('r.client_id', $clientId)
            ->where('p.product_type', $statementType)
            ->where('r.status', 'paid')
            ->where('r.redemption_type', 'FULL')
            ->whereNotNull('r.settled_at')
            ->whereBetween('r.settled_at', ["{$weekStart} 00:00:00", "{$weekEnd} 23:59:59"])
            ->field('r.subscription_id, r.settled_at, r.redemption_amount, r.guaranteed_annual_rate, r.guaranteed_holding_days')
            ->select()
            ->toArray();

        if ($redemptions === []) {
            return [];
        }

        $redemptionMetaBySub = [];
        $subscriptionIds = [];
        foreach ($redemptions as $redemption) {
            $sid = (int)($redemption['subscription_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $subscriptionIds[$sid] = $sid;
            $redemptionMetaBySub[$sid] = [
                'settled_date'            => $this->formatUtcDatetime((string)($redemption['settled_at'] ?? ''), 'Y-m-d'),
                'redemption_amount'       => (float)($redemption['redemption_amount'] ?? 0),
                'guaranteed_annual_rate'  => (float)($redemption['guaranteed_annual_rate'] ?? 0),
                'guaranteed_holding_days' => (int)($redemption['guaranteed_holding_days'] ?? 0),
            ];
        }

        if ($subscriptionIds === []) {
            return [];
        }

        $rows = Db::connect('db_bitfinex')
            ->name('fund_client_product_subscription')
            ->alias('s')
            ->leftJoin('fund_products p', 'p.id = s.product_id')
            ->where('s.client_id', $clientId)
            ->where('p.product_type', $statementType)
            ->where('s.status', 'REDEEMED')
            ->whereIn('s.id', array_values($subscriptionIds))
            ->field('s.id AS subscription_id, p.term, p.product_name, s.product_id, s.subscribe_amount, s.floating_shares, s.end_interest_date, s.redeemable_amount, s.compound_date, s.subscribe_date, s.guaranteed_rate_snapshot')
            ->order('s.id', 'asc')
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $sid = (int)($row['subscription_id'] ?? 0);
            $meta = $redemptionMetaBySub[$sid] ?? [];
            $row['week_full_redeemed'] = true;
            $row['full_redeem_settled_date'] = (string)($meta['settled_date'] ?? '');
            $row['full_redeem_amount'] = (float)($meta['redemption_amount'] ?? 0);
            $row['full_redeem_rate'] = (float)($meta['guaranteed_annual_rate'] ?? 0);
            if ($row['full_redeem_rate'] <= 0) {
                $row['full_redeem_rate'] = (float)($row['guaranteed_rate_snapshot'] ?? 0);
            }
            $row['full_redeem_holding_days'] = (int)($meta['guaranteed_holding_days'] ?? 0);
        }
        unset($row);

        return $rows;
    }

    /**
     * 本期全赎行本金：由赎回单 redemption_amount 反推本金（与 Go GuaranteedPrincipalFromPayout 一致）
     *
     * @param array<string, mixed> $row
     */
    private function resolveFullRedemptionPrincipalAmount(array $row): float
    {
        $payout = (float)($row['full_redeem_amount'] ?? 0);
        $rate   = (float)($row['full_redeem_rate'] ?? 0);
        $days   = (int)($row['full_redeem_holding_days'] ?? 0);

        if ($payout > 0 && $rate > 0 && $days > 0) {
            return $payout / (1 + $rate * $days / 365);
        }

        $subscribeAmount = (float)($row['subscribe_amount'] ?? 0);
        if ($subscribeAmount > 0) {
            return $subscribeAmount;
        }

        return $payout;
    }

    /**
     * 持仓本期/累计 accrual 截止日：本期全赎申购计到兑付日（含），不再延到 week_end。
     *
     * @param array<string, mixed> $row
     */
    private function resolveHoldingPeriodEndDate(array $row, string $weekEnd): string
    {
        if (empty($row['week_full_redeemed'])) {
            return $weekEnd;
        }

        $settledDate = trim((string)($row['full_redeem_settled_date'] ?? ''));
        if ($settledDate === '' || $settledDate === '-') {
            return $weekEnd;
        }

        return $settledDate < $weekEnd ? $settledDate : $weekEnd;
    }

    /**
     * 客户历史 accrual 利息合计（不限申购状态，含已赎回）。
     */
    private function clientHistoricalAccrualInterestSum(int $clientId, string $weekEnd): float
    {
        return (float)Db::connect('db_bitfinex')
            ->name('fund_client_subscription_accrual_daily')
            ->where('client_id', $clientId)
            ->where('accrual_date', '<=', $weekEnd)
            ->sum('interest_day_amount');
    }

    /**
     * 按申购汇总计提：startDate 为空则从最早计到 endDate（含）
     *
     * @param list<int> $subscriptionIds
     * @return array<int, float> subscription_id => sum
     */
    private function subscriptionAccrualSums(
        int $clientId,
        array $subscriptionIds,
        ?string $startDate,
        string $endDate
    ): array {
        if ($subscriptionIds === []) {
            return [];
        }

        $query = Db::connect('db_bitfinex')
            ->name('fund_client_subscription_accrual_daily')
            ->where('client_id', $clientId)
            ->whereIn('subscription_id', $subscriptionIds)
            ->where('accrual_date', '<=', $endDate);
        if ($startDate !== null && $startDate !== '') {
            $query->where('accrual_date', '>=', $startDate);
        }

        $rows = $query
            ->field('subscription_id, SUM(interest_day_amount) AS total')
            ->group('subscription_id')
            ->select()
            ->toArray();

        $out = [];
        foreach ($rows as $row) {
            $sid = (int)($row['subscription_id'] ?? 0);
            if ($sid > 0) {
                $out[$sid] = (float)($row['total'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * 单申购 accrual 利息合计（含首尾，status=final，与 Go 当前持仓利息一致）
     */
    private function sumSubscriptionAccrualInRange(
        int $clientId,
        int $subscriptionId,
        string $startDate,
        string $endDate
    ): float {
        if ($subscriptionId <= 0 || $startDate === '' || $startDate > $endDate) {
            return 0.0;
        }

        return (float)(Db::connect('db_bitfinex')
            ->name('fund_client_subscription_accrual_daily')
            ->where('client_id', $clientId)
            ->where('subscription_id', $subscriptionId)
            ->where('status', 'final')
            ->whereBetween('accrual_date', [$startDate, $endDate])
            ->sum('interest_day_amount') ?: 0);
    }

    private function termPeriodProfit(
        int $clientId,
        string $statementType,
        string $term,
        string $weekStart,
        string $weekEnd
    ): float {
        // 与期末持仓明细同口径：只统计仍 ACTIVE 的申购在本周的计提
        return (float)Db::connect('db_bitfinex')
            ->name('fund_client_subscription_accrual_daily')
            ->alias('a')
            ->leftJoin('fund_products p', 'p.id = a.product_id')
            ->leftJoin('fund_client_product_subscription s', 's.id = a.subscription_id')
            ->where('a.client_id', $clientId)
            ->where('p.product_type', $statementType)
            ->where('p.term', $term)
            ->where('s.status', 'ACTIVE')
            ->whereBetween('a.accrual_date', [$weekStart, $weekEnd])
            ->sum('a.interest_day_amount');
    }

    /**
     * 期末持仓净收益：按当前 ACTIVE 持仓逐笔计算
     * 活期 GUARANTEED：accrual 利息合计（起算 compound_date+1 / subscribe_date+1，截止 week_end）
     * 定期 GUARANTEED：subscribe_amount * guaranteed_rate_snapshot * 天数 / 365
     * 起息日：compound_date 非空则用 compound_date，否则 subscribe_date+1
     * 天数区间：[起息日, week_end]（含首尾）
     */
    private function calcClosingHoldingNetProfit(int $clientId, string $statementType, string $weekEnd): float
    {
        $rows = $this->subscriptionSumQuery($clientId, $statementType)
            ->where('s.status', 'ACTIVE')
            ->field('s.id AS subscription_id, s.subscribe_amount, s.guaranteed_rate_snapshot, s.compound_date, s.subscribe_date, p.term')
            ->select()
            ->toArray();

        $weekEndTs = strtotime($weekEnd);
        if ($weekEndTs === false) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($rows as $row) {
            $term = (string)($row['term'] ?? '');
            if ($term === 'FLEXIBLE' && $statementType === 'GUARANTEED') {
                $subscriptionId = (int)($row['subscription_id'] ?? 0);
                $holdingStart   = $this->resolveFlexibleHoldingInterestStartDate($row);
                $total += $this->sumSubscriptionAccrualInRange(
                    $clientId,
                    $subscriptionId,
                    $holdingStart,
                    $weekEnd
                );
                continue;
            }

            $startDate = $this->resolveHoldingInterestStartDate($row);
            $startTs   = strtotime($startDate);
            if ($startTs === false || $startTs > $weekEndTs) {
                continue;
            }

            $days = (int)floor(($weekEndTs - $startTs) / 86400) + 1;
            if ($days <= 0) {
                continue;
            }

            $principal = (float)($row['subscribe_amount'] ?? 0);
            $rate      = (float)($row['guaranteed_rate_snapshot'] ?? 0);
            $total += $principal * $rate * $days / 365;
        }

        return $total;
    }

    /**
     * 定期 GUARANTEED 公式起息日：compound_date 非空则用 compound_date，否则 subscribe_date+1
     *
     * @param array<string, mixed> $row
     */
    private function resolveHoldingInterestStartDate(array $row): string
    {
        $compoundDate = trim((string)($row['compound_date'] ?? ''));
        if ($compoundDate !== '' && $compoundDate !== '0000-00-00') {
            return $compoundDate;
        }

        $subscribeDate = trim((string)($row['subscribe_date'] ?? ''));
        $subscribeTs   = strtotime($subscribeDate);
        if ($subscribeTs === false) {
            return '';
        }

        return date('Y-m-d', strtotime('+1 day', $subscribeTs));
    }

    /**
     * 活期 GUARANTEED 当前持仓利息起算日（与 Go GuaranteedClosingHoldingInterestStartDate 一致）
     *
     * @param array<string, mixed> $row
     */
    private function resolveFlexibleHoldingInterestStartDate(array $row): string
    {
        $compoundDate = trim((string)($row['compound_date'] ?? ''));
        if ($compoundDate !== '' && $compoundDate !== '0000-00-00') {
            $compoundTs = strtotime($compoundDate);
            if ($compoundTs === false) {
                return '';
            }

            return date('Y-m-d', strtotime('+1 day', $compoundTs));
        }

        return $this->resolveHoldingInterestStartDate($row);
    }

    /**
     * 期末持仓累计收益：ACTIVE 申购日计提累加至账单周结束日（含）
     */
    private function termCumulativeProfit(
        int $clientId,
        string $statementType,
        string $term,
        string $weekEnd
    ): float {
        return (float)Db::connect('db_bitfinex')
            ->name('fund_client_subscription_accrual_daily')
            ->alias('a')
            ->leftJoin('fund_products p', 'p.id = a.product_id')
            ->leftJoin('fund_client_product_subscription s', 's.id = a.subscription_id')
            ->where('a.client_id', $clientId)
            ->where('p.product_type', $statementType)
            ->where('p.term', $term)
            ->where('s.status', 'ACTIVE')
            ->where('a.accrual_date', '<=', $weekEnd)
            ->sum('a.interest_day_amount');
    }

    /**
     * 本期申购记录（不含入金/出金/赎回；赎回见 buildRedemptionRecords）
     *
     * @return array<int, array<string, string>>
     */
    private function buildActivityRecords(int $clientId, string $statementType, string $weekStart, string $weekEnd): array
    {
        $subs = Db::connect('db_bitfinex')
            ->name('fund_client_product_subscription')
            ->alias('s')
            ->leftJoin('fund_products p', 'p.id = s.product_id')
            ->where('s.client_id', $clientId)
            ->where('p.product_type', $statementType)
            ->where('p.term', '<>', 'FLEXIBLE')
            ->whereBetween('s.subscribe_date', [$weekStart, $weekEnd])
            ->field('s.subscribe_date AS dt, p.term, s.subscribe_amount AS amount')
            ->order('s.subscribe_date', 'asc')
            ->select()
            ->toArray();

        $flexSubs = Db::connect('db_bitfinex')
            ->name('fund_client_product_subscription_flexibel')
            ->alias('s')
            ->leftJoin('fund_products p', 'p.id = s.product_id')
            ->where('s.client_id', $clientId)
            ->where('p.product_type', $statementType)
            ->whereBetween('s.subscribe_date', [$weekStart, $weekEnd])
            ->field('s.subscribe_date AS dt, p.term, s.subscribe_amount AS amount')
            ->order('s.subscribe_date', 'asc')
            ->select()
            ->toArray();

        $rows = array_merge($subs, $flexSubs);
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string)($a['dt'] ?? ''), (string)($b['dt'] ?? ''));
        });

        $out = [];
        foreach ($rows as $sub) {
            $out[] = [
                'date'   => (string)$sub['dt'],
                'type'   => '申购',
                'term'   => $this->termLabel((string)$sub['term']),
                'amount' => $this->fmtMoney($sub['amount'] ?? 0),
                'status' => '已完成',
                'remark' => '',
            ];
        }

        return $out;
    }

    /**
     * 本期续购记录：rollover_date 落在账单周内
     *
     * @return array<int, array<string, string>>
     */
    private function buildRolloverRecords(int $clientId, string $statementType, string $weekStart, string $weekEnd): array
    {
        $rows = Db::connect('db_bitfinex')
            ->name('fund_client_product_subscription_rollover')
            ->alias('r')
            ->leftJoin('fund_products p', 'p.id = r.product_id')
            ->where('r.client_id', $clientId)
            ->where('p.product_type', $statementType)
            ->whereBetween('r.rollover_date', [$weekStart, $weekEnd])
            ->field('r.rollover_date, r.prev_remaining_date, r.end_interest_date, p.term, r.subscribe_amount AS amount, r.status')
            ->order('r.rollover_date', 'asc')
            ->select()
            ->toArray();

        $out = [];
        foreach ($rows as $row) {
            $date = !empty($row['rollover_date'])
                ? (string)$row['rollover_date']
                : (string)($row['prev_remaining_date'] ?? '-');
            $status = strtoupper((string)($row['status'] ?? ''));
            $prevRemaining = (string)($row['prev_remaining_date'] ?? '');
            $endInterest   = (string)($row['end_interest_date'] ?? '');
            $remarkRange   = ($prevRemaining !== '' || $endInterest !== '')
                ? sprintf(
                    '续购时间范围：%s 到 %s',
                    $prevRemaining !== '' ? $prevRemaining : '-',
                    $endInterest !== '' ? $endInterest : '-'
                )
                : '';
            $out[] = [
                'date'   => $date,
                'type'   => '续购',
                'term'   => $this->termLabel((string)($row['term'] ?? '')),
                'amount' => $this->fmtMoney($row['amount'] ?? 0),
                'status' => $status === 'ACTIVE' ? '已完成' : ($status !== '' ? $status : '-'),
                'remark' => $remarkRange,
            ];
        }

        return $out;
    }

    /**
     * 本期赎回记录
     *
     * @return array<int, array<string, string>>
     */
    private function buildRedemptionRecords(int $clientId, string $statementType, string $weekStart, string $weekEnd): array
    {
        $reds = Db::connect('db_bitfinex')
            ->name('fund_client_product_redemption')
            ->alias('r')
            ->leftJoin('fund_products p', 'p.id = r.product_id')
            ->leftJoin('fund_client_product_subscription sub', 'sub.id = r.subscription_id')
            ->where('r.client_id', $clientId)
            ->where('p.product_type', $statementType)
            ->whereIn('r.status', ['paid', 'scheduled'])
            ->where(function ($query) use ($weekStart, $weekEnd) {
                $query->where(function ($q) use ($weekStart, $weekEnd) {
                    $q->where('r.status', 'paid')
                        ->whereNotNull('r.settled_at')
                        ->whereBetween('r.settled_at', ["{$weekStart} 00:00:00", "{$weekEnd} 23:59:59"]);
                })->whereOr(function ($q) use ($weekStart, $weekEnd) {
                    $q->where('r.status', 'scheduled')
                        ->whereBetween('r.due_date', [$weekStart, $weekEnd]);
                });
            })
            ->field([
                'r.settled_at',
                'r.requested_at',
                'r.due_date',
                'r.redemption_type',
                'p.term',
                'r.redemption_amount AS amount',
                'r.status',
                'r.remark',
                'sub.remaining_date',
            ])
            ->select()
            ->toArray();

        $out = [];
        foreach ($reds as $red) {
            $status = (string)($red['status'] ?? '');
            $out[] = [
                'date'   => $this->activityRecordDate($red),
                'type'   => $this->resolveRedemptionTypeLabel($red),
                'term'   => $this->termLabel((string)$red['term']),
                'amount' => $this->fmtMoney($red['amount'] ?? 0),
                'status' => $this->formatActivityStatus($status),
                'remark' => (string)($red['remark'] ?? ''),
            ];
        }

        usort($out, static fn ($a, $b) => strcmp($a['date'], $b['date']));
        return $out;
    }

    /**
     * @param array<string, mixed> $redemption
     */
    private function activityRecordDate(array $redemption): string
    {
        $status = strtolower((string)($redemption['status'] ?? ''));
        if ($status === 'paid' && !empty($redemption['settled_at'])) {
            return $this->formatUtcDatetime((string)$redemption['settled_at'], 'Y-m-d');
        }
        if (!empty($redemption['due_date'])) {
            return (string)$redemption['due_date'];
        }
        if (!empty($redemption['requested_at'])) {
            return $this->formatUtcDatetime((string)$redemption['requested_at'], 'Y-m-d');
        }

        return '-';
    }

    /**
     * @param array<string, mixed> $redemption
     */
    private function resolveRedemptionTypeLabel(array $redemption): string
    {
        $type = strtoupper((string)($redemption['redemption_type'] ?? ''));
        if ($type === 'PARTIAL') {
            return '部分赎回';
        }

        $dueDate       = (string)($redemption['due_date'] ?? '');
        $remainingDate = (string)($redemption['remaining_date'] ?? '');
        $settledAt     = (string)($redemption['settled_at'] ?? '');
        if ($settledAt !== '') {
            $settledDate = $this->formatUtcDatetime($settledAt, 'Y-m-d');
            if ($dueDate !== '' && $settledDate !== '-' && $settledDate < $dueDate) {
                return '提前赎回';
            }
            if ($dueDate === '' && $remainingDate !== '' && $settledDate !== '-' && $settledDate < $remainingDate) {
                return '提前赎回';
            }
        }

        return '到期赎回';
    }

    private function formatActivityStatus(string $status): string
    {
        return match (strtolower($status)) {
            'paid'      => '已完成',
            'scheduled' => '待付款',
            default     => $status !== '' ? $status : '-',
        };
    }

    /**
     * @param array<string, mixed> $metrics
     *
     * @return array{filename: string, content: string}
     */
    private function renderPdf(array $metrics): array
    {
        $statementType = (string)($metrics['statement_type'] ?? 'FLOATING');
        $template      = $statementType === 'GUARANTEED' ? 'pdf_guaranteed' : 'pdf_floating';

        foreach (['opening_principal', 'subscribe_in_week', 'redeem_in_week', 'closing_principal',
            'period_gross_profit', 'mgmt_fee', 'early_redeem_fee', 'other_fee', 'period_net_profit',
            'closing_holding_net_profit', 'cumulative_net_profit', 'closing_total_return'] as $key) {
            $metrics[$key . '_fmt'] = $this->fmtMoney($metrics[$key] ?? 0);
        }

        $html = $this->renderPdfHtml($template, $metrics);

        $clientId  = (int)($metrics['account_no'] ?? 0);
        $weekStart = (string)($metrics['week_start'] ?? 'week');
        $clientPrefix = $this->sanitizeFilenamePrefix(
            (string)($metrics['client_name'] ?? ''),
            $clientId
        );
        $filename = sprintf(
            '%s_weekly_%s_%s_%s.pdf',
            $clientPrefix,
            $weekStart,
            strtolower($statementType),
            $this->utcNowFormat('YmdHis')
        );

        if (class_exists(\Mpdf\Mpdf::class)) {
            $content = $this->renderPdfWithMpdf($html);
        } elseif (class_exists(\Dompdf\Dompdf::class)) {
            $content = $this->renderPdfWithDompdf($html);
        } else {
            throw new \RuntimeException(
                'PDF 生成失败：缺少 PDF 库。请在项目根目录执行 composer install --no-dev --optimize-autoloader -o'
            );
        }

        if ($content === '') {
            throw new \RuntimeException('PDF 生成结果为空');
        }

        return [
            'filename' => $filename,
            'content'  => $content,
        ];
    }

    private function renderPdfWithMpdf(string $html): string
    {
        $tmpDir = rtrim(runtime_path(), '/\\') . DIRECTORY_SEPARATOR . 'mpdf_tmp';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('无法创建 mpdf 临时目录');
        }

        $mpdf = new \Mpdf\Mpdf([
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'margin_left'       => 12,
            'margin_right'      => 12,
            'margin_top'        => 14,
            'margin_bottom'     => 14,
            'tempDir'           => $tmpDir,
            'autoScriptToLang'  => true,
            'autoLangToFont'    => true,
        ]);
        $mpdf->WriteHTML($html);

        return (string)$mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private function renderPdfWithDompdf(string $html): string
    {
        $fontDir = $this->bundledDompdfFontDir();

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'sun exta');
        $options->set('fontDir', $fontDir);
        $options->set('fontCache', $fontDir);
        $options->set('chroot', root_path());

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string)$dompdf->output();
    }

    private function bundledDompdfFontDir(): string
    {
        $dir = root_path() . 'app/bitfinex/resource/dompdf_fonts';
        $real = realpath($dir);
        if ($real === false || !is_file($real . DIRECTORY_SEPARATOR . 'installed-fonts.json')) {
            throw new \RuntimeException(
                'PDF 字体资源缺失，请确认已部署 app/bitfinex/resource/dompdf_fonts/ 目录'
            );
        }

        $installed = json_decode((string)file_get_contents($real . DIRECTORY_SEPARATOR . 'installed-fonts.json'), true);
        if (!is_array($installed) || !isset($installed['sun exta']['normal'])) {
            throw new \RuntimeException(
                'PDF 字体不完整，请同步最新 app/bitfinex/resource/dompdf_fonts/（需包含 sun exta 字体）'
            );
        }

        return $real;
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function renderPdfHtml(string $template, array $metrics): string
    {
        $path = dirname(__DIR__) . '/view/fund_weekly_statement/' . $template . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException('PDF 模板不存在：' . $path);
        }

        $m = $metrics;
        $h = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        include $path;
        $html = (string)ob_get_clean();

        if ($html === '' || !mb_check_encoding($html, 'UTF-8')) {
            throw new \RuntimeException('PDF 模板渲染失败（编码异常）');
        }

        return $html;
    }

    /**
     * 期初本金：优先上一周账单的 closing_principal；无上一周行则按 ACTIVE 持仓现算。
     */
    private function resolveOpeningPrincipal(int $clientId, string $statementType, string $weekStart): float
    {
        $prevWeekStart = date('Y-m-d', strtotime($weekStart . ' -7 days'));
        if ($prevWeekStart !== false && $prevWeekStart !== '') {
            $prev = Db::connect('db_bitfinex')
                ->name('fund_client_weekly_statement')
                ->where('client_id', $clientId)
                ->where('statement_type', $statementType)
                ->where('week_start', $prevWeekStart)
                ->field('closing_principal, total_invest')
                ->find();
            if (!empty($prev)) {
                // 新字段优先；未迁移/未回填时回退 total_invest
                if (isset($prev['closing_principal'])) {
                    return (float)$prev['closing_principal'];
                }
                return (float)($prev['total_invest'] ?? 0);
            }
        }

        return (float)($this->subscriptionSumQuery($clientId, $statementType)
            ->where('s.subscribe_date', '<', $weekStart)
            ->where('s.status', 'ACTIVE')
            ->sum('s.subscribe_amount') ?: 0);
    }

    private function subscriptionSumQuery(int $clientId, string $statementType): \think\db\Query
    {
        return Db::connect('db_bitfinex')
            ->name('fund_client_product_subscription')
            ->alias('s')
            ->leftJoin('fund_products p', 'p.id = s.product_id')
            ->where('s.client_id', $clientId)
            ->where('p.product_type', $statementType);
    }

    private function pairKey(int $clientId, string $statementType): string
    {
        return $clientId . ':' . $statementType;
    }

    private function termLabel(string $term): string
    {
        return match ($term) {
            'FLEXIBLE' => '活期',
            'D7'       => '7 天',
            'D14'      => '14 天',
            'D30'      => '30 天',
            'D60'      => '60 天',
            'D90'      => '90 天',
            'D120'     => '120 天',
            default    => $term,
        };
    }

    /**
     * PDF「保底年化利率」：申购 locked 利率 − 当前客户补贴（与计息 snapshot 拆分展示一致）
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $clientRates product_id => [guaranteed_rate, bonus]
     */
    private function resolveGuaranteedRateForHoldingRow(array $row, array $clientRates): float
    {
        $bonus = $this->resolveGuaranteedBonusForHoldingRow($row, $clientRates);

        $snapshot = (float)($row['guaranteed_rate_snapshot'] ?? 0);
        if ($snapshot > 0) {
            $floor = $snapshot - $bonus;

            return $floor > 0 ? $floor : 0.0;
        }

        $productId = (int)($row['product_id'] ?? 0);
        $cfg       = $clientRates[$productId] ?? null;
        if (is_array($cfg)) {
            return (float)($cfg['guaranteed_rate'] ?? 0);
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $clientRates product_id => [guaranteed_rate, bonus]
     */
    private function resolveGuaranteedBonusForHoldingRow(array $row, array $clientRates): float
    {
        $productId = (int)($row['product_id'] ?? 0);
        $cfg       = $clientRates[$productId] ?? null;
        if (is_array($cfg)) {
            return (float)($cfg['bonus'] ?? 0);
        }

        return 0.0;
    }

    private function formatAnnualRate(float $rate): string
    {
        return number_format($rate * 100, 1, '.', '') . '%';
    }

    /**
     * 多笔明细在同一单元格内换行展示（用于 PDF）
     *
     * @param array<int, string> $lines
     */
    private function joinHtmlLines(array $lines, string $fallback = '-'): string
    {
        $parts = [];
        foreach ($lines as $line) {
            $text = trim((string)$line);
            if ($text === '') {
                continue;
            }
            $parts[] = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if ($parts === []) {
            return htmlspecialchars($fallback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return implode('<br>', $parts);
    }

    private function sanitizeFilenamePrefix(string $clientName, int $clientId): string
    {
        $name = trim($clientName);
        if ($name === '') {
            return 'client' . $clientId;
        }

        $safe = preg_replace('/[\\\\\/:*?"<>|\\s]+/u', '_', $name) ?? '';
        $safe = trim($safe, '._-');

        return $safe !== '' ? $safe : 'client' . $clientId;
    }

    /**
     * @param mixed $value
     */
    private function fmtMoney($value): string
    {
        return number_format((float)$value, 2, '.', ',');
    }
}
