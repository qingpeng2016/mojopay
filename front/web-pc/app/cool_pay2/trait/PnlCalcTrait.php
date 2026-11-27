<?php
declare(strict_types=1);

namespace app\cool_pay2\trait;

use think\facade\Cache;
use think\facade\Db;

/**
 * PNL 计算公共逻辑（2026-05-15 新公式；2026-05-21 时间过滤字段改为 created_at）
 *
 * 时间过滤字段：created_at（2026-05-21 之前为 updated_at）
 *   updated_at 会被 real_trade_amount 回填脚本 / 结算脚本反复 bump，
 *   导致"5.19 PNL"今天看是 -13.81、明天看可能漂到 -10.71，行集合不稳定。
 *   改用 created_at 后，每天的行集合 INSERT 之后就定死，PNL 数字不再因后台脚本而漂移。
 *   注意残留：Part 1 Poly 用 IF(real_trade_amount=0, trade_amount, ...) 兜底，
 *   Part 2 依赖 source_winning_token_id（结算时才被填）——所以已结算市场对应的
 *   历史 PNL 仍会在"结算发生时"小幅变动，但行集合本身已稳定。
 *
 * 新公式（产品/Dylan 定稿）：
 *   PNL = (FG_sell_amount − FG_buy_amount − Poly_buy_real + Poly_sell_real)        ← Part 1: 已对冲差价
 *       + (Poly_isWon_BUY_qty − Poly_isWon_SELL_qty − FG_isWon_SELL_qty + FG_isWon_BUY_qty)  ← Part 2: 结算差价
 *
 * 字段对照：
 *   FG 侧   = target_trader_records，金额用 trade_amount，份额用 trade_quantity
 *   Poly 侧 = source_trader_records，金额用 real_trade_amount（Dylan 新加，扣除内扣 fee 后的真实成交金额），份额用 trade_quantity
 *   isWon=1 判定：
 *     Poly 侧 = source.source_token_id 等于 market_mapping_config.source_winning_token_id（Dylan 新加，结算赢家 Poly token）
 *     FG  侧 = target.target_token_id 等于 token_id_mapping JSON 反查后的 targetTokenId
 *
 * 设计取舍（产品决策）：
 *   - real_trade_amount = 0（未回填）的行兜底用 trade_amount，避免回填脚本未跑完时数字虚高。
 *     SQL 表达式：IF(real_trade_amount = 0, trade_amount, real_trade_amount)
 *   - 未结算市场 source_winning_token_id 为空字符串，自动不计入 Part 2。
 *   - 老公式里的 Poly 手续费（source.fee_amount）项整体去掉（Poly 内扣 fee 已含在 real_trade_amount 里）。
 */
trait PnlCalcTrait
{
    /** Part 1 FG 净额 SQL 表达式：SUM(SELL trade_amount − BUY trade_amount) on target_trader_records */
    protected const PART1_FG_NET_EXPR =
        "COALESCE(SUM(CASE WHEN order_side = 'SELL' THEN trade_amount ELSE -trade_amount END), 0)";

    /** Part 1 Poly 净额 SQL 表达式：real_trade_amount，real=0 时兜底用 trade_amount on source_trader_records */
    protected const PART1_POLY_NET_EXPR =
        "COALESCE(SUM(CASE WHEN order_side = 'SELL' "
        . "THEN IF(real_trade_amount = 0, trade_amount, real_trade_amount) "
        . "ELSE -IF(real_trade_amount = 0, trade_amount, real_trade_amount) END), 0)";

    /** Part 2 数量差 SQL 表达式：SUM(BUY trade_quantity − SELL trade_quantity)（带 isWon 行过滤外置） */
    protected const PART2_BUY_SELL_QTY_EXPR =
        "COALESCE(SUM(CASE WHEN order_side = 'BUY' THEN trade_quantity ELSE -trade_quantity END), 0)";

    /**
     * per-request 缓存：getWinningTokens 在同一请求里被 buildShardPnl(ByGroup) 反复调用，
     * 入参相同（conn + mc_ids）时结果一定相同（mmc 表在请求生命周期内不变）。
     * key = conn + '|' + mc_ids 签名（null=__ALL__，空数组=__EMPTY__，否则 sorted CSV）
     * @var array<string, array<int, array{poly:string, fg:?string}>>
     */
    private static array $winnersCache = [];

