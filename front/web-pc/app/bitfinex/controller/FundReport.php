<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\const\Enum;
use app\bitfinex\trait\RecordViewTrait;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\db\Query;
use think\facade\Db;

/**
 * 3.6.5 流水与明细报表（只读 + CSV 导出）
 */
class FundReport extends Controller
{
    use SafeQueryTrait;
    use RecordViewTrait;

    private const REPORT_WALLET_FLOW      = 'wallet_flow';
    private const REPORT_SUBSCRIPTION     = 'subscription';
    private const REPORT_REDEMPTION       = 'redemption';
    private const REPORT_ROLLOVER         = 'rollover';
    private const REPORT_FUNDING_TRADE    = 'funding_trade';
    private const REPORT_FUNDING_ORDER    = 'funding_order';
    private const REPORT_LEDGER           = 'ledger';
    private const REPORT_FLOATING_YIELD   = 'floating_yield';
    private const REPORT_GUARANTEED_YIELD = 'guaranteed_yield';

    /**
     * @return array<string, string>
     */
    private static function reportTabs(): array
    {
        return [
            self::REPORT_WALLET_FLOW      => '客户资金流水',
            self::REPORT_SUBSCRIPTION     => '申购明细',
            self::REPORT_REDEMPTION       => '赎回明细',
            self::REPORT_ROLLOVER         => '续购明细',
            self::REPORT_FUNDING_TRADE    => '贷款成交明细',
            self::REPORT_FUNDING_ORDER    => '挂单记录',
            self::REPORT_LEDGER           => '产品池流水',
            self::REPORT_FLOATING_YIELD   => '浮动型收益汇总',
            self::REPORT_GUARANTEED_YIELD => '保底型收益汇总',
        ];
    }

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '流水与明细报表';
        $get         = $this->request->get();
        $report      = $this->resolveReportType($get['report'] ?? '');

