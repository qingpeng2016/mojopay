<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\trait\ScarbListTrait;
use think\admin\Controller;
use think\db\Query;
use think\Paginator;

/**
 * SC Arb 首页：手续费、用户持仓（UNIFIED）、理财奖励累计（earn_reward_records）
 */
class Dashboard extends Controller
{
    use ScarbListTrait;

    private const WALLET_TYPE_UNIFIED = 'UNIFIED';

    private const WALLET_ASSETS = ['USDT', 'USDC', 'BFUSD'];


    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = 'SC Arb 首页';
        $get          = $this->request->get();
        $accountTabs  = $this->loadDashboardAccountTabs();
        $accountId    = $this->resolveSelectedAccountId($get, $accountTabs);

        $commissionPaginator = $this->paginateCommissionSummary($get, $accountId, 20);
        $commissionList      = $commissionPaginator->items();

        $walletRows         = $accountId ? $this->loadUnifiedWalletSummary($accountId) : [];
        $rewardChartBlocks  = $accountId ? $this->buildEarnRewardCharts7d($accountId) : [];
        $dashboardSummary   = $this->loadDashboardSummary($accountId);
        $openPositionGroups  = $accountId ? $this->loadOpenPositionsBySymbol($accountId) : [];
        $recentOrders        = $accountId ? $this->loadRecentOrders($accountId, 10) : [];
        $dashboardPendingAlerts = $this->loadDashboardPendingAlerts();

        $selectedTab = null;
        foreach ($accountTabs as $tab) {
            if ((int)($tab['id'] ?? 0) === $accountId) {
                $selectedTab = $tab;
                break;
            }
        }

        $this->assign([
            'account_tabs'                    => $accountTabs,
            'selected_account_id'             => $accountId,
            'selected_account'                => $selectedTab,
            'commission_list'                 => $commissionList,
            'wallet_rows'                     => $walletRows,
            'reward_chart_blocks'             => $rewardChartBlocks,
            'dashboard_summary'               => $dashboardSummary,
            'open_position_groups'            => $openPositionGroups,
            'open_position_pair_count'        => count($openPositionGroups),
            'recent_orders'                   => $recentOrders,
            'pagehtml'                        => $commissionPaginator->render(),
            'commission_totals_by_account'    => $this->buildCommissionTotalsByAccount($commissionList),
            'dashboard_pending_alerts'        => $dashboardPendingAlerts,
        ]);

