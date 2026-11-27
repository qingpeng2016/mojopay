<?php

declare (strict_types=1);

namespace app\bitfinex\const;

/**
 * Bitfinex 模块枚举与白名单（供 SafeQueryTrait::sanitizeEnum 使用）
 */
final class Enum
{
    /** accounts.status 当前入库值主要为 Normal；预留扩展便于筛选 */
    public static function getAllAccountStatuses(): array
    {
        return ['Normal', 'Disabled', 'Locked'];
    }

    /** accounts.account_type DDL 默认为 funding */
    public static function getAllAccountTypes(): array
    {
        return ['funding', 'exchange', 'margin'];
    }

    /** accounts.vendor 默认 bitfinex */
    public static function getAllVendors(): array
    {
        return ['bitfinex'];
    }

    /** funding_config_strategy.group_name：3.5.2 利率锚定参数 */
    public const ANCHOR_GROUP_NAME = 'anchor';

    /** bot_schedule_config.module：3.5.5 风控参数 */
    public const BOT_SCHEDULE_MODULE = 'bitfinex';

    /** bot_schedule_config.task_name：借贷余额不足报警 */
    public const BOT_SCHEDULE_TASK_LAYER_LENDING_BUDGET_ALERT = 'layer_lending_budget_alert';

    /** funding_config_strategy.group_name：铺单 / 风控全局参数（风控参数页可改） */
    public const PLACEMENT_GROUP_NAME = 'placement';

    public const FUNDING_RISK_GROUP_NAME = 'risk';

    /** placement.obligation_day_cutoff_hour_utc */
    public const OBLIGATION_DAY_CUTOFF_HOUR_UTC_ITEM_KEY = 'obligation_day_cutoff_hour_utc';

    /** @return string[] */
    public static function getRiskPageFundingConfigGroups(): array
    {
        return [self::PLACEMENT_GROUP_NAME, self::FUNDING_RISK_GROUP_NAME];
    }

    /** 3.5.2 页面不展示的 anchor 项 */
    public static function getAnchorExcludedItemKeys(): array
    {
        return ['funding_book_len', 'vol_short_minutes'];
    }

    /** funding_config_strategy.value_type 白名单 */
    public static function getAllFundingConfigValueTypes(): array
    {
        return ['string', 'int', 'float', 'bool'];
    }

    /** funding_config_layer.symbol */
    public static function getAllFundingSymbols(): array
    {
        return ['fUST', 'fUSD'];
    }

    /** funding_config_layer.layer_code */
    public static function getAllLayerCodes(): array
    {
        return ['conservative', 'balanced', 'aggressive'];
    }

    /** layer_code 展示名 */
    public static function getLayerCodeLabels(): array
    {
        return [
            'conservative' => '保守',
            'balanced'     => '均衡',
            'aggressive'   => '激进',
        ];
    }

    /** funding_lending_position.status 展示顺序 */
    public static function getAllLendingPositionStatuses(): array
    {
        return ['ACTIVE', 'CLOSED (EXPIRED)', 'CLOSED (REDUCED)', 'CLOSED'];
    }

    /** funding_lending_position.lifecycle */
    public static function getAllLendingLifecycles(): array
    {
        return ['lending', 'settled'];
    }

    /** funding_orders.status */
    public static function getAllFundingOrderStatuses(): array
    {
        return ['pending', 'submitted', 'failed', 'closed'];
    }

    /** fund_client.client_type */
    public static function getAllFundClientTypes(): array
    {
        return ['individual', 'institution'];
    }

    /** fund_client.kyc_status */
    public static function getAllFundClientKycStatuses(): array
    {
        return ['pending', 'approved', 'rejected'];
    }

    /** fund_client.status / fund_client_wallet.status */
    public static function getAllFundClientStatuses(): array
    {
        return ['active', 'frozen', 'closed'];
    }

    /** fund_client_wallet.currency（客户钱包仅 USD） */
    public static function getAllFundClientWalletCurrencies(): array
    {
        return ['USD'];
    }