    /**
     * 取一组市场的赢家 token（Poly + FG 双方）。
     * 未结算（source_winning_token_id 为空）的市场不返回。
     * FG 赢家 token 通过 market_mapping_config.token_id_mapping JSON 反查得到，
     * JSON 结构假定为 [{"sourceTokenId": "...", "targetTokenId": "..."}, ...]。
     *
     * @param array<int>|null $mcIds      market_config_id 列表；null 表示不限制（取全部已结算市场）
     * @param string|null     $connection 指定 ThinkPHP DB 连接名（用于多分片场景）；null = 当前默认连接
     * @return array<int, array{poly: string, fg: ?string}>
     *         键是 market_config_id；fg 在 token_id_mapping JSON 反查失败时为 null（此市场不计入 FG Part 2）
     */
    protected function getWinningTokens(?array $mcIds = null, ?string $connection = null): array
    {
        $cacheKey = ($connection ?? '__default__') . '|';
        if ($mcIds === null) {
            $cacheKey .= '__ALL__';
        } elseif ($mcIds === []) {
            return [];
        } else {
            $ids = array_map('intval', $mcIds);
            sort($ids);
            $cacheKey .= implode(',', $ids);
        }
        // L1：进程内 static cache（同一请求多次调用免回查）
        if (isset(self::$winnersCache[$cacheKey])) {
            return self::$winnersCache[$cacheKey];
        }
        // L2：跨请求 file cache，5 分钟 TTL。
        // mmc 数据只在新市场上线 / 结算时变更，5 分钟过时完全可接受；
        // 命中后整段 winners 查询（dev 1-4s / prod 2-4s）变成 ~ms 级。
        // 若数据立即变更需可见，调用方可手动 Cache::delete('pnl_winners_' . md5(key))。
        $fileCacheKey = 'pnl_winners_' . md5($cacheKey);
        $cached = Cache::get($fileCacheKey);
        if (is_array($cached)) {
            return self::$winnersCache[$cacheKey] = $cached;
        }

        $db = $connection !== null ? Db::connect($connection) : Db::connect();
        $query = $db->name('market_mapping_config')
            ->where('source_winning_token_id', '<>', '')
            ->field('id, source_winning_token_id, token_id_mapping');
        if ($mcIds !== null) {
            $query->whereIn('id', $mcIds);
        }
        $rows = $query->select()->toArray();

        $out = [];
        foreach ($rows as $r) {
            $polyWinner = (string)($r['source_winning_token_id'] ?? '');
            if ($polyWinner === '') {
                continue;
            }
            $fgWinner = null;
            $mapping = json_decode((string)($r['token_id_mapping'] ?? ''), true);
            if (is_array($mapping)) {
                foreach ($mapping as $pair) {
                    if (!is_array($pair)) {
                        continue;
                    }
                    $sid = (string)($pair['sourceTokenId'] ?? '');
                    $tid = (string)($pair['targetTokenId'] ?? '');
                    if ($sid !== '' && $sid === $polyWinner && $tid !== '') {
                        $fgWinner = $tid;
                        break;
                    }
                }
            }
            $out[(int)$r['id']] = ['poly' => $polyWinner, 'fg' => $fgWinner];
        }

        self::$winnersCache[$cacheKey] = $out;
        Cache::set($fileCacheKey, $out, 300);
        return $out;
    }

