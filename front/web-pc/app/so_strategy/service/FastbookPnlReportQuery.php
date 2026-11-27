<?php
declare(strict_types=1);

namespace app\so_strategy\service;

/**
 * 快盘盈亏报表查询构造器。
 *
 * 与 FastbookPnlQuery 的区别：后者先把 trade_records 全量展开成账号流派再在外层过滤，
 * MySQL 无法把外层谓词推进那种 UNION + GROUP BY 派生表，实际每次都是全表聚合。
 * 这里反过来做：先用 market_results / market_options 上的时间索引求出候选 Outcome，
 * 再用 option 主键桥点查成交，聚合范围从「全量成交」收敛到「窗口内成交」。
 *
 * 支持 mysql 8 与 sqlite 3.25+（均支持 CTE 与窗口函数）。
 */
final class FastbookPnlReportQuery
{
    /** @var array<string,mixed> */
    private array $binds = [];
    private int $seq = 0;

    /**
     * @param array<string,mixed> $filter @param string[] $excluded
     * @param int $limit 0 表示不加 LIMIT（导出用：一次聚合并由游标逐行读取）
     * @return array{sql:string,binds:array}
     */
    public function userPnlSql(array $filter, array $excluded, int $limit = 0, int $offset = 0): array
    {
        $sql = $this->userCte($filter, $excluded)
            . $this->userSelect($filter)
            . $this->userOrder($filter) . $this->limit($limit, $offset);
        return ['sql' => $sql, 'binds' => $this->binds];
    }

    /** @param array<string,mixed> $filter @param string[] $excluded */
    public function userPnlCountSql(array $filter, array $excluded): array
    {
        $sql = $this->userCte($filter, $excluded)
            . ' SELECT COUNT(*) AS total FROM (SELECT r.account_id FROM ranked r GROUP BY r.account_id'
            . $this->having($filter) . ') cnt';
        return ['sql' => $sql, 'binds' => $this->binds];
    }

    /**
     * @param array<string,mixed> $filter @param string[] $excluded
     * @param int $limit 0 表示不加 LIMIT（导出用）
     * @return array{sql:string,binds:array}
     */
    public function outcomePnlSql(array $filter, array $excluded, int $limit = 0, int $offset = 0): array
    {
        [$start, $end] = $this->timeBounds($filter);
        $sql = $this->outcomeCte($filter, $excluded, $start, $end)
            . ' SELECT b.market_config_id, b.outcome_id, m.market_id, m.market_name,'
            . ' b.end_time, b.window_start_time, b.result, b.settlement_type,'
            . ' b.pnl, b.trade_count, b.user_count, b.fee_amount, b.first_trade_at, b.last_trade_at'
            . ' FROM base b JOIN market_config m ON m.id = b.market_config_id'
            . " AND m.market_type = 'FASTBOOK' AND m.vendor = 'FOREGATE'"
            . $this->outcomeWhere($filter)
            . $this->outcomeOrder($filter) . $this->limit($limit, $offset);
        return ['sql' => $sql, 'binds' => $this->binds];
    }

    /** @param array<string,mixed> $filter @param string[] $excluded */
    public function outcomePnlCountSql(array $filter, array $excluded): array
    {
        [$start, $end] = $this->timeBounds($filter);
        $sql = $this->outcomeCte($filter, $excluded, $start, $end)
            . ' SELECT COUNT(*) AS total FROM base b'
            . ' JOIN market_config m ON m.id = b.market_config_id'
            . " AND m.market_type = 'FASTBOOK' AND m.vendor = 'FOREGATE'"
            . $this->outcomeWhere($filter);
        return ['sql' => $sql, 'binds' => $this->binds];
    }

    /** 候选 Outcome 数量预检：只扫两张小表，用于拦截过大的查询范围。 */
    public function candidateCountSql(array $filter): array
    {
        $this->reset();
        $sql = 'SELECT COUNT(*) AS total FROM (' . $this->candidateSql($filter) . ') cand'
            . ' JOIN market_config mc ON mc.id = cand.market_config_id'
            . " AND mc.market_type = 'FASTBOOK' AND mc.vendor = 'FOREGATE'";
        return ['sql' => $sql, 'binds' => $this->binds];
    }

    // ---------------------------------------------------------------- 用户维度