    /** fund_client_wallet_flow.flow_type（后台入出金仅用 DEPOSIT/WITHDRAW） */
    public static function getAllFundClientWalletFlowTypes(): array
    {
        return ['DEPOSIT', 'WITHDRAW', 'SUBSCRIBE_OUT', 'REDEEM_IN', 'ADJUST'];
    }

    public static function getFundWalletFlowTypeLabels(): array
    {
        return [
            'DEPOSIT'       => '入金',
            'WITHDRAW'      => '出金',
            'SUBSCRIBE_OUT' => '申购冻结',
            'REDEEM_IN'     => '赎回入账',
            'ADJUST'        => '调整',
        ];
    }

    /** fund_bitfinex_funding_orders.status */
    public static function getAllFundBitfinexFundingOrderStatuses(): array
    {
        return ['pending', 'submitted', 'failed', 'closed', 'trade'];
    }

    /** fund_product_nav_daily.status / fund_client_subscription_accrual_daily.status */
    public static function getAllFundDailyCalcStatuses(): array
    {
        return ['draft', 'final'];
    }

    /** fund_client_product_subscription.status */
    public static function getAllFundSubscriptionStatuses(): array
    {
        return ['ACTIVE', 'REDEEMED'];
    }

    /** fund_client_product_redemption.redemption_type */
    public static function getAllFundRedemptionTypes(): array
    {
        return ['FULL', 'PARTIAL'];
    }

    /** fund_client_product_redemption.status */
    public static function getAllFundRedemptionStatuses(): array
    {
        return ['scheduled', 'pending', 'nav_pending', 'paid', 'cancelled', 'failed'];
    }

    /** 管理后台允许物理删除的赎回状态（已兑付/结算中不可删） */
    public static function getDeletableFundRedemptionStatuses(): array
    {
        return ['scheduled', 'pending', 'failed', 'cancelled'];
    }

    /** fund_products.product_type */
    public static function getAllFundProductTypes(): array
    {
        return ['GUARANTEED', 'FLOATING'];
    }

    /** fund_products.term */
    public static function getAllFundProductTerms(): array
    {
        return ['FLEXIBLE', 'D7', 'D14', 'D30', 'D60', 'D90', 'D120'];
    }

    /** fund_products.term → term_days */
    public static function getFundProductTermDays(string $term): int
    {
        $map = [
            'FLEXIBLE' => 0,
            'D7'       => 7,
            'D14'      => 14,
            'D30'      => 30,
            'D60'      => 60,
            'D90'      => 90,
            'D120'     => 120,
        ];
        return $map[$term] ?? 0;
    }

    /** fund_products.status */
    public static function getAllFundProductStatuses(): array
    {
        return ['active', 'paused', 'closed'];
    }

    /** fund_bitfinex_sub_accounts.status */
    public static function getAllBitfinexAccountStatuses(): array
    {
        return ['active', 'disabled', 'closed'];
    }

    public static function getBitfinexAccountStatusLabels(): array
    {
        return [
            'active'   => '启用',
            'disabled' => '停用',
            'closed'   => '关闭',
        ];
    }

    public static function getFundProductStatusLabels(): array
    {
        return [
            'active' => '运行中',
            'paused' => '暂停',
            'closed' => '关闭',
        ];
    }

    public static function getFundRedemptionTypeLabels(): array
    {
        return [
            'FULL'    => '全部赎回',
            'PARTIAL' => '部分赎回',
        ];
    }

    public static function getFundRedemptionStatusLabels(): array
    {
        return [
            'scheduled'   => '已预约',
            'pending'     => '待处理',
            'nav_pending' => '待净值',
            'paid'        => '已兑付',
            'cancelled'   => '已取消',
            'failed'      => '失败',
        ];
    }

    public static function getFundClientTypeLabels(): array
    {
        return [
            'individual'  => '个人',
            'institution' => '机构',
        ];
    }

    public static function getFundClientKycLabels(): array
    {
        return [
            'pending'  => '待审核',
            'approved' => '已通过',
            'rejected' => '已拒绝',
        ];
    }

    public static function getFundClientStatusLabels(): array
    {
        return [
            'active' => '正常',
            'frozen' => '冻结',
            'closed' => '关闭',
        ];
    }
}