    /**
     * 在 trader 表上聚合 isWon=1 行的份额差：SUM(BUY qty) − SUM(SELL qty)。
     * 用 (market_config_id, token_col) 元组 IN 精准过滤"该市场赢家 token 的成交"。
     * 可选按某列分组（用于按市场 / 按 token 输出）。
     *
     * @param string                          $table       'source_trader_records' 或 'target_trader_records'
     * @param string                          $tokenCol    'source_token_id' 或 'target_token_id'（trader 表里识别"是否赢家行"的列）
     * @param array<array{int, string}>       $tuples      [[market_config_id, winning_token_id], ...]
     * @param string|null                     $startTs     FILLED.created_at >= startTs（含），null 不限
     * @param string|null                     $endTs       FILLED.created_at <= endTs（含），null 不限
     * @param string|null                     $groupCol    分组列或 SQL 表达式（如 'target_group_id'、'DATE(created_at)'、'HOUR(created_at)'）；
     *                                                     null 表示不分组，返回单行。注意：作为 SELECT/GROUP BY 直接拼入 SQL，仅限内部受控调用。
     * @param array<string, mixed>            $extraWhere  额外 where 条件，键是字段名，值是过滤值或值数组（数组走 IN）
     * @param string|null                     $connection  指定 ThinkPHP DB 连接名（用于多分片场景）；null = 当前默认连接
     *
     * @return array<string, float>  groupCol 为 null 时 key='_total'；否则 key 是该列的值。空 $tuples 时返回空数组。
     */
    protected function sumIsWonQtyDiff(
        string $table,
        string $tokenCol,
        array $tuples,
        ?string $startTs = null,
        ?string $endTs = null,
        ?string $groupCol = null,
        array $extraWhere = [],
        ?string $connection = null
    ): array {
        if ($tuples === []) {
            return [];
        }

        // Split tuples into batches to avoid "too many placeholders" error
        $maxPlaceholders = 1000; // Safe limit for most database drivers
        $tupleBatchSize = (int)floor($maxPlaceholders / 2); // 2 placeholders per tuple
        $tupleBatches = array_chunk($tuples, $tupleBatchSize);

        $db = $connection !== null ? Db::connect($connection) : Db::connect();
        $results = [];

        foreach ($tupleBatches as $batch) {
            $placeholders = [];
            $tupleBinds = [];
            foreach ($batch as $t) {
                $placeholders[] = '(?, ?)';
                $tupleBinds[] = (int)$t[0];
                $tupleBinds[] = (string)$t[1];
            }
            $tuplesSql = implode(',', $placeholders);

            $where = ["status = 'FILLED'"];
            $binds = [];
            if ($startTs !== null) {
                $where[] = 'created_at >= ?';
                $binds[] = $startTs;
            }
            if ($endTs !== null) {
                $where[] = 'created_at <= ?';
                $binds[] = $endTs;
            }
            foreach ($extraWhere as $col => $val) {
                if (is_array($val)) {
                    if ($val === []) {
                        // IN 空列表 = 永真否定，直接返回空结果（用 1=0 表达）
                        continue;
                    }
                    $placeholders0 = implode(',', array_fill(0, count($val), '?'));
                    $where[] = "`$col` IN ($placeholders0)";
                    foreach ($val as $v) {
                        $binds[] = $v;
                    }
                } else {
                    $where[] = "`$col` = ?";
                    $binds[] = $val;
                }
            }
            $where[] = "(market_config_id, `$tokenCol`) IN ($tuplesSql)";
            $binds = array_merge($binds, $tupleBinds);

            $select = self::PART2_BUY_SELL_QTY_EXPR . ' AS net';
            if ($groupCol !== null) {
                // groupCol 直接拼入 SQL，允许表达式（DATE(...)/HOUR(...)）。仅限受控内部调用。
                $select = "$groupCol AS gk, " . $select;
            }
            $sql = "SELECT $select FROM `$table` WHERE " . implode(' AND ', $where);
            if ($groupCol !== null) {
                $sql .= " GROUP BY $groupCol";
            }

            $rows = $db->query($sql, $binds);
            
            if ($groupCol === null) {
                // For non-grouped queries, accumulate the net values
                if (!isset($results['_total'])) {
                    $results['_total'] = 0;
                }
                $results['_total'] += (float)($rows[0]['net'] ?? 0);
            } else {
                // For grouped queries, accumulate values by group key
                foreach ($rows as $r) {
                    $gk = (string)$r['gk'];
                    if (!isset($results[$gk])) {
                        $results[$gk] = 0;
                    }
                    $results[$gk] += (float)$r['net'];
                }
            }
        }

        return $results;
    }