    private function userCte(array $filter, array $excluded): string
    {
        $this->reset();
        return $this->sideCte($filter, $excluded)
            . ',agg AS ('
            . ' SELECT s.market_config_id, s.outcome_id, s.account_id,'
            . ' SUM(CASE WHEN s.trade_side = \'SELL\' THEN s.trade_amount ELSE -s.trade_amount END) AS trade_pnl,'
            . ' SUM(CASE WHEN r.settlement_type = \'NORMAL\' AND s.option_code = r.result'
            . ' THEN CASE WHEN s.trade_side = \'BUY\' THEN s.trade_quantity ELSE -s.trade_quantity END'
            . ' ELSE 0 END) AS settlement_cashflow,'
            . ' SUM(s.fee_amount) AS fee_amount,'
            . ' COUNT(DISTINCT s.trade_id) AS trade_count,'
            . ' SUM(s.trade_amount) AS trade_amount,'
            . ' MIN(s.trade_time) AS first_trade_at, MAX(s.trade_time) AS last_trade_at'
            . ' FROM trade_side s'
            . ' LEFT JOIN market_results r ON r.market_config_id = s.market_config_id AND r.outcome_id = s.outcome_id'
            . ' WHERE s.is_excluded = 0'
            . ' GROUP BY s.market_config_id, s.outcome_id, s.account_id'
            . '),lvl1 AS ('
            . ' SELECT a.market_config_id, a.outcome_id, a.account_id,'
            . ' (a.trade_pnl + COALESCE(a.settlement_cashflow,0)) AS customer_pnl,'
            . ' a.trade_count, a.trade_amount, a.fee_amount, a.first_trade_at, a.last_trade_at FROM agg a'
            . '),ranked AS ('
            . ' SELECT l.*,'
            . ' ROW_NUMBER() OVER (PARTITION BY l.account_id ORDER BY l.customer_pnl DESC) AS rn_win,'
            . ' ROW_NUMBER() OVER (PARTITION BY l.account_id ORDER BY l.customer_pnl ASC) AS rn_loss'
            . ' FROM lvl1 l'
            . ')';
    }

    private function userSelect(array $filter): string
    {
        return ' SELECT r.account_id,'
            . ' SUM(r.customer_pnl) AS pnl,'
            . ' SUM(CASE WHEN r.customer_pnl > 0 THEN 1 ELSE 0 END) AS win_outcomes,'
            . ' SUM(CASE WHEN r.customer_pnl < 0 THEN 1 ELSE 0 END) AS lose_outcomes,'
            . ' SUM(r.trade_count) AS trade_count,'
            . ' SUM(r.trade_amount) AS trade_amount,'
            . ' SUM(r.fee_amount) AS fee_amount,'
            . ' MAX(CASE WHEN r.rn_win = 1 AND r.customer_pnl > 0 THEN r.customer_pnl END) AS max_win_pnl,'
            . ' MAX(CASE WHEN r.rn_win = 1 AND r.customer_pnl > 0 THEN r.outcome_id END) AS max_win_outcome,'
            . ' MIN(CASE WHEN r.rn_loss = 1 AND r.customer_pnl < 0 THEN r.customer_pnl END) AS max_loss_pnl,'
            . ' MAX(CASE WHEN r.rn_loss = 1 AND r.customer_pnl < 0 THEN r.outcome_id END) AS max_loss_outcome,'
            . ' MIN(r.first_trade_at) AS first_trade_at, MAX(r.last_trade_at) AS last_trade_at'
            . ' FROM ranked r GROUP BY r.account_id' . $this->having($filter);
    }

    private function having(array $filter): string
    {
        $direction = (string)($filter['direction'] ?? '');
        if ($direction === 'profit') {
            return ' HAVING SUM(r.customer_pnl) > 0';
        }
        if ($direction === 'loss') {
            return ' HAVING SUM(r.customer_pnl) < 0';
        }
        return '';
    }

    // ---------------------------------------------------------------- 盘口维度

    private function outcomeCte(array $filter, array $excluded, string $start, string $end): string
    {
        $this->reset();
        return $this->sideCte($filter, $excluded)
            . ',agg AS ('
            . ' SELECT s.market_config_id, s.outcome_id,'
            . ' COUNT(DISTINCT s.trade_id) AS trade_count,'
            . ' COUNT(DISTINCT CASE WHEN s.is_excluded = 0 THEN s.account_id END) AS user_count,'
            . ' SUM(CASE WHEN s.is_excluded = 0 THEN'
            . ' CASE WHEN s.trade_side = \'SELL\' THEN s.trade_amount ELSE -s.trade_amount END ELSE 0 END) AS customer_trade_pnl,'
            . ' SUM(CASE WHEN s.is_excluded = 0 AND r.settlement_type = \'NORMAL\' AND s.option_code = r.result'
            . ' THEN CASE WHEN s.trade_side = \'BUY\' THEN s.trade_quantity ELSE -s.trade_quantity END'
            . ' ELSE 0 END) AS customer_settlement_cashflow,'
            . ' SUM(CASE WHEN s.is_excluded = 0 THEN s.fee_amount ELSE 0 END) AS fee_amount,'
            . ' MIN(s.trade_time) AS first_trade_at, MAX(s.trade_time) AS last_trade_at'
            . ' FROM trade_side s'
            . ' LEFT JOIN market_results r ON r.market_config_id = s.market_config_id AND r.outcome_id = s.outcome_id'
            . ' GROUP BY s.market_config_id, s.outcome_id'
            . '),base AS ('
            . ' SELECT c.market_config_id, c.outcome_id, c.end_time,'
            . ' r.result, r.settlement_type, r.window_start_time,'
            . ' -(COALESCE(a.customer_trade_pnl,0) + COALESCE(a.customer_settlement_cashflow,0)) AS pnl,'
            . ' COALESCE(a.trade_count,0) AS trade_count,'
            . ' COALESCE(a.user_count,0) AS user_count,'
            . ' COALESCE(a.fee_amount,0) AS fee_amount,'
            . ' a.first_trade_at, a.last_trade_at'
            . ' FROM (' . $this->candidateSql($filter, $start, $end) . ') c'
            . ' LEFT JOIN market_results r ON r.market_config_id = c.market_config_id AND r.outcome_id = c.outcome_id'
            . ' LEFT JOIN agg a ON a.market_config_id = c.market_config_id AND a.outcome_id = c.outcome_id'
            . ')';
    }