        $query = $this->buildReportQuery($report, $get);
        $result = $query->paginate([
            'list_rows' => isset($get['pageSize'])
                ? min(100, max(10, intval($get['pageSize'])))
                : 20,
            'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/bitfinex/fund_report/index.html',
        ], false);

        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatReportRow($report, $row);
        }
        unset($row);

        $this->assign([
            'report'       => $report,
            'report_tabs'  => self::reportTabs(),
            'list'         => $list,
            'pagehtml'     => $result->render(),
            'get'          => $get,
            'products'     => $this->loadProductOptions(),
            'flow_types'   => Enum::getFundWalletFlowTypeLabels(),
            'sub_statuses' => Enum::getAllFundSubscriptionStatuses(),
            'red_statuses' => Enum::getFundRedemptionStatusLabels(),
            'red_types'    => Enum::getFundRedemptionTypeLabels(),
            'order_statuses' => Enum::getAllFundBitfinexFundingOrderStatuses(),
            'symbols'      => Enum::getAllFundingSymbols(),
            'export_url'   => (string)url('export'),
        ]);

        return $this->fetch();
    }

    /**
     * @auth true
     */
    public function export()
    {
        $get    = $this->request->get();
        $report = $this->resolveReportType($get['report'] ?? '');

        [$start, $end] = $this->resolveExportDateRange($get);
        if (empty($get['date_range'])) {
            $get['date_range'] = "{$start} - {$end}";
        }

        $rangeErr = $this->validateExportDateRange($start, $end);
        if ($rangeErr !== null) {
            return $this->error($rangeErr);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $tabs     = self::reportTabs();
        $filename = $this->buildCsvExportFilename($tabs[$report] ?? '流水与明细报表', $start, $end);
        $this->sendCsvDownloadHeaders($filename);

        $fp = fopen('php://output', 'w');
        if ($fp === false) {
            return $this->error('无法创建导出流');
        }
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, $this->exportHeaders($report), ',', '"', '');

        $query = $this->buildReportQuery($report, $get, false);
        foreach ($query->cursor() as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $row = $this->formatReportRow($report, $row);
            fputcsv($fp, $this->exportRow($report, $row), ',', '"', '');
        }

        fclose($fp);
        exit;
    }

    /**
     * @param array<string, mixed> $get
     */
    private function resolveReportType(string $report): string
    {
        $tabs = self::reportTabs();
        return isset($tabs[$report]) ? $report : self::REPORT_WALLET_FLOW;
    }

    /**
     * @param array<string, mixed> $get
     */
    private function buildReportQuery(string $report, array $get, bool $defaultLast30Days = true): Query
    {
        $db = Db::connect('db_bitfinex');

        switch ($report) {
            case self::REPORT_SUBSCRIPTION:
                /** @var Query $query */
                $query = $db->name('fund_client_product_subscription')
                    ->alias('s')
                    ->leftJoin('fund_client c', 'c.id = s.client_id')
                    ->leftJoin('fund_products p', 'p.id = s.product_id')
                    ->field('s.*, c.name AS client_name, p.product_name, p.product_type AS product_type');
                $this->applySubscriptionFilters($query, $get, $defaultLast30Days);
                return $query->order('s.subscribe_date', 'desc')->order('s.id', 'desc');

            case self::REPORT_REDEMPTION:
                /** @var Query $query */
                $query = $db->name('fund_client_product_redemption')
                    ->alias('r')
                    ->leftJoin('fund_client c', 'c.id = r.client_id')
                    ->leftJoin('fund_products p', 'p.id = r.product_id')
                    ->field('r.*, c.name AS client_name, p.product_name, p.product_type AS product_type');
                $this->applyRedemptionFilters($query, $get, $defaultLast30Days);
                return $query->order('r.requested_at', 'desc')->order('r.id', 'desc');

            case self::REPORT_ROLLOVER:
                /** @var Query $query */
                $query = $db->name('fund_client_product_subscription_rollover')
                    ->alias('r')
                    ->leftJoin('fund_client c', 'c.id = r.client_id')
                    ->leftJoin('fund_products p', 'p.id = r.product_id')
                    ->field('r.*, c.name AS client_name, p.product_name, p.product_type AS product_type, p.term');
                $this->applyRolloverFilters($query, $get, $defaultLast30Days);
                return $query->order('r.rollover_date', 'desc')->order('r.id', 'desc');

            case self::REPORT_FUNDING_TRADE:
                $query = $db->name('fund_bitfinex_funding_trades')
                    ->alias('t')
                    ->leftJoin('fund_products p', 'p.id = t.product_id')
                    ->field('t.*, p.product_name');
                $this->applyProductDateFilters($query, $get, 't.created_at', 't.product_id', $defaultLast30Days);
                if (!empty($get['symbol'])) {
                    $symbol = $this->sanitizeEnum($get['symbol'], Enum::getAllFundingSymbols());
                    if ($symbol) {
                        $query->where('t.symbol', $symbol);
                    }
                }
                return $query->order('t.created_at', 'desc')->order('t.id', 'desc');

            case self::REPORT_FUNDING_ORDER:
                $query = $db->name('fund_bitfinex_funding_orders')
                    ->alias('o')
                    ->leftJoin('fund_products p', 'p.id = o.product_id')
                    ->field('o.*, p.product_name');
                $this->applyProductDateFilters($query, $get, 'o.created_at', 'o.product_id', $defaultLast30Days);
                if (!empty($get['status'])) {
                    $status = $this->sanitizeEnum($get['status'], Enum::getAllFundBitfinexFundingOrderStatuses());
                    if ($status) {
                        $query->where('o.status', $status);
                    }
                }
                if (!empty($get['symbol'])) {
                    $symbol = $this->sanitizeEnum($get['symbol'], Enum::getAllFundingSymbols());
                    if ($symbol) {
                        $query->where('o.symbol', $symbol);
                    }
                }
                return $query->order('o.created_at', 'desc')->order('o.id', 'desc');

            case self::REPORT_LEDGER:
                $query = $db->name('fund_bitfinex_ledger_hist')
                    ->alias('l')
                    ->leftJoin('fund_products p', 'p.id = l.product_id')
                    ->field('l.*, p.product_name');
                $this->applyLedgerFilters($query, $get, $defaultLast30Days);
                return $query->order('l.mts', 'desc')->order('l.id', 'desc');

            case self::REPORT_FLOATING_YIELD:
            case self::REPORT_GUARANTEED_YIELD:
                return $this->buildDailyYieldQuery($report, $get, $defaultLast30Days);

            case self::REPORT_WALLET_FLOW:
            default:
                $query = $db->name('fund_client_wallet_flow')
                    ->alias('f')
                    ->leftJoin('fund_client c', 'c.id = f.client_id')
                    ->field('f.*, c.name AS client_name');
                $this->applyWalletFlowFilters($query, $get, $defaultLast30Days);
                return $query->order('f.created_at', 'desc')->order('f.id', 'desc');
        }
    }

    /**
     * @param array<string, mixed> $get
     */
    private function applyWalletFlowFilters(Query $query, array $get, bool $defaultLast30Days): void
    {
        $this->applyDateRangeFilter($query, $get, 'f.created_at', $defaultLast30Days);

        if (!empty($get['client_id'])) {
            $clientId = $this->sanitizeId($get['client_id']);
            if ($clientId) {
                $query->where('f.client_id', $clientId);
            }
        }

        if (!empty($get['flow_type'])) {
            $flowType = $this->sanitizeEnum($get['flow_type'], Enum::getAllFundClientWalletFlowTypes());
            if ($flowType) {
                $query->where('f.flow_type', $flowType);
            }
        }

        if (!empty($get['currency'])) {
            $currency = $this->sanitizeEnum($get['currency'], Enum::getAllFundClientWalletCurrencies());
            if ($currency) {
                $query->where('f.currency', $currency);
            }
        }
    }

    /**
     * @param array<string, mixed> $get
     */
    private function applySubscriptionFilters(Query $query, array $get, bool $defaultLast30Days): void
    {
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween('s.subscribe_date', [$start, $end]);
        } elseif ($defaultLast30Days) {
            $query->where('s.subscribe_date', '>=', date('Y-m-d', strtotime('-30 days')));
        }

        if (!empty($get['client_id'])) {
            $clientId = $this->sanitizeId($get['client_id']);
            if ($clientId) {
                $query->where('s.client_id', $clientId);
            }
        }

        if (!empty($get['product_id'])) {
            $productId = $this->sanitizeId($get['product_id']);
            if ($productId) {
                $query->where('s.product_id', $productId);
            }
        }

        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getAllFundSubscriptionStatuses());
            if ($status) {
                $query->where('s.status', $status);
            }
        }
    }

    /**
     * @param array<string, mixed> $get
     */
    private function applyRedemptionFilters(Query $query, array $get, bool $defaultLast30Days): void
    {
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween('r.requested_at', ["{$start} 00:00:00", "{$end} 23:59:59"]);
        } elseif ($defaultLast30Days) {
            $query->where('r.requested_at', '>=', date('Y-m-d 00:00:00', strtotime('-30 days')));
        }

        if (!empty($get['client_id'])) {
            $clientId = $this->sanitizeId($get['client_id']);
            if ($clientId) {
                $query->where('r.client_id', $clientId);
            }
        }

        if (!empty($get['product_id'])) {
            $productId = $this->sanitizeId($get['product_id']);
            if ($productId) {
                $query->where('r.product_id', $productId);
            }
        }

        if (!empty($get['redemption_type'])) {
            $type = $this->sanitizeEnum($get['redemption_type'], Enum::getAllFundRedemptionTypes());
            if ($type) {
                $query->where('r.redemption_type', $type);
            }
        }

        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getAllFundRedemptionStatuses());
            if ($status) {
                $query->where('r.status', $status);
            }
        }
    }

    /**
     * @param array<string, mixed> $get
     */
    private function applyRolloverFilters(Query $query, array $get, bool $defaultLast30Days): void
    {
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween('r.rollover_date', [$start, $end]);
        } elseif ($defaultLast30Days) {
            $query->where('r.rollover_date', '>=', date('Y-m-d', strtotime('-30 days')));
        }

        if (!empty($get['client_id'])) {
            $clientId = $this->sanitizeId($get['client_id']);
            if ($clientId) {
                $query->where('r.client_id', $clientId);
            }
        }

        if (!empty($get['product_id'])) {
            $productId = $this->sanitizeId($get['product_id']);
            if ($productId) {
                $query->where('r.product_id', $productId);
            }
        }

        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getAllFundSubscriptionStatuses());
            if ($status) {
                $query->where('r.status', $status);
            }
        }
    }

    /**
     * @param array<string, mixed> $get
     */
    private function applyProductDateFilters(
        Query $query,
        array $get,
        string $dateColumn,
        string $productColumn,
        bool $defaultLast30Days
    ): void {
        $this->applyDateRangeFilter($query, $get, $dateColumn, $defaultLast30Days);

        if (!empty($get['product_id'])) {
            $productId = $this->sanitizeId($get['product_id']);
            if ($productId) {
                $query->where($productColumn, $productId);
            }
        }
    }

    /**
     * @param array<string, mixed> $get
     */
    private function applyLedgerFilters(Query $query, array $get, bool $defaultLast30Days): void
    {
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start, $end] = $dates;
            $startMs = strtotime("{$start} 00:00:00") * 1000;
            $endMs   = strtotime("{$end} 23:59:59") * 1000;
            $query->whereBetween('l.mts', [$startMs, $endMs]);
        } elseif ($defaultLast30Days) {
            $query->where('l.mts', '>=', (int)(strtotime('-30 days') * 1000));
        }

        if (!empty($get['product_id'])) {
            $productId = $this->sanitizeId($get['product_id']);
            if ($productId) {
                $query->where('l.product_id', $productId);
            }
        }

        if (!empty($get['wallet'])) {
            $wallet = trim((string)$get['wallet']);
            if (in_array($wallet, ['exchange', 'margin', 'funding'], true)) {
                $query->where('l.wallet', $wallet);
            }
        }
    }

    /**
     * fund_product_daily_report_floating / fund_product_daily_report_guaranteed
     *
     * @param array<string, mixed> $get
     */
    private function buildDailyYieldQuery(string $report, array $get, bool $defaultLast30Days): Query
    {
        $table = $report === self::REPORT_FLOATING_YIELD
            ? 'fund_product_daily_report_floating'
            : 'fund_product_daily_report_guaranteed';

        /** @var Query $query */
        $query = Db::connect('db_bitfinex')->name($table);
        $this->applyDailyReportFilters($query, $get, $defaultLast30Days);

        return $query->order('report_date', 'desc')->order('product_id', 'asc');
    }

    /**
     * @param array<string, mixed> $get
     */
    private function applyDailyReportFilters(Query $query, array $get, bool $defaultLast30Days): void
    {
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween('report_date', [$start, $end]);
        } elseif ($defaultLast30Days) {
            $query->where('report_date', '>=', date('Y-m-d', strtotime('-30 days')));
        }

        if (!empty($get['product_id'])) {
            $productId = $this->sanitizeId($get['product_id']);
            if ($productId) {
                $query->where('product_id', $productId);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatReportRow(string $report, array $row): array
    {
        switch ($report) {
            case self::REPORT_SUBSCRIPTION:
                $row['subscribe_amount_fmt'] = $this->trimDecimal($row['subscribe_amount'] ?? 0, 8);
                $row['floating_shares_fmt']  = $this->trimDecimal($row['floating_shares'] ?? 0, 10);
                $row['redeemable_amount_fmt']= $this->trimDecimal($row['redeemable_amount'] ?? 0, 8);
                $row['guaranteed_rate_fmt']  = $this->formatRatePercent($row['guaranteed_rate_snapshot'] ?? 0);
                $row['nav_at_subscribe_fmt'] = $this->trimDecimal($row['floating_nav_at_subscribe'] ?? 0, 10);
                return $row;

            case self::REPORT_REDEMPTION:
                $typeLabels   = Enum::getFundRedemptionTypeLabels();
                $statusLabels = Enum::getFundRedemptionStatusLabels();
                $type         = (string)($row['redemption_type'] ?? '');
                $status       = (string)($row['status'] ?? '');
                $row['redemption_type_label'] = $typeLabels[$type] ?? ($type !== '' ? $type : '-');
                $row['status_label']          = $statusLabels[$status] ?? ($status !== '' ? $status : '-');
                $row['redemption_amount_fmt'] = $this->trimDecimal($row['redemption_amount'] ?? 0, 8);
                $row['floating_request_shares_fmt'] = $this->trimDecimal($row['floating_request_shares'] ?? 0, 10);
                $row['floating_nav_per_share_fmt']  = $this->trimDecimal($row['floating_nav_per_share'] ?? 0, 10);
                $row['guaranteed_annual_rate_fmt']  = $this->formatRatePercent($row['guaranteed_annual_rate'] ?? 0);
                $row['requested_at']          = $this->formatDatetime($row['requested_at'] ?? null);
                $row['settled_at']            = $this->formatDatetime($row['settled_at'] ?? null);
                return $row;

            case self::REPORT_ROLLOVER:
                $row['subscribe_amount_fmt']  = $this->trimDecimal($row['subscribe_amount'] ?? 0, 8);
                $row['floating_shares_fmt']   = $this->trimDecimal($row['floating_shares'] ?? 0, 10);
                $row['redeemable_amount_fmt'] = $this->trimDecimal($row['redeemable_amount'] ?? 0, 8);
                $row['current_interest_fmt']  = $this->trimDecimal($row['current_interest'] ?? 0, 8);
                $row['guaranteed_rate_fmt']   = $this->formatRatePercent($row['guaranteed_rate_snapshot'] ?? 0);
                $row['created_at']            = $this->formatDatetime($row['created_at'] ?? null);
                return $row;

            case self::REPORT_FUNDING_TRADE:
                $row['amount_fmt']             = $this->trimDecimal($row['amount'] ?? 0, 8);
                $row['rate_fmt']               = $this->trimDecimal($row['rate'] ?? 0, 10);
                $row['annual_yield_rate_fmt']  = $this->formatRatePercent($row['annual_yield_rate'] ?? 0);
                $row['mts_create_fmt']         = $this->formatTsMs($row['mts_create'] ?? 0);
                $row['created_at']             = $this->formatDatetime($row['created_at'] ?? null);
                return $row;

            case self::REPORT_FUNDING_ORDER:
                $row['amount_usd_fmt']   = $this->trimDecimal($row['amount_usd'] ?? 0, 8);
                $row['rate_fmt']         = $this->trimDecimal($row['rate'] ?? 0, 10);
                $row['created_at']       = $this->formatDatetime($row['created_at'] ?? null);
                $row['updated_at']       = $this->formatDatetime($row['updated_at'] ?? null);
                return $row;

            case self::REPORT_LEDGER:
                $row['amount_fmt']         = $this->trimDecimal($row['amount'] ?? 0, 10);
                $row['balance_after_fmt']  = $this->trimDecimal($row['balance_after'] ?? 0, 10);
                $row['mts_fmt']            = $this->formatTsMs($row['mts'] ?? 0);
                $row['created_at']         = $this->formatDatetime($row['created_at'] ?? null);
                return $row;

            case self::REPORT_FLOATING_YIELD:
                return $this->formatDailyYieldRow($row, true);

            case self::REPORT_GUARANTEED_YIELD:
                return $this->formatDailyYieldRow($row, false);

            case self::REPORT_WALLET_FLOW:
            default:
                $labels = Enum::getFundWalletFlowTypeLabels();
                $type   = (string)($row['flow_type'] ?? '');
                $row['flow_type_label']   = $labels[$type] ?? ($type !== '' ? $type : '-');
                $row['amount_fmt']        = $this->trimDecimal(abs((float)($row['amount'] ?? 0)), 8);
                $row['balance_after_fmt'] = $this->trimDecimal($row['balance_after'] ?? 0, 8);
                $row['created_at']        = $this->formatDatetime($row['created_at'] ?? null);
                return $row;
        }
    }

    /**
     * @return array<int, string>
     */
    private function exportHeaders(string $report): array
    {
        switch ($report) {
            case self::REPORT_SUBSCRIPTION:
                return [
                    'subscription_id', 'client_id', 'client_name', 'product_id', 'product_name', 'product_type',
                    'principal', 'shares', 'nav_at_subscribe', 'guaranteed_rate', 'status',
                    'subscribe_date', 'start_interest_date', 'remaining_date', 'redeemable_amount',
                ];
            case self::REPORT_REDEMPTION:
                return [
                    'redemption_id', 'subscription_id', 'client_id', 'client_name', 'product_id', 'product_name',
                    'product_type', 'redemption_type', 'redemption_amount', 'status', 'due_date',
                    'requested_at', 'settled_at', 'remark',
                ];
            case self::REPORT_ROLLOVER:
                return [
                    'rollover_id', 'subscription_id', 'client_id', 'client_name', 'product_id', 'product_name',
                    'product_type', 'term', 'currency', 'subscribe_amount', 'guaranteed_rate', 'status',
                    'rollover_date', 'prev_remaining_date', 'end_interest_date', 'remaining_date',
                    'start_interest_date', 'remark',
                ];
            case self::REPORT_FUNDING_TRADE:
                return [
                    'id', 'product_id', 'product_name', 'trade_id', 'symbol', 'amount', 'rate',
                    'period_days', 'annual_yield_rate', 'mts_create', 'created_at',
                ];
            case self::REPORT_FUNDING_ORDER:
                return [
                    'id', 'product_id', 'product_name', 'symbol', 'order_id', 'amount_usd', 'rate',
                    'period_days', 'status', 'layer_code', 'created_at',
                ];
            case self::REPORT_LEDGER:
                return [
                    'id', 'product_id', 'product_name', 'bfx_ledger_id', 'currency', 'wallet', 'mts',
                    'amount', 'balance_after', 'description', 'cash_available_applied',
                ];
            case self::REPORT_FLOATING_YIELD:
                return [
                    'report_date', 'product_id', 'product_name', 'pool_id', 'nav_per_share', 'nav_daily_change',
                    'daily_yield_pct', 'daily_net_inflow', 'daily_net_interest', 'total_nav', 'total_shares',
                    'cash_lending', 'cash_pending', 'daily_capital_utilization_pct', 'daily_lending_rate',
                ];
            case self::REPORT_GUARANTEED_YIELD:
                return [
                    'report_date', 'product_id', 'product_name', 'pool_id', 'customer_liability_total',
                    'cash_available', 'surplus_gap', 'daily_yield_pct', 'daily_net_inflow', 'daily_net_interest',
                    'cash_lending', 'cash_pending', 'daily_capital_utilization_pct', 'daily_lending_rate',
                ];
            case self::REPORT_WALLET_FLOW:
            default:
                return [
                    'created_at', 'client_id', 'client_name', 'flow_type', 'currency', 'amount',
                    'balance_after', 'operator', 'remark',
                ];
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, scalar|null>
     */
    private function exportRow(string $report, array $row): array
    {
        switch ($report) {
            case self::REPORT_SUBSCRIPTION:
                return [
                    $row['id'] ?? '',
                    $row['client_id'] ?? '',
                    $row['client_name'] ?? '',
                    $row['product_id'] ?? '',
                    $row['product_name'] ?? '',
                    $row['product_type'] ?? '',
                    $row['subscribe_amount_fmt'] ?? '',
                    $row['floating_shares_fmt'] ?? '',
                    $row['nav_at_subscribe_fmt'] ?? '',
                    $row['guaranteed_rate_fmt'] ?? '',
                    $row['status'] ?? '',
                    $row['subscribe_date'] ?? '',
                    $row['start_interest_date'] ?? '',
                    $row['remaining_date'] ?? '',
                    $row['redeemable_amount_fmt'] ?? '',
                ];
            case self::REPORT_REDEMPTION:
                return [
                    $row['id'] ?? '',
                    $row['subscription_id'] ?? '',
                    $row['client_id'] ?? '',
                    $row['client_name'] ?? '',
                    $row['product_id'] ?? '',
                    $row['product_name'] ?? '',
                    $row['product_type'] ?? '',
                    $row['redemption_type_label'] ?? '',
                    $row['redemption_amount_fmt'] ?? '',
                    $row['status_label'] ?? '',
                    $row['due_date'] ?? '',
                    $row['requested_at'] ?? '',
                    $row['settled_at'] ?? '',
                    $row['remark'] ?? '',
                ];
            case self::REPORT_ROLLOVER:
                return [
                    $row['id'] ?? '',
                    $row['subscription_id'] ?? '',
                    $row['client_id'] ?? '',
                    $row['client_name'] ?? '',
                    $row['product_id'] ?? '',
                    $row['product_name'] ?? '',
                    $row['product_type'] ?? '',
                    $row['term'] ?? '',
                    $row['currency'] ?? '',
                    $row['subscribe_amount_fmt'] ?? '',
                    $row['guaranteed_rate_fmt'] ?? '',
                    $row['status'] ?? '',
                    $row['rollover_date'] ?? '',
                    $row['prev_remaining_date'] ?? '',
                    $row['end_interest_date'] ?? '',
                    $row['remaining_date'] ?? '',
                    $row['start_interest_date'] ?? '',
                    $row['remark'] ?? '',
                ];
            case self::REPORT_FUNDING_TRADE:
                return [
                    $row['id'] ?? '',
                    $row['product_id'] ?? '',
                    $row['product_name'] ?? '',
                    $row['trade_id'] ?? '',
                    $row['symbol'] ?? '',
                    $row['amount_fmt'] ?? '',
                    $row['rate_fmt'] ?? '',
                    $row['period_days'] ?? '',
                    $row['annual_yield_rate_fmt'] ?? '',
                    $row['mts_create_fmt'] ?? '',
                    $row['created_at'] ?? '',
                ];
            case self::REPORT_FUNDING_ORDER:
                return [
                    $row['id'] ?? '',
                    $row['product_id'] ?? '',
                    $row['product_name'] ?? '',
                    $row['symbol'] ?? '',
                    $row['order_id'] ?? '',
                    $row['amount_usd_fmt'] ?? '',
                    $row['rate_fmt'] ?? '',
                    $row['period_days'] ?? '',
                    $row['status'] ?? '',
                    $row['layer_code'] ?? '',
                    $row['created_at'] ?? '',
                ];
            case self::REPORT_LEDGER:
                return [
                    $row['id'] ?? '',
                    $row['product_id'] ?? '',
                    $row['product_name'] ?? '',
                    $row['bfx_ledger_id'] ?? '',
                    $row['currency'] ?? '',
                    $row['wallet'] ?? '',
                    $row['mts_fmt'] ?? '',
                    $row['amount_fmt'] ?? '',
                    $row['balance_after_fmt'] ?? '',
                    $row['description'] ?? '',
                    $row['cash_available_applied'] ?? '',
                ];
            case self::REPORT_FLOATING_YIELD:
                return [
                    $row['report_date'] ?? '',
                    $row['product_id'] ?? '',
                    $row['product_name'] ?? '',
                    $row['pool_id'] ?? '',
                    $row['nav_per_share_fmt'] ?? '',
                    $row['nav_daily_change_fmt'] ?? '',
                    $row['daily_yield_pct_fmt'] ?? '',
                    $row['daily_net_inflow_fmt'] ?? '',
                    $row['daily_net_interest_fmt'] ?? '',
                    $row['total_nav_fmt'] ?? '',
                    $row['total_shares_fmt'] ?? '',
                    $row['cash_lending_fmt'] ?? '',
                    $row['cash_pending_fmt'] ?? '',
                    $row['daily_capital_utilization_pct_fmt'] ?? '',
                    $row['daily_lending_rate_fmt'] ?? '',
                ];
            case self::REPORT_GUARANTEED_YIELD:
                return [
                    $row['report_date'] ?? '',
                    $row['product_id'] ?? '',
                    $row['product_name'] ?? '',
                    $row['pool_id'] ?? '',
                    $row['customer_liability_total_fmt'] ?? '',
                    $row['cash_available_fmt'] ?? '',
                    $row['surplus_gap_fmt'] ?? '',
                    $row['daily_yield_pct_fmt'] ?? '',
                    $row['daily_net_inflow_fmt'] ?? '',
                    $row['daily_net_interest_fmt'] ?? '',
                    $row['cash_lending_fmt'] ?? '',
                    $row['cash_pending_fmt'] ?? '',
                    $row['daily_capital_utilization_pct_fmt'] ?? '',
                    $row['daily_lending_rate_fmt'] ?? '',
                ];
            case self::REPORT_WALLET_FLOW:
            default:
                return [
                    $row['created_at'] ?? '',
                    $row['client_id'] ?? '',
                    $row['client_name'] ?? '',
                    $row['flow_type_label'] ?? '',
                    $row['currency'] ?? '',
                    $row['amount_fmt'] ?? '',
                    $row['balance_after_fmt'] ?? '',
                    $row['operator'] ?? '',
                    $row['remark'] ?? '',
                ];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadProductOptions(): array
    {
        return Db::connect('db_bitfinex')
            ->name('fund_products')
            ->orderRaw("FIELD(product_type, 'GUARANTEED', 'FLOATING'), product_code ASC")
            ->field('id, product_code, product_name, product_type')
            ->select()
            ->toArray();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatDailyYieldRow(array $row, bool $floating): array
    {
        $row['report_date'] = (string)($row['report_date'] ?? '');
        $row['daily_yield_pct_fmt'] = $this->formatStoredPercent($row['daily_yield_pct'] ?? 0);
        $row['daily_net_inflow_fmt'] = $this->trimDecimal($row['daily_net_inflow'] ?? 0, 8);
        $row['daily_net_interest_fmt'] = $this->trimDecimal($row['daily_net_interest'] ?? 0, 8);
        $row['cash_lending_fmt'] = $this->trimDecimal($row['cash_lending'] ?? 0, 8);
        $row['cash_pending_fmt'] = $this->trimDecimal($row['cash_pending'] ?? 0, 8);
        $row['daily_capital_utilization_pct_fmt'] = $this->formatStoredPercent($row['daily_capital_utilization_pct'] ?? 0);
        $row['daily_lending_rate_fmt'] = $this->formatDailyLendingRate($row['daily_lending_rate'] ?? 0);

        if ($floating) {
            $row['nav_per_share_fmt'] = $this->trimDecimal($row['nav_per_share'] ?? 0, 10);
            $row['nav_daily_change_fmt'] = $this->trimDecimal($row['nav_daily_change'] ?? 0, 10);
            $row['total_nav_fmt'] = $this->trimDecimal($row['total_nav'] ?? 0, 8);
            $row['total_shares_fmt'] = $this->trimDecimal($row['total_shares'] ?? 0, 10);
        } else {
            $row['customer_liability_total_fmt'] = $this->trimDecimal($row['customer_liability_total'] ?? 0, 8);
            $row['cash_available_fmt'] = $this->trimDecimal($row['cash_available'] ?? 0, 8);
            $row['surplus_gap_fmt'] = $this->trimDecimal($row['surplus_gap'] ?? 0, 8);
        }

        return $row;
    }

    /**
     * @param mixed $value
     */
    private function formatStoredPercent($value, int $scale = 4): string
    {
        return $this->trimDecimal((float)($value ?? 0), $scale) . '%';
    }

    /**
     * @param mixed $value
     */
    private function formatDailyLendingRate($value): string
    {
        if ($value === null || $value === '') {
            return '0%';
        }

        return $this->trimDecimal((float)$value * 100, 4) . '%';
    }

    /**
     * @param mixed $rate
     */
    private function formatRatePercent($rate): string
    {
        if ($rate === null || $rate === '') {
            return '0%';
        }
        return $this->trimDecimal((float)$rate * 100, 4) . '%';
    }
}