        return $this->fetch();
    }

    /**
     * 首页：当前账号最新订单（id 降序）
     *
     * @return list<array<string, mixed>>
     */
    private function loadRecentOrders(int $accountId, int $limit = 10): array
    {
        if ($accountId < 1 || $limit < 1) {
            return [];
        }

        $rows = $this->scarbDb()
            ->name('orders')
            ->where('exchange_account_id', $accountId)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['orig_qty_fmt']     = $this->trimDecimal($row['orig_qty'] ?? 0, 8);
            $row['executed_qty_fmt'] = $this->trimDecimal($row['executed_qty'] ?? 0, 8);
            $row['avg_price_fmt']    = $this->trimDecimal($row['avg_price'] ?? null, 8);
            $row['created_at']       = $this->formatDatetime($row['created_at'] ?? null);
        }
        unset($row);

        return $rows;
    }

    /**
     * 首页顶部 KPI：总负债、已奖励（全历史按币种）、预计年化率
     *
     * @return array{total_debt_usd_fmt: string, reward_lines: list<array{qty_fmt: string, reward_asset: string}>, estimated_payback_fmt: string, estimated_apr_fmt: string}
     */
    private function loadDashboardSummary(?int $accountId): array
    {
        if ($accountId === null || $accountId < 1) {
            return [
                'total_debt_usd_fmt'    => '—',
                'reward_lines'          => [],
                'estimated_payback_fmt' => '—',
                'estimated_apr_fmt'     => '—',
                'portfolio_uni_mmr_fmt' => '—',
                'portfolio_uni_mmr_ok'  => false,
            ];
        }

        $row = $this->scarbDb()
            ->name('account_exchange')
            ->where('id', $accountId)
            ->field('portfolio_total_debt_usd, portfolio_total_asset_usdt, origin_portfolio_total_asset_usdt, portfolio_uni_mmr, min_portfolio_uni_mmr')
            ->find();

        $debtRaw = $row['portfolio_total_debt_usd'] ?? null;
        if ($debtRaw === null || $debtRaw === '') {
            $debtFmt = '—';
        } else {
            $debtFmt = '$' . $this->trimDecimal($debtRaw, 2);
        }

        $rewardLines = [];
        foreach ($this->loadEarnRewardQtySummary($accountId) as $item) {
            $rewardLines[] = [
                'qty_fmt'      => (string)($item['reward_qty_fmt'] ?? '0'),
                'reward_asset' => (string)($item['reward_asset'] ?? ''),
            ];
        }

        $aprCtx = $this->computeEstimatedAprContext($accountId, $row);

        $mmrRaw = $row['portfolio_uni_mmr'] ?? null;
        if ($mmrRaw === null || $mmrRaw === '') {
            $uniMmrFmt = '—';
            $uniMmrOk  = false;
        } else {
            $uniMmrVal = (float)$mmrRaw;
            $uniMmrFmt = $this->trimDecimal($uniMmrVal, 4);
            $minMmr    = (float)($row['min_portfolio_uni_mmr'] ?? 3);
            if ($minMmr <= 0) {
                $minMmr = 3;
            }
            $uniMmrOk = $uniMmrVal >= $minMmr;
        }

        return [
            'total_debt_usd_fmt'    => $debtFmt,
            'reward_lines'          => $rewardLines,
            'estimated_payback_fmt' => $this->formatEstimatedPaybackDays($accountId, $row, $aprCtx),
            'estimated_apr_fmt'     => $aprCtx !== null
                ? $this->trimDecimal($aprCtx['percent'], 4) . ' %'
                : '—',
            'portfolio_uni_mmr_fmt' => $uniMmrFmt,
            'portfolio_uni_mmr_ok'  => $uniMmrOk,
        ];
    }

    /**
     * 预计年化率（小数）= 近30天 AVG(annual_percentage_rate) × 倍数；展示值 ×100 为百分数
     * 倍数 = account_asset（UNIFIED + BFUSD）单条 balance_wallet / origin_portfolio_total_asset_usdt
     *
     * @param array<string, mixed>|null $accountRow
     * @return null|array{total_asset: float, bfusd_balance_wallet: float, multiplier: float, avg_apr: float, apr_decimal: float, percent: float}
     */
    private function computeEstimatedAprContext(int $accountId, ?array $accountRow): ?array
    {
        if ($accountRow === null) {
            return null;
        }
        $totalAssetRaw = $accountRow['origin_portfolio_total_asset_usdt'] ?? null;
        if ($totalAssetRaw === null || $totalAssetRaw === '') {
            return null;
        }
        $totalAsset = (float)$totalAssetRaw;
        if ($totalAsset <= 0) {
            return null;
        }

        $bfusdWallet = $this->unifiedBfusdBalanceWallet($accountId);
        $multiplier = $bfusdWallet / $totalAsset;

        $avgApr = $this->scarbDb()
            ->name('earn_reward_records')
            ->where('exchange_account_id', $accountId)
            ->where('reward_time', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))
            ->avg('annual_percentage_rate');
        if ($avgApr === null || $avgApr === '') {
            return null;
        }

        $avgAprFloat = (float)$avgApr;
        $aprDecimal  = $avgAprFloat * $multiplier;

        return [
            'total_asset'       => $totalAsset,
            'bfusd_balance_wallet' => $bfusdWallet,
            'multiplier'        => $multiplier,
            'avg_apr'           => $avgAprFloat,
            'apr_decimal'       => $aprDecimal,
            'percent'           => $aprDecimal * 100,
        ];
    }

    /**
     * 已奖励 - 总负债 >= 0 → --；否则 (总负债-已奖励) / ((近30天 AVG(annual_percentage_rate)/365) × UNIFIED BFUSD balance_wallet)
     * 注意：勿用展示用 apr_decimal（已含 BFUSD/总资产 倍数），否则再乘 BFUSD 会二次缩小日化收益。
     *
     * @param array<string, mixed>|null $accountRow
     * @param null|array{total_asset: float, bfusd_balance_wallet: float, multiplier: float, avg_apr: float, apr_decimal: float, percent: float} $aprCtx
     */
    private function formatEstimatedPaybackDays(int $accountId, ?array $accountRow, ?array $aprCtx): string
    {
        if ($accountRow === null || $aprCtx === null) {
            return '—';
        }

        $debtRaw = $accountRow['portfolio_total_debt_usd'] ?? null;
        if ($debtRaw === null || $debtRaw === '') {
            return '—';
        }
        $totalDebt = (float)$debtRaw;
        $totalRewarded = $this->sumTotalRewardQty($accountId);

        if ($totalRewarded - $totalDebt >= 0) {
            return '--';
        }

        $bfusdWallet = (float)($aprCtx['bfusd_balance_wallet'] ?? 0);
        if ($bfusdWallet <= 0) {
            return '—';
        }

        $dailyIncome = ($aprCtx['avg_apr'] / 365) * $bfusdWallet;
        if ($dailyIncome <= 0) {
            return '—';
        }

        $days = ($totalDebt - $totalRewarded) / $dailyIncome;

        return $this->trimDecimal($days, 2) . ' 天';
    }

    private function sumTotalRewardQty(int $accountId): float
    {
        $sum = $this->scarbDb()
            ->name('earn_reward_records')
            ->where('exchange_account_id', $accountId)
            ->sum('reward_qty');

        return (float)($sum ?? 0);
    }

    /** UNIFIED + BFUSD 单条 balance_wallet（按 updated_at、id 取最新一条，不求和） */
    private function unifiedBfusdBalanceWallet(int $accountId): float
    {
        $row = $this->scarbDb()
            ->name('account_asset')
            ->where('exchange_account_id', $accountId)
            ->where('wallet_type', self::WALLET_TYPE_UNIFIED)
            ->where('asset', 'BFUSD')
            ->field('balance_wallet')
            ->order('updated_at', 'desc')
            ->order('id', 'desc')
            ->find();

        if (empty($row)) {
            return 0.0;
        }

        return (float)($row['balance_wallet'] ?? 0);
    }

    /**
     * @param list<string> $assets
     */
    private function sumUnifiedWalletBalance(int $accountId, array $assets = self::WALLET_ASSETS): float
    {
        if ($assets === []) {
            return 0.0;
        }

        $rows = $this->scarbDb()
            ->name('account_asset')
            ->where('exchange_account_id', $accountId)
            ->where('wallet_type', self::WALLET_TYPE_UNIFIED)
            ->whereIn('asset', $assets)
            ->field('asset, SUM(balance_wallet) AS balance_wallet_sum')
            ->group('asset')
            ->select()
            ->toArray();

        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float)($row['balance_wallet_sum'] ?? 0);
        }

        return $sum;
    }

    /**
     * 待办：换仓 INIT；兑换 INIT 或 PENDING（待后台确认）
     *
     * @return list<array{text: string, href: string}>
     */
    private function loadDashboardPendingAlerts(): array
    {
        $alerts = [];

        $rebalanceRows = $this->scarbDb()
            ->name('rebalance_close_review')
            ->where('status', 'INIT')
            ->field('id, exchange_account_id, asset, symbol')
            ->order('id', 'desc')
            ->limit(50)
            ->select()
            ->toArray();

        foreach ($rebalanceRows as $row) {
            $id      = (int)($row['id'] ?? 0);
            $account = (int)($row['exchange_account_id'] ?? 0);
            $asset   = (string)($row['asset'] ?? '');
            $symbol  = (string)($row['symbol'] ?? '');
            if ($id < 1) {
                continue;
            }
            $alerts[] = [
                'text' => sprintf(
                    '换仓待审核 #%d（账号 %d · %s · %s）',
                    $id,
                    $account,
                    $asset !== '' ? $asset : '-',
                    $symbol !== '' ? $symbol : '-'
                ),
                'href' => '/admin.html#/scarb/rebalance_close_review/index.html?id=' . $id,
            ];
        }

        $convertRows = $this->scarbDb()
            ->name('account_asset_converts_records')
            ->whereIn('status', ['INIT', 'PENDING'])
            ->field('id, exchange_account_id, from_asset, to_asset')
            ->order('id', 'desc')
            ->limit(50)
            ->select()
            ->toArray();

        foreach ($convertRows as $row) {
            $id      = (int)($row['id'] ?? 0);
            $account = (int)($row['exchange_account_id'] ?? 0);
            $from    = (string)($row['from_asset'] ?? '');
            $to      = (string)($row['to_asset'] ?? '');
            if ($id < 1) {
                continue;
            }
            $alerts[] = [
                'text' => sprintf(
                    '兑换待确认 #%d（账号 %d · %s → %s）',
                    $id,
                    $account,
                    $from !== '' ? $from : '-',
                    $to !== '' ? $to : '-'
                ),
                'href' => '/admin.html#/scarb/account_asset_converts/index.html?id=' . $id,
            ];
        }

        return $alerts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadDashboardAccountTabs(): array
    {
        return $this->scarbDb()
            ->name('account_exchange')
            ->field('id,account_name,platform')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * @param list<array<string, mixed>> $tabs
     */
    private function resolveSelectedAccountId(array $get, array $tabs): ?int
    {
        if ($tabs === []) {
            return null;
        }

        $valid = [];
        foreach ($tabs as $tab) {
            $id = (int)($tab['id'] ?? 0);
            if ($id > 0) {
                $valid[$id] = $id;
            }
        }

        $picked = $this->sanitizeId($get['exchange_account_id'] ?? null);
        if ($picked !== null && isset($valid[$picked])) {
            return $picked;
        }

        return (int)reset($valid);
    }

    private function commissionSummaryFilteredQuery(?int $accountId): Query
    {
        $query = $this->scarbDb()->name('orders');
        if ($accountId !== null && $accountId > 0) {
            $query->where('exchange_account_id', $accountId);
        }

        return $query;
    }

    /**
     * orders 按 exchange_account_id + commission_asset 汇总手续费（分页）
     *
     * @param array<string, mixed> $get
     */
    private function paginateCommissionSummary(array $get, ?int $accountId, int $pageSize): Paginator
    {
        if ($accountId === null || $accountId < 1) {
            return Paginator::make([], $pageSize, 1, 0, false, [
                'path'  => '/admin.html#/scarb/dashboard/index.html',
                'query' => $get,
            ]);
        }

        $get['exchange_account_id'] = $accountId;

        $rows = $this->commissionSummaryFilteredQuery($accountId)
            ->field('exchange_account_id, MAX(platform) AS platform, commission_asset, COUNT(*) AS order_count, COALESCE(SUM(commission), 0) AS commission_sum')
            ->group('exchange_account_id, commission_asset')
            ->having('COALESCE(SUM(commission), 0) > 0')
            ->order('exchange_account_id', 'asc')
            ->order('commission_asset', 'asc')
            ->select()
            ->toArray();

        $accountIds = [];
        foreach ($rows as &$row) {
            $aid = (int)($row['exchange_account_id'] ?? 0);
            if ($aid > 0) {
                $accountIds[$aid] = $aid;
            }
            $asset = $row['commission_asset'] ?? null;
            $row['commission_asset'] = ($asset === null || $asset === '') ? '-' : (string)$asset;
            $row['commission_sum'] = (float)($row['commission_sum'] ?? 0);
            $row['commission_sum_fmt'] = $this->trimDecimal($row['commission_sum'], 8);
            $row['order_count'] = (int)($row['order_count'] ?? 0);
        }
        unset($row);

        $names = [];
        if ($accountIds !== []) {
            $names = $this->scarbDb()
                ->name('account_exchange')
                ->whereIn('id', array_values($accountIds))
                ->column('account_name', 'id');
        }
        foreach ($rows as &$row) {
            $aid = (int)($row['exchange_account_id'] ?? 0);
            $row['account_name'] = (string)($names[$aid] ?? '-');
            $row['platform'] = (string)($row['platform'] ?? '');
        }
        unset($row);

        $page  = isset($get['page']) ? max(1, intval($get['page'])) : 1;
        $total = count($rows);
        $slice = array_slice($rows, ($page - 1) * $pageSize, $pageSize);

        return Paginator::make($slice, $pageSize, $page, $total, false, [
            'path'  => '/admin.html#/scarb/dashboard/index.html',
            'query' => $get,
        ]);
    }

    /**
     * 本页手续费：按账号汇总，各账号内再按 commission_asset 拆分
     *
     * @param list<array<string, mixed>> $commissionList
     * @return list<array<string, mixed>>
     */
    private function buildCommissionTotalsByAccount(array $commissionList): array
    {
        /** @var array<int, array<string, mixed>> $grouped */
        $grouped = [];
        foreach ($commissionList as $row) {
            $aid = (int)($row['exchange_account_id'] ?? 0);
            if ($aid < 1) {
                continue;
            }
            $asset = (string)($row['commission_asset'] ?? '-');
            if (!isset($grouped[$aid])) {
                $grouped[$aid] = [
                    'exchange_account_id' => $aid,
                    'account_name'        => (string)($row['account_name'] ?? '-'),
                    'by_asset'            => [],
                ];
            }
            if (!isset($grouped[$aid]['by_asset'][$asset])) {
                $grouped[$aid]['by_asset'][$asset] = 0.0;
            }
            $grouped[$aid]['by_asset'][$asset] += (float)($row['commission_sum'] ?? 0);
        }

        if ($grouped === []) {
            return [];
        }

        ksort($grouped);
        $out = [];
        foreach ($grouped as $item) {
            $parts = [];
            ksort($item['by_asset']);
            foreach ($item['by_asset'] as $asset => $sum) {
                $parts[] = $asset . ' ' . $this->trimDecimal($sum, 8);
            }
            $item['detail_text'] = implode('，', $parts);
            unset($item['by_asset']);
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadUnifiedWalletSummary(int $accountId): array
    {
        $rows = $this->scarbDb()
            ->name('account_asset')
            ->where('exchange_account_id', $accountId)
            ->where('wallet_type', self::WALLET_TYPE_UNIFIED)
            ->whereIn('asset', self::WALLET_ASSETS)
            ->field('exchange_account_id, platform, asset, SUM(balance_wallet) AS balance_wallet_sum, SUM(balance_net) AS balance_net_sum')
            ->group('exchange_account_id, platform, asset')
            ->order('exchange_account_id', 'asc')
            ->select()
            ->toArray();

        $grouped = [];
        foreach ($rows as $row) {
            $aid = (int)($row['exchange_account_id'] ?? 0);
            if ($aid < 1) {
                continue;
            }
            if (!isset($grouped[$aid])) {
                $grouped[$aid] = [
                    'exchange_account_id' => $aid,
                    'platform'            => (string)($row['platform'] ?? ''),
                    'usdt_fmt'            => '0',
                    'usdc_fmt'            => '0',
                    'bfusd_fmt'           => '0',
                    'usdt_net_fmt'        => '-',
                    'usdc_net_fmt'        => '-',
                ];
            }
            $asset = strtoupper((string)($row['asset'] ?? ''));
            $wallet = $this->trimDecimal($row['balance_wallet_sum'] ?? 0, 8);
            $net    = $row['balance_net_sum'] ?? null;
            $netFmt = $net === null ? '-' : $this->trimDecimal($net, 8);
            if ($asset === 'USDT') {
                $grouped[$aid]['usdt_fmt'] = $wallet;
                $grouped[$aid]['usdt_net_fmt'] = $netFmt;
            } elseif ($asset === 'USDC') {
                $grouped[$aid]['usdc_fmt'] = $wallet;
                $grouped[$aid]['usdc_net_fmt'] = $netFmt;
            } elseif ($asset === 'BFUSD') {
                $grouped[$aid]['bfusd_fmt'] = $wallet;
            }
        }

        if ($grouped === []) {
            return [];
        }

        $names = $this->scarbDb()
            ->name('account_exchange')
            ->whereIn('id', array_keys($grouped))
            ->column('account_name', 'id');

        $out = [];
        foreach ($grouped as $aid => $item) {
            $item['account_name'] = (string)($names[$aid] ?? '-');
            $out[] = $item;
        }

        return $out;
    }

    /**
     * 首页右侧：OPEN 持仓按标的 base 分组（去掉 USDT/USDC 后缀），组内 LONG 在上、SHORT 在下
     *
     * @return list<array{symbol: string, asset: string, platform: string, long_rows: list<array<string, mixed>>, short_rows: list<array<string, mixed>>}>
     */
    private function loadOpenPositionsBySymbol(int $accountId): array
    {
        $rows = $this->scarbDb()
            ->name('positions')
            ->where('exchange_account_id', $accountId)
            ->where('status', 'OPEN')
            ->order('symbol', 'asc')
            ->order('position_side', 'asc')
            ->order('updated_at', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        /** @var array<string, array{symbol: string, asset: string, platform: string, long_rows: list<array<string, mixed>>, short_rows: list<array<string, mixed>>}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $symbol = (string)($row['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }
            $asset = $this->baseAssetFromUMSymbol($symbol);
            if ($asset === '') {
                continue;
            }
            if (!isset($grouped[$asset])) {
                $grouped[$asset] = [
                    'asset'       => $asset,
                    'symbol'      => $asset,
                    'platform'    => (string)($row['platform'] ?? ''),
                    'long_rows'   => [],
                    'short_rows'  => [],
                ];
            }
            $pnlRatioRaw = $row['pnl_ratio'] ?? null;
            if ($pnlRatioRaw === null || $pnlRatioRaw === '') {
                $pnlRatioFmt = '-';
            } else {
                $pnlRatioFmt = $this->trimDecimal((float)$pnlRatioRaw * 100, 4) . ' %';
            }
            $liqRaw = $row['liquidation_price'] ?? null;
            if ($liqRaw === null || $liqRaw === '' || (float)$liqRaw == 0.0) {
                $liqFmt = '-';
            } else {
                $liqFmt = $this->trimDecimal($liqRaw, 8);
            }
            $item = [
                'platform'            => (string)($row['platform'] ?? ''),
                'symbol'              => $symbol,
                'position_side'       => (string)($row['position_side'] ?? ''),
                'qty_fmt'             => $this->trimDecimal($row['qty'] ?? 0, 8),
                'status'              => (string)($row['status'] ?? 'OPEN'),
                'unrealized_pnl_fmt'  => $this->trimDecimal($row['unrealized_pnl'] ?? 0, 8),
                'pnl_ratio_fmt'       => $pnlRatioFmt,
                'leverage'            => $row['leverage'] ?? '-',
                'entry_price_fmt'     => $this->trimDecimal($row['entry_price'] ?? null, 8) ?: '-',
                'liquidation_price_fmt' => $liqFmt,
                'last_sync_at'        => $this->formatDatetime($row['last_sync_at'] ?? null),
            ];
            $side = strtoupper((string)($row['position_side'] ?? ''));
            if ($side === 'SHORT') {
                $grouped[$asset]['short_rows'][] = $item;
            } else {
                $grouped[$asset]['long_rows'][] = $item;
            }
        }

        $out = array_values($grouped);
        usort($out, static fn (array $a, array $b): int => strcmp($a['asset'], $b['asset']));

        return $out;
    }

    /**
     * 按账号 + 奖励币种汇总 reward_qty（全表累计，无分页）
     *
     * @return list<array<string, mixed>>
     */
    private function loadEarnRewardQtySummary(int $accountId): array
    {
        $rows = $this->scarbDb()
            ->name('earn_reward_records')
            ->where('exchange_account_id', $accountId)
            ->field('exchange_account_id, reward_asset, SUM(reward_qty) AS reward_qty_sum')
            ->group('exchange_account_id, reward_asset')
            ->order('exchange_account_id', 'asc')
            ->order('reward_asset', 'asc')
            ->select()
            ->toArray();

        if ($rows === []) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $aid = (int)($row['exchange_account_id'] ?? 0);
            if ($aid < 1) {
                continue;
            }
            $out[] = [
                'exchange_account_id' => $aid,
                'reward_asset'        => (string)($row['reward_asset'] ?? ''),
                'reward_qty_fmt'      => $this->trimDecimal($row['reward_qty_sum'] ?? 0, 8),
            ];
        }

        return $out;
    }

    /**
     * 近 7 日（含今日）按币种拆分：每种 reward_asset 独立柱状图 + 7 日累计数量
     *
     * @return list<array{reward_asset: string, cumulative_7d_fmt: string, labels: list<string>, series: list<array<string, mixed>>}>
     */
    private function buildEarnRewardCharts7d(int $accountId): array
    {
        $dayKeys = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayKeys[] = date('Y-m-d', strtotime("-{$i} days"));
        }

        $labels = [];
        foreach ($dayKeys as $day) {
            $labels[] = date('m-d', strtotime($day));
        }

        $start = $dayKeys[0] . ' 00:00:00';
        $end   = $dayKeys[array_key_last($dayKeys)] . ' 23:59:59';

        $rows = $this->scarbDb()
            ->name('earn_reward_records')
            ->where('exchange_account_id', $accountId)
            ->whereBetween('reward_time', [$start, $end])
            ->field('DATE(reward_time) AS reward_day, reward_asset, SUM(reward_qty) AS qty_sum')
            ->group('reward_day, reward_asset')
            ->select()
            ->toArray();

        $byDayAsset = [];
        $assets     = [];
        foreach ($rows as $row) {
            $day   = (string)($row['reward_day'] ?? '');
            $asset = (string)($row['reward_asset'] ?? '');
            if ($day === '' || $asset === '') {
                continue;
            }
            $assets[$asset] = true;
            $byDayAsset[$day][$asset] = (float)($row['qty_sum'] ?? 0);
        }

        foreach ($this->loadEarnRewardQtySummary($accountId) as $summary) {
            $asset = (string)($summary['reward_asset'] ?? '');
            if ($asset !== '') {
                $assets[$asset] = true;
            }
        }

        $assetList = array_keys($assets);
        sort($assetList);

        if ($assetList === []) {
            return [[
                'reward_asset'      => '—',
                'cumulative_7d_fmt' => '0',
                'labels'            => $labels,
                'series'            => [[
                    'name' => '—',
                    'type' => 'bar',
                    'data' => array_fill(0, count($dayKeys), 0),
                ]],
            ]];
        }

        $blocks = [];
        foreach ($assetList as $asset) {
            $data = [];
            $sum  = 0.0;
            foreach ($dayKeys as $day) {
                $qty = (float)($byDayAsset[$day][$asset] ?? 0);
                $data[] = $qty;
                $sum += $qty;
            }
            $blocks[] = [
                'reward_asset'      => $asset,
                'cumulative_7d_fmt' => $this->trimDecimal($sum, 8),
                'labels'            => $labels,
                'series'            => [[
                    'name' => $asset,
                    'type' => 'bar',
                    'data' => $data,
                ]],
            ];
        }

        return $blocks;
    }
}
