<?php
declare(strict_types=1);

namespace app\so_strategy\service;

/** 成交列表/导出共用的快盘派生字段表达式。 */
final class FastbookTradeFields
{
    /**
     * 生成成交列表的派生字段。
     *
     * 做市商账号来自 sys_config 的排除账号配置（同一份就是唯一的做市账号）。
     * 只有单边命中做市账号时才给出方向；双边命中或双边都不命中为 UNKNOWN。
     *
     * @param string[] $makerAccountIds 做市商账号列表（一般只有一个）
     */
    public static function select(array $makerAccountIds): string
    {
        $accounts = self::quoteIds($makerAccountIds);
        if ($accounts === '') {
            $makerSide = "'UNKNOWN'";
        } else {
            $makerSide = 'CASE'
                . " WHEN t.buyer_account_id IN ({$accounts}) AND (t.seller_account_id IS NULL OR t.seller_account_id NOT IN ({$accounts})) THEN 'BUY'"
                . " WHEN t.seller_account_id IN ({$accounts}) AND (t.buyer_account_id IS NULL OR t.buyer_account_id NOT IN ({$accounts})) THEN 'SELL'"
                . " ELSE 'UNKNOWN' END";
        }
        return 't.*, mo.outcome_id AS outcome_id,'
            . ' mr.window_start_time AS window_start_time, mr.window_end_time AS window_end_time,'
            . ' mc.market_name AS market_name, mo.option_code AS option_code,'
            . ' ' . $makerSide . ' AS maker_side,'
            . " CASE WHEN mr.settlement_type = 'NORMAL' AND mr.result IS NOT NULL AND mr.result <> ''"
            . " THEN CASE WHEN mo.option_code = mr.result THEN 'WIN' ELSE 'LOSE' END"
            . " ELSE 'PENDING' END AS is_win"
            // predictfun 价差告警表（psa）左关联带来的派生字段：predictfun 最优参考价。
            . ', psa.reference_price AS predictfun_optimal_price';
    }

    private static function quoteIds(array $makerAccountIds): string
    {
        $parts = [];
        foreach ($makerAccountIds as $accountId) {
            $accountId = trim((string)$accountId);
            if ($accountId === '') {
                continue;
            }
            if (!ctype_digit($accountId)) {
                throw new \InvalidArgumentException('快盘做市商账号必须是数字');
            }
            $parts[$accountId] = "'{$accountId}'";
        }
        return implode(',', $parts);
    }
}