    /**
     * 在指定分片上算 PNL 4 项（Part 1 FG / Poly + Part 2 Poly / FG）+ 合计。
     * 调用方一般在 ShardManager::fanOutRead 的闭包里调用本方法，再把多片结果各自累加。
     *
     * $extra 支持 key：
     *   - target_account_id : 应用到 target_trader_records.target_account_id（FG 端账户过滤）
     *   - source_account_id : 应用到 source_trader_records.source_account_id（Poly 端账户过滤）
     *   - target_group_id   : string 或 string[]，应用到两边的 target_group_id（市场过滤；全局键，跨片安全）
     *   - mc_ids_local      : list<int>|null，本片上的 market_config_id 集合，限制 Part 2 winners 范围
     *                         （注意：mc_id 是分片本地自增，仅在该 $conn 上下文内有效）
     *
     * @return array{fgNet:float, polyNet:float, polyQty:float, fgQty:float, total:float}
     */
    protected function buildShardPnl(
        string $conn,
        ?string $startTs,
        ?string $endTs,
        array $extra = []
    ): array {
        [$targetExtra, $sourceExtra] = self::splitInsightExtra($extra);

        // Part 1 FG
        $tq = Db::connect($conn)->name('target_trader_records')
            ->where('status', 'FILLED')
            ->fieldRaw(self::PART1_FG_NET_EXPR . ' AS net');
        if ($startTs !== null) $tq->where('created_at', '>=', $startTs);
        if ($endTs !== null)   $tq->where('created_at', '<=', $endTs);
        self::applyExtraWhere($tq, $targetExtra);
        $fgNet = (float)($tq->find()['net'] ?? 0);

        // Part 1 Poly
        $sq = Db::connect($conn)->name('source_trader_records')
            ->where('status', 'FILLED')
            ->fieldRaw(self::PART1_POLY_NET_EXPR . ' AS net');
        if ($startTs !== null) $sq->where('created_at', '>=', $startTs);
        if ($endTs !== null)   $sq->where('created_at', '<=', $endTs);
        self::applyExtraWhere($sq, $sourceExtra);
        $polyNet = (float)($sq->find()['net'] ?? 0);

        // Part 2
        $mcIdsLocal = $extra['mc_ids_local'] ?? null;
        [$polyTuples, $fgTuples] = self::splitWinnerTuples($this->getWinningTokens($mcIdsLocal, $conn));
        $polyQty = array_sum($this->sumIsWonQtyDiff(
            'source_trader_records', 'source_token_id', $polyTuples, $startTs, $endTs, null, $sourceExtra, $conn
        ));
        $fgQty = array_sum($this->sumIsWonQtyDiff(
            'target_trader_records', 'target_token_id', $fgTuples, $startTs, $endTs, null, $targetExtra, $conn
        ));

        return [
            'fgNet'   => $fgNet,
            'polyNet' => $polyNet,
            'polyQty' => $polyQty,
            'fgQty'   => $fgQty,
            'total'   => $fgNet + $polyNet + $polyQty + $fgQty,
        ];
    }

