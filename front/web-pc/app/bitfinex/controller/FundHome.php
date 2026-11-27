<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 3.6.1 理财产品首页 Dashboard（fund_* 表）
 */
class FundHome extends Controller
{
    use SafeQueryTrait;

    private const MARKET_SYMBOL = 'fUSD';

    private const UNCLEAR_FUNDS_THRESHOLD = 1000.0;

    private const ENGINE_STALE_SECONDS = 300;

    private const LOW_UTILIZATION_THRESHOLD = 80.0;

    /** Bitfinex funding 付息 ledger description */
    private const LEDGER_FUNDING_PAYMENT_DESC = 'Margin Funding Payment on wallet funding';

    /** 在贷 ACTIVE position 未入账付息折算系数 */
    private const LOAN_ACCRUAL_NET_FACTOR = 0.85;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '理财产品 Dashboard';
        $today       = date('Y-m-d');
        $db          = Db::connect('db_bitfinex');

        $products = $db->name('fund_products')
            ->where('status', 'active')
            ->orderRaw("FIELD(product_type, 'FLOATING', 'GUARANTEED'), FIELD(term, 'FLEXIBLE', 'D7', 'D14', 'D30', 'D60', 'D90', 'D120'), id ASC")
            ->select()
            ->toArray();

        $productCards = [];
        $sumLending   = 0.0;
        $sumCashAvailable = 0.0;
        $sumSubscribeToday = 0.0;
        $sumRedeemToday  = 0.0;

        foreach ($products as $product) {
            $card = $this->buildProductCard($db, $product, $today);
            $productCards[] = $card;
            $sumLending += (float)$card['cash_lending'];
            $sumCashAvailable += (float)($product['cash_available'] ?? 0);
            $sumSubscribeToday += (float)$card['subscribe_today'];
            $sumRedeemToday += (float)$card['redeem_today'];
        }

        $utilizationPct = $sumCashAvailable > 0 ? $sumLending / $sumCashAvailable * 100 : 0.0;
        $activeClientCount = (int)($db->name('fund_client_product_subscription')
            ->where('status', 'ACTIVE')
            ->field('COUNT(DISTINCT client_id) AS cnt')
            ->find()['cnt'] ?? 0);

        $productIds           = array_map(static fn(array $p): int => (int)$p['id'], $products);
        $guaranteedProductIds = [];
        foreach ($products as $product) {
            if (strtoupper((string)($product['product_type'] ?? '')) === 'GUARANTEED') {
                $guaranteedProductIds[] = (int)$product['id'];
            }
        }
        $poolActualMap     = $this->loadPoolActualMap($db, $productIds);
        $guaranteedDebtMap = $this->loadGuaranteedAccrualMap($db, $guaranteedProductIds);
        $totalProfit       = $this->sumMjTotalProfit($db, $productIds);

        $summary = [
            'active_clients'      => $activeClientCount,
            'r_last_fmt'          => $this->loadRLastFmt($db),
            'frr_fmt'             => $this->loadFrrFmt($db),
            'aum_fmt'             => $this->fmtMoneyCompact($sumCashAvailable),
            'subscribe_today_fmt' => $this->fmtMoneyCompact($sumSubscribeToday),
            'redeem_today_fmt'    => $this->fmtMoneyCompact($sumRedeemToday),
            'utilization_fmt'     => number_format($utilizationPct, 2, '.', '') . '%',
            'utilization_pct'     => number_format(min(100, max(0, $utilizationPct)), 2, '.', ''),
            'total_profit_fmt'    => '$' . number_format($totalProfit, 2, '.', ''),
        ];

        $this->assign([
            'summary'       => $summary,
            'product_cards' => $productCards,
            'alerts'        => $this->buildAlerts($db, $products, $today, $poolActualMap, $guaranteedDebtMap),
        ]);

