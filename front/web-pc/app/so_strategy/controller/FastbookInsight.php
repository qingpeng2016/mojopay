<?php
declare(strict_types=1);

namespace app\so_strategy\controller;

use app\so_strategy\service\FastbookAccounts;
use app\so_strategy\service\FastbookPnlQuery;
use think\admin\Controller;

/** SO 快盘 PNL 数据洞察（只读基础表实时聚合）。 */
class FastbookInsight extends Controller
{

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '快盘 · PNL 数据洞察';
        [$rangeStart, $rangeEnd] = $this->resolveRange($this->request->get());
        [$dayChart, $dayRank] = $this->buildPeriodPayload('day');

        $this->assign([
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'cards' => $this->coreMetrics($rangeStart, $rangeEnd),
            'excluded_accounts' => implode('、', FastbookAccounts::excludedIds()),
            'chart_data_json' => $this->jsonForHtml(['day' => $dayChart]),
            'rank_data_json' => $this->jsonForHtml(['day' => $dayRank]),
        ]);
        return $this->fetch();
    }

    /**
     * 日/周/月全局趋势及 Outcome 排行榜，供页面按需加载。
     * @auth true
     */
    public function period()
    {
        $period = (string)$this->request->get('period', '');
        if (!in_array($period, ['day', 'week', 'month'], true)) {
            $this->error('invalid period');
            return;
        }
        [$chart, $rank] = $this->buildPeriodPayload($period);
        $this->success('ok', ['chart' => $chart, 'rank' => $rank]);
    }

    /**
     * 自定义日期范围核心指标，供页面局部刷新。
     * @auth true
     */
    public function cards()
    {
        [$start, $end] = $this->resolveRange($this->request->get());
        $this->success('查询成功', $this->coreMetrics($start, $end));
    }

    private function buildPeriodPayload(string $period): array
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $end = $today->format('Y-m-d');
        $start = match ($period) {
            'day' => $end,
            'week' => $today->modify('-6 days')->format('Y-m-d'),
            'month' => $today->modify('-29 days')->format('Y-m-d'),
        };
        $chart = $period === 'day' ? $this->hourlyPnlSeries($end) : $this->dailyPnlSeries($start, $end);
        [$top, $bottom] = $this->outcomeRankings($start, $end, 10);
        return [$chart, ['top' => $top, 'bottom' => $bottom]];
    }

    /** 统一口径：Foregate 快盘全部已成交数据，不限制 Outcome 状态。 */
    private function baseQuery(string $start, string $end)
    {
        $query = FastbookPnlQuery::query()
            ->where('market_type', 'FASTBOOK')
            ->where('vendor', 'FOREGATE')
            ->whereRaw($this->outcomeTimeExpr() . ' BETWEEN ? AND ?', [
                $start . ' 00:00:00', $end . ' 23:59:59.999999',
            ]);
        $excluded = FastbookAccounts::excludedIds();
        if ($excluded !== []) {
            $query->whereNotIn('account_id', $excluded);
        }
        return $query;
    }

    /** 核心卡片统一按实际独立结算单元 Outcome 聚合。 */
    private function coreMetrics(string $start, string $end): array
    {
        $rows = $this->outcomePnlRows($start, $end);
        $totalPnl = 0.0;
        $feeRevenue = 0.0;
        $win = 0;
        $lose = 0;
        $minPnl = null;
        $minOutcomeId = '';
        foreach ($rows as $row) {
            $pnl = (float)$row['pnl'];
            $totalPnl += $pnl;
            $feeRevenue += (float)$row['fee_revenue'];
            if ($pnl > 0) ++$win;
            elseif ($pnl < 0) ++$lose;
            if ($minPnl === null || $pnl < $minPnl) {
                $minPnl = $pnl;
                $minOutcomeId = (string)$row['outcome_id'];
            }
        }
        $outcomeCount = count($rows);
        $hasLoss = $minPnl !== null && $minPnl < 0;
        return [
            'total_pnl' => $totalPnl,
            'total_pnl_fmt' => number_format($totalPnl, 2),
            'fee_revenue_fmt' => number_format($feeRevenue, 2),
            'avg_pnl_fmt' => number_format($outcomeCount > 0 ? $totalPnl / $outcomeCount : 0, 2),
            'outcome_count' => $outcomeCount,
            'win_count' => $win,
            'lose_count' => $lose,
            'win_pct_fmt' => number_format($outcomeCount > 0 ? $win / $outcomeCount * 100 : 0, 1),
            'lose_pct_fmt' => number_format($outcomeCount > 0 ? $lose / $outcomeCount * 100 : 0, 1),
            'max_loss_fmt' => number_format($hasLoss ? (float)$minPnl : 0, 2),
            'max_loss_id' => $hasLoss ? $minOutcomeId : '',
            'max_loss_id_short' => $hasLoss ? $this->shortenId($minOutcomeId) : '',
        ];
    }

    /** 一个 Outcome 是一轮独立开盘、交易、结算的快盘市场。 */
    private function outcomePnlRows(string $start, string $end): array
    {
        return $this->baseQuery($start, $end)
            ->fieldRaw('market_config_id, outcome_id, MAX(market_id) AS market_id, MAX(market_name) AS market_name, SUM(' . $this->pnlExpr() . ') AS pnl, SUM(trade_fee_amount) AS fee_revenue')
            ->group('market_config_id,outcome_id')->select()->toArray();
    }

    private function outcomeRankings(string $start, string $end, int $limit): array
    {
        $profit = [];
        $loss = [];
        foreach ($this->outcomePnlRows($start, $end) as $row) {
            $item = [
                'outcome_id' => (string)$row['outcome_id'],
                'market_id' => (string)$row['market_id'],
                'market_name' => (string)($row['market_name'] ?? ''),
                'pnl' => round((float)$row['pnl'], 8),
            ];
            if ($item['pnl'] > 0) $profit[] = $item;
            elseif ($item['pnl'] < 0) $loss[] = $item;
        }
        usort($profit, static fn(array $a, array $b): int => $b['pnl'] <=> $a['pnl']);
        usort($loss, static fn(array $a, array $b): int => $a['pnl'] <=> $b['pnl']);
        return [array_slice($profit, 0, $limit), array_slice($loss, 0, $limit)];
    }

    private function hourlyPnlSeries(string $date): array
    {
        $timeExpr = $this->outcomeTimeExpr();
        $rows = $this->baseQuery($date, $date)->fieldRaw('HOUR(' . $timeExpr . ') AS bucket, SUM(' . $this->pnlExpr() . ') AS pnl')
            ->group('bucket')->select()->toArray();
        $values = [];
        foreach ($rows as $row) $values[(int)$row['bucket']] = (float)$row['pnl'];
        $labels = $daily = $cum = [];
        $running = 0.0;
        for ($hour = 0; $hour < 24; ++$hour) {
            $value = $values[$hour] ?? 0.0;
            $running += $value;
            $labels[] = sprintf('%02d:00', $hour);
            $daily[] = round($value, 8);
            $cum[] = round($running, 8);
        }
        return ['labels' => $labels, 'daily' => $daily, 'cum' => $cum];
    }

    private function dailyPnlSeries(string $start, string $end): array
    {
        $timeExpr = $this->outcomeTimeExpr();
        $rows = $this->baseQuery($start, $end)->fieldRaw('DATE(' . $timeExpr . ') AS bucket, SUM(' . $this->pnlExpr() . ') AS pnl')
            ->group('bucket')->select()->toArray();
        $values = [];
        foreach ($rows as $row) $values[(string)$row['bucket']] = (float)$row['pnl'];
        $labels = $daily = $cum = [];
        $running = 0.0;
        $cursor = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
        $last = new \DateTimeImmutable($end, new \DateTimeZone('UTC'));
        while ($cursor <= $last) {
            $key = $cursor->format('Y-m-d');
            $value = $values[$key] ?? 0.0;
            $running += $value;
            $labels[] = $key;
            $daily[] = round($value, 8);
            $cum[] = round($running, 8);
            $cursor = $cursor->modify('+1 day');
        }
        return ['labels' => $labels, 'daily' => $daily, 'cum' => $cum];
    }

    /** 公司快盘 PNL = -SUM(排除做市账号后的客户 PNL)；手续费不进入 PNL。 */
    private function pnlExpr(): string
    {
        return '(pnl.trading_cashflow - COALESCE(pnl.settlement_cashflow,0))';
    }

    /** 未结盘视图无 window_end_time，回退到 Outcome 选项的交易结束时间。 */
    private function outcomeTimeExpr(): string
    {
        return 'COALESCE(pnl.window_end_time,(SELECT MAX(mo.trading_end_at) FROM market_options mo WHERE mo.market_config_id=pnl.market_config_id AND mo.outcome_id=pnl.outcome_id))';
    }

    private function resolveRange(array $get): array
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $end = $this->validDate((string)($get['range_end'] ?? '')) ?: $today->format('Y-m-d');
        $start = $this->validDate((string)($get['range_start'] ?? '')) ?: (new \DateTimeImmutable($end, new \DateTimeZone('UTC')))->modify('-29 days')->format('Y-m-d');
        return $start <= $end ? [$start, $end] : [$end, $start];
    }

    private function validDate(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function shortenId(string $value): string
    {
        return strlen($value) <= 28 ? $value : substr($value, 0, 12) . '…' . substr($value, -10);
    }

    private function jsonForHtml(array $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