    /**
     * 同 buildShardPnl，但所有 4 项都按 $groupCol 分组返回。
     * 用于 DataInsight 的 combinedPnlByGroup / dailyPnlSeries / hourlyPnlSeries。
     *
     * @param string $groupCol 分组列或 SQL 表达式（target_group_id / target_token_id / DATE(created_at) / HOUR(created_at)）
     *                         注意：直接拼入 SQL，仅限受控内部调用，不能传用户输入。
     * @return array<string,float> groupKey => 该 key 上的 PNL 合计
     */
    protected function buildShardPnlByGroup(
        string $conn,
        string $groupCol,
        ?string $startTs,
        ?string $endTs,
        array $extra = []
    ): array {
        [$targetExtra, $sourceExtra] = self::splitInsightExtra($extra);
        // 普通列名（如 target_group_id / target_token_id）需要排除空字符串行；
        // SQL 表达式（DATE(created_at) / HOUR(created_at)）无需也不能加 <> ''
        $isPlainCol = preg_match('/^[a-z_][a-z0-9_]*$/i', $groupCol) === 1;
        $local = [];

        // Part 1 FG by $groupCol
        $tq = Db::connect($conn)->name('target_trader_records')
            ->where('status', 'FILLED')
            ->fieldRaw("$groupCol AS gk, " . self::PART1_FG_NET_EXPR . ' AS net');
        if ($startTs !== null) $tq->where('created_at', '>=', $startTs);
        if ($endTs !== null)   $tq->where('created_at', '<=', $endTs);
        if ($isPlainCol)       $tq->where($groupCol, '<>', '');
        self::applyExtraWhere($tq, $targetExtra);
        $tq->group($groupCol);
        foreach ($tq->select()->toArray() as $r) {
            $local[(string)$r['gk']] = (float)$r['net'];
        }

        // Part 1 Poly by $groupCol
        $sq = Db::connect($conn)->name('source_trader_records')
            ->where('status', 'FILLED')
            ->fieldRaw("$groupCol AS gk, " . self::PART1_POLY_NET_EXPR . ' AS net');
        if ($startTs !== null) $sq->where('created_at', '>=', $startTs);
        if ($endTs !== null)   $sq->where('created_at', '<=', $endTs);
        if ($isPlainCol)       $sq->where($groupCol, '<>', '');
        self::applyExtraWhere($sq, $sourceExtra);
        $sq->group($groupCol);
        foreach ($sq->select()->toArray() as $r) {
            $gk = (string)$r['gk'];
            $local[$gk] = ($local[$gk] ?? 0.0) + (float)$r['net'];
        }

        // Part 2 by $groupCol
        $mcIdsLocal = $extra['mc_ids_local'] ?? null;
        [$polyTuples, $fgTuples] = self::splitWinnerTuples($this->getWinningTokens($mcIdsLocal, $conn));
        $polyByGroup = $this->sumIsWonQtyDiff(
            'source_trader_records', 'source_token_id', $polyTuples, $startTs, $endTs, $groupCol, $sourceExtra, $conn
        );
        $fgByGroup = $this->sumIsWonQtyDiff(
            'target_trader_records', 'target_token_id', $fgTuples, $startTs, $endTs, $groupCol, $targetExtra, $conn
        );
        foreach ($polyByGroup as $gk => $qty) {
            if ($isPlainCol && (string)$gk === '') continue;
            $local[(string)$gk] = ($local[(string)$gk] ?? 0.0) + (float)$qty;
        }
        foreach ($fgByGroup as $gk => $qty) {
            if ($isPlainCol && (string)$gk === '') continue;
            $local[(string)$gk] = ($local[(string)$gk] ?? 0.0) + (float)$qty;
        }
        return $local;
    }

    /**
     * 把 getWinningTokens 结果拆成 (polyTuples, fgTuples)。
     * @param array<int, array{poly:string, fg:?string}> $winners
     * @return array{0: list<array{int,string}>, 1: list<array{int,string}>}
     */
    protected static function splitWinnerTuples(array $winners): array
    {
        $poly = $fg = [];
        foreach ($winners as $mcId => $w) {
            $poly[] = [(int)$mcId, (string)$w['poly']];
            if ($w['fg'] !== null && $w['fg'] !== '') {
                $fg[] = [(int)$mcId, (string)$w['fg']];
            }
        }
        return [$poly, $fg];
    }

    /**
     * 把 DataInsight 风格的 $extra (target_account_id / source_account_id / target_group_id) 拆成
     * 两组 extraWhere：target 端用什么 column / source 端用什么 column。
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    protected static function splitInsightExtra(array $extra): array
    {
        $targetExtra = [];
        $sourceExtra = [];
        if (!empty($extra['target_account_id'])) {
            $targetExtra['target_account_id'] = $extra['target_account_id'];
        }
        if (!empty($extra['source_account_id'])) {
            $sourceExtra['source_account_id'] = $extra['source_account_id'];
        }
        $tgid = $extra['target_group_id'] ?? null;
        if ($tgid !== null && $tgid !== '' && $tgid !== []) {
            $targetExtra['target_group_id'] = $tgid;
            $sourceExtra['target_group_id'] = $tgid;
        }
        return [$targetExtra, $sourceExtra];
    }

    /**
     * 在 ThinkPHP Query 上 apply extraWhere 数组：scalar → where()；array → whereIn()。
     * 数组为空 → whereRaw('1=0')（避免无意义的全表扫描）
     */
    protected static function applyExtraWhere($query, array $extra): void
    {
        foreach ($extra as $col => $val) {
            if (is_array($val)) {
                if ($val === []) {
                    $query->whereRaw('1=0');
                } else {
                    $query->whereIn($col, $val);
                }
            } else {
                $query->where($col, $val);
            }
        }
    }
}
