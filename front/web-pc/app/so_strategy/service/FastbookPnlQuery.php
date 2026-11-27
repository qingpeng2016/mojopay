<?php
declare(strict_types=1);

namespace app\so_strategy\service;

use think\facade\Db;

/**
 * SO 快盘账号 PNL 基础表查询。
 *
 * 该查询与 alert 服务的 PNL 视图保持相同原子粒度，但 MIS 只读
 * market_config / market_options / trade_records / market_results，不依赖视图权限。
 */
final class FastbookPnlQuery
{
    private const CONNECTION = 'db_fastbook';

    public static function query()
    {
        return Db::connect(self::CONNECTION)->table('(' . self::sql() . ') pnl');
    }

    private static function sql(): string
    {
        return <<<'SQL'
WITH account_trades AS (
    SELECT
        t.market_config_id,
        o.outcome_id,
        t.market_option_id,
        t.buyer_account_id AS account_id,
        t.trade_amount,
        t.trade_quantity,
        'BUY' AS account_side,
        t.buy_fee_amount AS account_fee_amount
    FROM trade_records t
    INNER JOIN market_options o ON o.id = t.market_option_id
    WHERE t.buyer_account_id IS NOT NULL

    UNION ALL

    SELECT
        t.market_config_id,
        o.outcome_id,
        t.market_option_id,
        t.seller_account_id AS account_id,
        t.trade_amount,
        t.trade_quantity,
        'SELL' AS account_side,
        t.sell_fee_amount AS account_fee_amount
    FROM trade_records t
    INNER JOIN market_options o ON o.id = t.market_option_id
    WHERE t.seller_account_id IS NOT NULL
),
trade_cash AS (
    SELECT
        market_config_id,
        outcome_id,
        account_id,
        SUM(CASE WHEN account_side = 'BUY' THEN trade_amount ELSE 0 END) AS buy_trade_amount,
        SUM(CASE WHEN account_side = 'SELL' THEN trade_amount ELSE 0 END) AS sell_trade_amount,
        SUM(account_fee_amount) AS trade_fee_amount,
        SUM(CASE WHEN account_side = 'BUY' THEN trade_amount ELSE -trade_amount END) AS trading_cashflow
    FROM account_trades
    GROUP BY market_config_id, outcome_id, account_id
),
positions AS (
    SELECT
        market_config_id,
        outcome_id,
        market_option_id,
        account_id,
        SUM(CASE WHEN account_side = 'BUY' THEN trade_quantity ELSE -trade_quantity END) AS net_quantity
    FROM account_trades
    GROUP BY market_config_id, outcome_id, market_option_id, account_id
),
result_cash AS (
    SELECT
        p.market_config_id,
        p.outcome_id,
        p.account_id,
        r.result,
        r.settlement_type,
        r.window_start_time,
        r.window_end_time,
        SUM(p.net_quantity) AS net_position_quantity,
        CASE
            WHEN r.settlement_type = 'NORMAL'
                THEN SUM(CASE WHEN o.option_code = r.result THEN p.net_quantity ELSE 0 END)
            ELSE NULL
        END AS settlement_cashflow
    FROM positions p
    INNER JOIN market_options o ON o.id = p.market_option_id
    INNER JOIN market_results r
        ON r.market_config_id = p.market_config_id
       AND r.outcome_id = p.outcome_id
    GROUP BY
        p.market_config_id,
        p.outcome_id,
        p.account_id,
        r.result,
        r.settlement_type,
        r.window_start_time,
        r.window_end_time
)
SELECT
    tc.market_config_id,
    tc.outcome_id,
    m.market_type,
    m.vendor,
    m.market_id,
    m.market_name,
    tc.account_id,
    tc.buy_trade_amount,
    tc.sell_trade_amount,
    tc.trade_fee_amount,
    tc.trading_cashflow,
    rc.result,
    rc.settlement_type,
    rc.net_position_quantity,
    rc.settlement_cashflow,
    CASE
        WHEN rc.settlement_type = 'NORMAL'
            THEN rc.settlement_cashflow - tc.trading_cashflow
        ELSE NULL
    END AS final_pnl,
    CASE
        WHEN rc.market_config_id IS NULL THEN 'UNSETTLED'
        WHEN rc.settlement_type = 'NORMAL' THEN 'FINAL'
        ELSE 'UNSETTLED'
    END AS pnl_status,
    rc.window_start_time,
    rc.window_end_time
FROM trade_cash tc
INNER JOIN market_config m ON m.id = tc.market_config_id
LEFT JOIN result_cash rc
    ON rc.market_config_id = tc.market_config_id
   AND rc.outcome_id = tc.outcome_id
   AND rc.account_id = tc.account_id
SQL;
    }
}