        return $this->fetch();
    }

    /**
     * @param \think\db\Connection $db
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function buildProductCard($db, array $product, string $today): array
    {
        $productId     = (int)$product['id'];
        $cashAvailable = (float)($product['cash_available'] ?? 0);
        $lending       = (float)($product['cash_lending'] ?? 0);
        $pending       = (float)($product['cash_pending'] ?? 0);
        $idle          = $cashAvailable - $pending - $lending;
        $isFloating    = strtoupper((string)($product['product_type'] ?? '')) === 'FLOATING';

        $subscribeToday = (float)($db->name('fund_client_product_subscription')
            ->where('product_id', $productId)
            ->where('status', 'ACTIVE')
            ->where('subscribe_date', $today)
            ->sum('subscribe_amount') ?: 0);

        $redeemToday = (float)($db->name('fund_client_product_subscription')
            ->where('product_id', $productId)
            ->where('status', 'REDEEMED')
            ->where('remaining_date', $today)
            ->sum('redeemable_amount') ?: 0);

        $activeClients = (int)($db->name('fund_client_product_subscription')
            ->where('product_id', $productId)
            ->where('status', 'ACTIVE')
            ->field('COUNT(DISTINCT client_id) AS cnt')
            ->find()['cnt'] ?? 0);

        $utilizationPct = $cashAvailable > 0 ? $lending / $cashAvailable * 100 : 0.0;
        $cashParts      = $this->cashBarParts($idle, $lending, $pending);

        return [
            'product_id'           => $productId,
            'product_name'         => (string)($product['product_name'] ?? ''),
            'product_type'         => (string)($product['product_type'] ?? ''),
            'product_type_label'   => $isFloating ? '浮动' : '保底',
            'type_badge_class'     => $isFloating ? 'fh-badge-float' : 'fh-badge-guar',
            'term'                 => (string)($product['term'] ?? ''),
            'cash_lending'         => $lending,
            'cash_available'       => $cashAvailable,
            'cash_idle'            => $idle,
            'cash_pending'         => $pending,
            'aum_fmt'              => $this->fmtMoneyCompact($cashAvailable),
            'nav_fmt'              => $isFloating
                ? number_format((float)($product['nav_per_share'] ?? 0), 6, '.', '')
                : '—',
            'annual_7d_fmt'        => '0.0%',
            'cash_idle_fmt'        => $this->fmtMoneyCompact($idle),
            'cash_lending_fmt'     => $this->fmtMoneyCompact($lending),
            'cash_pending_fmt'     => $this->fmtMoneyCompact($pending),
            'cash_idle_pct'        => $cashParts['idle'],
            'cash_lend_pct'        => $cashParts['lend'],
            'cash_pend_pct'        => $cashParts['pend'],
            'utilization_fmt'      => number_format($utilizationPct, 2, '.', '') . '%',
            'utilization_pct'      => number_format(min(100, max(0, $utilizationPct)), 2, '.', ''),
            'util_bar_tone'        => $utilizationPct < self::LOW_UTILIZATION_THRESHOLD ? 'warn' : 'ok',
            'subscribe_today'      => $subscribeToday,
            'subscribe_today_fmt'  => $this->fmtMoneyCompact($subscribeToday),
            'redeem_today'         => $redeemToday,
            'redeem_today_fmt'     => $this->fmtMoneyCompact($redeemToday),
            'active_clients'       => $activeClients,
        ];
    }

    /**
     * MJ总利润 = Σ fund_product_data_summary.mj_total_profit
     *
     * @param \think\db\Connection $db
     * @param list<int> $productIds
     */
    private function sumMjTotalProfit($db, array $productIds): float
    {
        if ($productIds === []) {
            return 0.0;
        }

        return (float)($db->name('fund_product_data_summary')
            ->whereIn('product_id', $productIds)
            ->sum('mj_total_profit') ?: 0);
    }

    /**
     * @param \think\db\Connection $db
     * @param list<array<string, mixed>> $products
     * @param array<int, float> $poolActualMap
     * @param array<int, float> $guaranteedDebtMap
     * @return list<array<string, string>>
     */
    private function buildAlerts(
        $db,
        array $products,
        string $today,
        array $poolActualMap,
        array $guaranteedDebtMap
    ): array {
        $alerts = [];

        foreach ($products as $product) {
            $productId   = (int)$product['id'];
            $productName = (string)($product['product_name'] ?? '');

            // Flow I shortfall=max(0,gap-lock_applied)；锁仓后仍无法覆盖则报警
            $shortfallRow = $db->name('fund_product_liquidity_daily')
                ->where('product_id', $productId)
                ->where('calc_date', $today)
                ->where('shortfall', '>', 0)
                ->order('liquidity_date', 'asc')
                ->find();

            if ($shortfallRow) {
                $shortfall = (float)($shortfallRow['shortfall'] ?? 0);
                $alerts[] = [
                    'type'  => 'coverage_shortfall',
                    'level' => 'danger',
                    'label' => '覆盖缺口',
                    'text'  => sprintf(
                        '%s: 赎回日 %s 锁仓后仍无法覆盖，缺口 %s',
                        $productName,
                        (string)$shortfallRow['liquidity_date'],
                        $this->fmtMoneyCompact(max(0, $shortfall))
                    ),
                ];
            }

            $activeSub = (float)$db->name('fund_client_product_subscription')
                ->where('product_id', $productId)
                ->where('status', 'ACTIVE')
                ->sum('subscribe_amount');

            $lending       = (float)($product['cash_lending'] ?? 0);
            $cashAvailable = (float)($product['cash_available'] ?? 0);
            $cashTotal     = (float)($product['cash_total'] ?? 0);
            $utilizationPct = $cashAvailable > 0 ? $lending / $cashAvailable * 100 : 0.0;
            if ($cashAvailable > 0 && $utilizationPct < self::LOW_UTILIZATION_THRESHOLD) {
                $alerts[] = [
                    'type'  => 'low_utilization',
                    'level' => 'warning',
                    'label' => '利用率过低',
                    'text'  => sprintf(
                        '%s: 利用率 %s < %.0f%%',
                        $productName,
                        number_format($utilizationPct, 2, '.', '') . '%',
                        self::LOW_UTILIZATION_THRESHOLD
                    ),
                ];
            }

            $unclear = $cashTotal - $activeSub - $lending - (float)($product['cash_pending'] ?? 0);
            if ($unclear > self::UNCLEAR_FUNDS_THRESHOLD) {
                $alerts[] = [
                    'type'  => 'unclear_funds',
                    'level' => 'warning',
                    'label' => '不明资金',
                    'text'  => sprintf(
                        '%s: cash − ACTIVE sub − 在借 − 挂单 = %s > 阈值 $1,000',
                        $productName,
                        $this->fmtMoneyCompact($unclear)
                    ),
                ];
            }

            if (strtoupper((string)($product['product_type'] ?? '')) === 'GUARANTEED') {
                $poolActual          = $poolActualMap[$productId] ?? 0.0;
                $guaranteedLiability = $guaranteedDebtMap[$productId] ?? 0.0;
                if ($guaranteedLiability > 0 && $poolActual < $guaranteedLiability) {
                    $shortfall = $guaranteedLiability - $poolActual;
                    $alerts[] = [
                        'type'  => 'guaranteed_shortfall',
                        'level' => 'danger',
                        'label' => '保底差额',
                        'text'  => sprintf(
                            '%s: 池实际收益 %s < 保底负债 %s，差额 %s',
                            $productName,
                            $this->fmtMoneyCompact($poolActual),
                            $this->fmtMoneyCompact($guaranteedLiability),
                            $this->fmtMoneyCompact(-$shortfall)
                        ),
                    ];
                }
            }
        }

        $staleTasks = $this->loadStaleEngineTasks($db);
        foreach ($staleTasks as $task) {
            $alerts[] = [
                'type'  => 'engine_stale',
                'level' => 'danger',
                'label' => '引擎异常',
                'text'  => sprintf('%s: 引擎超过 %d 分钟未执行周期', $task['label'], (int)(self::ENGINE_STALE_SECONDS / 60)),
            ];
        }

        return $alerts;
    }

    /**
     * @param \think\db\Connection $db
     * @param list<int> $productIds
     * @return array<int, float>
     */
    private function loadPoolActualMap($db, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $query = $db->name('fund_bitfinex_ledger_hist')
            ->whereIn('product_id', $productIds)
            ->where('description', self::LEDGER_FUNDING_PAYMENT_DESC);

        if (!$this->ledgerIncludesTodayUtc8()) {
            $todayUtc8 = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
            $query->whereRaw(
                "DATE(CONVERT_TZ(FROM_UNIXTIME(mts/1000), '+00:00', '+08:00')) < ?",
                [$todayUtc8]
            );
        }

        $rows = $query
            ->field('product_id, SUM(amount) AS total')
            ->group('product_id')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['product_id']] = (float)($row['total'] ?? 0);
        }

        foreach ($this->loadActiveLoanAccrualMap($db, $productIds) as $productId => $accrued) {
            $map[$productId] = ($map[$productId] ?? 0.0) + $accrued;
        }

        return $map;
    }

    /**
     * ACTIVE 在贷 position：SUM(amount × rate × 0.85)
     *
     * @param \think\db\Connection $db
     * @param list<int> $productIds
     * @return array<int, float>
     */
    private function loadActiveLoanAccrualMap($db, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $factor = self::LOAN_ACCRUAL_NET_FACTOR;
        $rows   = $db->name('fund_bitfinex_product_loan')
            ->whereIn('product_id', $productIds)
            ->where('status', 'ACTIVE')
            ->field("product_id, SUM(amount * rate * {$factor}) AS total")
            ->group('product_id')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['product_id']] = (float)($row['total'] ?? 0);
        }

        return $map;
    }

    /** UTC ≥ 02:00 后计入当日（UTC+8 日历日）ledger 付息 */
    private function ledgerIncludesTodayUtc8(): bool
    {
        return (int)gmdate('H') >= 2;
    }

    /**
     * @param \think\db\Connection $db
     * @param list<int> $productIds
     * @return array<int, float>
     */
    private function loadGuaranteedAccrualMap($db, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = $db->name('fund_client_subscription_accrual_daily')
            ->whereIn('product_id', $productIds)
            ->field('product_id, SUM(interest_day_amount) AS total')
            ->group('product_id')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['product_id']] = (float)($row['total'] ?? 0);
        }

        return $map;
    }

    /**
     * @param \think\db\Connection $db
     * @return list<array{label: string}>
     */
    private function loadStaleEngineTasks($db): array
    {
        $tasks = $db->name('bot_schedule_config')
            ->where('module', 'bitfinex')
            ->whereLike('task_name', 'fund_%')
            ->where('is_enabled', 1)
            ->select()
            ->toArray();

        $stale = [];
        $now   = time();
        foreach ($tasks as $task) {
            $lastLive = trim((string)($task['last_live_time'] ?? ''));
            if ($lastLive === '') {
                continue;
            }
            $ts = strtotime($lastLive);
            if ($ts === false || ($now - $ts) <= self::ENGINE_STALE_SECONDS) {
                continue;
            }
            $stale[] = [
                'label' => (string)($task['description'] ?: $task['task_name']),
            ];
        }

        return $stale;
    }

    /**
     * @param \think\db\Connection $db
     */
    private function loadRLastFmt($db): string
    {
        $row = $db->name('public_funding_trades_hist')
            ->where('symbol', self::MARKET_SYMBOL)
            ->order('mts', 'desc')
            ->order('id', 'desc')
            ->find();

        if (!$row) {
            return '-';
        }

        return $this->fmtDailyRatePercent((float)($row['rate'] ?? 0));
    }

    /**
     * @param \think\db\Connection $db
     */
    private function loadFrrFmt($db): string
    {
        $row = $db->name('funding_rate_anchor')
            ->where('symbol', self::MARKET_SYMBOL)
            ->order('updated_at', 'desc')
            ->order('id', 'desc')
            ->find();

        if (!$row) {
            return '-';
        }

        return $this->fmtDailyRatePercent((float)($row['frr'] ?? 0));
    }

    private function fmtDailyRatePercent(float $rate): string
    {
        return number_format($rate * 100, 4, '.', '') . '%';
    }

    private function fmtMoneyCompact(float $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $abs  = abs($amount);

        if ($abs >= 1000000) {
            return $sign . '$' . number_format($abs / 1000000, 2, '.', '') . 'M';
        }
        if ($abs >= 1000) {
            return $sign . '$' . number_format($abs / 1000, 1, '.', '') . 'K';
        }

        return $sign . '$' . number_format($abs, 2, '.', ',');
    }

    /**
     * @return array{idle: string, lend: string, pend: string}
     */
    private function cashBarParts(float $idle, float $lending, float $pending): array
    {
        $total = $idle + $lending + $pending;
        if ($total <= 0) {
            return ['idle' => '0', 'lend' => '0', 'pend' => '0'];
        }

        return [
            'idle' => number_format($idle / $total * 100, 2, '.', ''),
            'lend' => number_format($lending / $total * 100, 2, '.', ''),
            'pend' => number_format($pending / $total * 100, 2, '.', ''),
        ];
    }
}