    /** 盘口盈亏报表的 WHERE：默认过滤交易笔数为 0 的盘口，叠加盈亏方向筛选。 */
    private function outcomeWhere(array $filter): string
    {
        $conds = [];
        // 默认过滤交易笔数为 0 的盘口。
        $conds[] = 'b.trade_count > 0';
        $direction = (string)($filter['direction'] ?? '');
        if ($direction === 'profit') {
            $conds[] = 'b.pnl > 0';
        } elseif ($direction === 'loss') {
            $conds[] = 'b.pnl < 0';
        }
        return $conds ? ' WHERE ' . implode(' AND ', $conds) : '';
    }

    /** 盘口盈亏报表的 ORDER BY：仅允许白名单字段，杜绝 SQL 注入。默认按创建时间（盘口开始时间）降序。 */
    private function outcomeOrder(array $filter): string
    {
        $map = ['pnl' => 'b.pnl', 'trade_count' => 'b.trade_count', 'user_count' => 'b.user_count', 'window_start_time' => 'b.window_start_time'];
        $field = $map[$filter['sort_field'] ?? 'window_start_time'] ?? 'b.window_start_time';
        $dir = (($filter['sort_dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
        return ' ORDER BY ' . $field . ' ' . $dir;
    }

    /** 用户盈亏报表的 ORDER BY：仅允许白名单字段，杜绝 SQL 注入。默认按盈亏金额降序。 */
    private function userOrder(array $filter): string
    {
        $map = ['pnl' => 'pnl', 'trade_count' => 'trade_count', 'last_trade_at' => 'last_trade_at'];
        $field = $map[$filter['sort_field'] ?? 'pnl'] ?? 'pnl';
        $dir = (($filter['sort_dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
        return ' ORDER BY ' . $field . ' ' . $dir;
    }

    // ---------------------------------------------------------------- 公共下推部分

    /** 候选 Outcome → option 桥 → 买卖双方展开，全程走索引。 */
    private function sideCte(array $filter, array $excluded): string
    {
        [$start, $end] = $this->timeBounds($filter);
        return 'WITH cand AS (' . $this->candidateSql($filter, $start, $end) . ')'
            . ',opt AS ('
            . ' SELECT o.id AS option_id, o.market_config_id, o.outcome_id, o.option_code'
            . ' FROM market_options o JOIN cand c'
            . ' ON c.market_config_id = o.market_config_id AND c.outcome_id = o.outcome_id'
            // FASTBOOK + FOREGATE 边界放在这里：cand 已物化，加入市场表不会带偏候选分支的索引范围扫描。
            . ' JOIN market_config mc ON mc.id = o.market_config_id'
            . " AND mc.market_type = 'FASTBOOK' AND mc.vendor = 'FOREGATE'"
            . '),trade_side AS ('
            . $this->sideBranch($filter, $excluded, 'BUY')
            . ' UNION ALL '
            . $this->sideBranch($filter, $excluded, 'SELL')
            . ')';
    }

    private function sideBranch(array $filter, array $excluded, string $side): string
    {
        $isBuy = $side === 'BUY';
        $account = $isBuy ? 't.buyer_account_id' : 't.seller_account_id';
        $fee = $isBuy ? 't.buy_fee_amount' : 't.sell_fee_amount';
        $sql = ' SELECT op.market_config_id, op.outcome_id, op.option_code, t.id AS trade_id,'
            . " {$account} AS account_id, t.trade_amount, t.trade_quantity,"
            . " {$fee} AS fee_amount, t.trade_time, '{$side}' AS trade_side,"
            . ' CASE WHEN ' . $this->inList($account, $excluded) . ' THEN 1 ELSE 0 END AS is_excluded'
            . ' FROM trade_records t JOIN opt op ON op.option_id = t.market_option_id'
            . " WHERE {$account} IS NOT NULL";
        $accountId = trim((string)($filter['account_id'] ?? ''));
        if ($accountId !== '') {
            $sql .= ' AND ' . $account . ' = ' . $this->param($accountId);
        }
        return $sql;
    }

    /**
     * 候选 Outcome：已结算用 window_end_time，未结算回退 MAX(option.trading_end_at)，
     * 与洞察页 COALESCE(window_end_time, MAX(trading_end_at)) 完全一致。
     *
     * 注意：这里刻意不 join market_config。实测 MySQL 一旦把 market_config 放进候选分支，
     * 优化器会改成从 market_config 驱动、按 (market_config_id) 回查结果表，
     * 反而丢掉时间范围走 idx_result_window_end / idx_option_trading_end 的范围扫描；
     * FASTBOOK + FOREGATE 的边界改在下方的 opt 里过滤，此时 cand 已物化，计划不会被带偏。
     */
    private function candidateSql(array $filter, ?string $start = null, ?string $end = null): string
    {
        [$start, $end] = $start !== null ? [$start, $end] : $this->timeBounds($filter);
        // 每个分支各自绑定参数：同一个命名占位符不能在一条语句里出现两次。
        $settledFilter = $this->dimensionFilter($filter, 'r.');
        $unsettledFilter = $this->dimensionFilter($filter, 'o.');

        $settled = ' SELECT r.market_config_id, r.outcome_id, MAX(r.window_end_time) AS end_time'
            . ' FROM market_results r'
            . ' WHERE r.window_end_time BETWEEN ' . $this->param($start) . ' AND ' . $this->param($end)
            . $settledFilter;
        $result = trim((string)($filter['result'] ?? ''));
        if ($result !== '') {
            $settled .= ' AND r.result = ' . $this->param($result);
        }
        $settlementType = trim((string)($filter['settlement_type'] ?? ''));
        if ($settlementType !== '') {
            $settled .= ' AND r.settlement_type = ' . $this->param($settlementType);
        }
        $settled .= ' GROUP BY r.market_config_id, r.outcome_id';

        $unsettled = ' SELECT o.market_config_id, o.outcome_id, MAX(o.trading_end_at) AS end_time'
            . ' FROM market_options o'
            . ' WHERE o.trading_end_at BETWEEN ' . $this->param($start) . ' AND ' . $this->param($end)
            . $unsettledFilter
            . ' AND NOT EXISTS (SELECT 1 FROM market_results r2'
            . ' WHERE r2.market_config_id = o.market_config_id AND r2.outcome_id = o.outcome_id)'
            . ' GROUP BY o.market_config_id, o.outcome_id';

        return $settled . ' UNION ALL ' . $unsettled;
    }

    /** 市场内部 ID / Outcome ID 的下推条件，按分支前缀生成，返回后并入 WHERE。 */
    private function dimensionFilter(array $filter, string $alias): string
    {
        $sql = '';
        $marketId = trim((string)($filter['market_config_id'] ?? ''));
        if (ctype_digit($marketId)) {
            $sql .= ' AND ' . $alias . 'market_config_id = ' . $this->param($marketId);
        }
        $outcomeId = trim((string)($filter['outcome_id'] ?? ''));
        if ($outcomeId !== '') {
            $sql .= ' AND ' . $alias . 'outcome_id = ' . $this->param($outcomeId);
        }
        return $sql;
    }

    /** @return array{0:string,1:string} UTC 起止时间（含整天首尾）。 */
    private function timeBounds(array $filter): array
    {
        $start = (string)($filter['start'] ?? '');
        $end = (string)($filter['end'] ?? '');
        return [$start . ' 00:00:00', $end . ' 23:59:59.999999'];
    }

    /** $limit 为 0 时不加 LIMIT，导出用一次性聚合 + 游标读取，避免 OFFSET 分批重复聚合。 */
    private function limit(int $limit, int $offset): string
    {
        return $limit > 0 ? ' LIMIT ' . $limit . ' OFFSET ' . max(0, $offset) : '';
    }

    /** @param string[] $values */
    private function inList(string $column, array $values): string
    {
        if ($values === []) {
            return '1 = 0';
        }
        $parts = [];
        foreach ($values as $value) {
            $parts[] = $this->param((string)$value);
        }
        return $column . ' IN (' . implode(',', $parts) . ')';
    }

    private function param(string $value): string
    {
        $name = 'p' . (++$this->seq);
        $this->binds[$name] = $value;
        return ':' . $name;
    }

    private function reset(): void
    {
        $this->binds = [];
        $this->seq = 0;
    }
}
