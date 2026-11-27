<?php
declare(strict_types=1);

namespace app\cool_pay2\controller;

use app\cool_pay2\service\ShardManager;
use app\cool_pay2\trait\PnlCalcTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 做市仪表盘（数据洞察）
 *
 * 多分片实现（2026-05-19）：
 *   - 所有原始 Db::name(...) 改成 Db::connect($conn)->name(...) 并放进 ShardManager::fanOutRead 闭包
 *   - 应用层合并：scalar 求和 / map 按 key 求和 / 排名先合并 PNL → 再排序
 *   - market_config_id 是分片本地自增 ID，绝不在跨片间传递；mc_id 永远在分片闭包内部就地解析
 *   - target_group_id 是全局唯一字符串，可以安全跨片
 *
 * 与产品文档对齐的口径（2026-05-15 PNL 公式更新版）：
 * - 「未对冲份额」：FG 与 Poly 已成交（FILLED）数量差；未对冲卖出 = FG SELL − Poly BUY，未对冲买入 = FG BUY − Poly SELL；Top 按 outcome 汇总降序。
 * - PNL（趋势 / 排行榜 / 核心指标）走 {@see PnlCalcTrait} 头注释中的新公式。
 * - 趋势图「日」粒度按小时聚合；「周/月」按自然日聚合。
 * - 最大单市场亏损卡片：聚合粒度为 target_token_id（outcome）。
 */
class DataInsight extends Controller
{
    use PnlCalcTrait;

    /**
     * per-request 缓存：resolveMappingIdsOnShard 在 makeShardExtra 里每次调用 PNL 时被反复触发，
     * 同一请求内 (conn, targetGroupId, allowedGroups) 不变时结果一定相同。
     * key = conn + '|' + targetGroupId + '|' + allowedGroups 签名（null=__ALL__）
     * @var array<string, list<int|string>|null>
     */
    private static array $mappingIdsCache = [];

    /**
     * 做市仪表盘
     * @auth true
     */
    public function index()
    {
        // 数据洞察跨片扫百万 trader 行，默认 PHP max_execution_time=30 会超时。
        // 临时抬到 180s（3 分钟），便于与 runtime static_pnl 预聚合结果对拍；上线预聚合读表后可去掉。
        @set_time_limit(180);
        $this->title = '做市仪表盘';
        $get = $this->request->get();

        $rangePreset = $get['range_preset'] ?? '30';
        [$rangeStart, $rangeEnd] = $this->resolveRange($rangePreset, $get);
        $targetGroupId = isset($get['target_group_id']) && $get['target_group_id'] !== ''
            ? trim((string)$get['target_group_id']) : '';
        $marketCategory = isset($get['market_category']) && $get['market_category'] !== ''
            ? trim((string)$get['market_category']) : '';
        $marketSubCategory = isset($get['market_sub_category']) && $get['market_sub_category'] !== ''
            ? trim((string)$get['market_sub_category']) : '';
        $allowedGroups = $this->allowedTargetGroupIds($marketCategory, $marketSubCategory);

        $unhedged = $this->unhedgedPositionStats();

        // 性能优化（2026-05-20）：原版一次性算 day/week/month 三个 period 的 chart+rank
        // = 6 次 buildShardPnlByGroup × N 分片，prod 上单页 ~50s。
        // 现在初始只算默认显示的 day；week/month 由前端 tab 切换时通过 period() AJAX 拉取。
        [$dayChart, $dayRank] = $this->buildPeriodPayload('day');
        $chartData = ['day' => $dayChart];
        $rankData  = ['day' => $dayRank];

        $cards = $this->coreMetrics($rangeStart, $rangeEnd, '', $targetGroupId, $allowedGroups);

        $categories = [
            'Sports', 'Politics', 'Crypto', 'Finance', 'Geopolitics', 'Earnings',
            'Tech', 'Culture', 'World', 'Economy', 'Climate & Science', 'Elections', 'Mentions',
        ];

        $this->assign([
            'get'               => $get,
            'range_preset'      => $rangePreset,
            'range_start'       => $rangeStart,
            'range_end'         => $rangeEnd,
            'unhedged'          => $unhedged,
            'chart_data_json'   => json_encode($chartData, JSON_UNESCAPED_UNICODE),
            'rank_data_json'    => json_encode($rankData, JSON_UNESCAPED_UNICODE),
            'cards'             => $cards,
            'market_categories' => $categories,
        ]);

        return $this->fetch();
    }

    /**
     * 全局趋势图 + 排行榜 AJAX（按 period 懒加载）
     *
     * 前端在 day tab 初始已渲染；切到 week/month tab 时按需拉取本接口。
     * 不受中部维度切换器影响（与 index 顶部图同口径：全局 / 不过滤）。
     *
     * @auth true
     */
    public function period()
    {
        @set_time_limit(180); // 见 index() 说明：周/月 period 跨片聚合亦可能 >30s
        $get = $this->request->get();
        $period = (string)($get['period'] ?? '');
        if (!in_array($period, ['day', 'week', 'month'], true)) {
            $this->error('invalid period');
            return;
        }
        [$chart, $rank] = $this->buildPeriodPayload($period);
        $this->success('ok', ['chart' => $chart, 'rank' => $rank]);
    }

    /**
     * 算单个 period 的 chart series + market ranking。
     * day = 当日按小时；week/month = 按自然日。全局口径（不带筛选）。
     *
     * @return array{0:array{labels:list<string>,daily:list<float>,cum:list<float>},1:array{top:list<array{market_id:string,pnl:float}>,bottom:list<array{market_id:string,pnl:float}>}}
     */
    private function buildPeriodPayload(string $period): array
    {
        $today = date('Y-m-d');
        $start = match ($period) {
            'day'   => $today,
            'week'  => date('Y-m-d', strtotime('-6 days')),
            'month' => date('Y-m-d', strtotime('-29 days')),
        };
        if ($period === 'day') {
            $series = $this->hourlyPnlSeries($today, '', '', null);
        } else {
            $series = $this->dailyPnlSeries($start, $today, '', '', null);
        }
        $chart = [
            'labels' => array_column($series, 'd'),
            'daily'  => array_column($series, 'pnl'),
            'cum'    => array_column($series, 'cum'),
        ];
        [$top, $bottom] = $this->marketRankings($start, $today, '', '', null, 10);
        return [$chart, ['top' => $top, 'bottom' => $bottom]];
    }

    /**
     * 核心指标卡片 AJAX
     */
    public function cards()
    {
        @set_time_limit(180); // 见 index() 说明：带筛选的核心指标卡跨片聚合亦可能 >30s
        $get = $this->request->get();
        if (($get['only_unhedged'] ?? '') === '1') {
            $this->success('ok', $this->unhedgedPositionStats());
            return;
        }
        $rangePreset = $get['range_preset'] ?? '30';
        [$rangeStart, $rangeEnd] = $this->resolveRange($rangePreset, $get);
        $targetGroupId = isset($get['target_group_id']) && $get['target_group_id'] !== ''
            ? trim((string)$get['target_group_id']) : '';
        $marketCategory = isset($get['market_category']) && $get['market_category'] !== ''
            ? trim((string)$get['market_category']) : '';
        $marketSubCategory = isset($get['market_sub_category']) && $get['market_sub_category'] !== ''
            ? trim((string)$get['market_sub_category']) : '';
        $allowedGroups = $this->allowedTargetGroupIds($marketCategory, $marketSubCategory);
        $cards = $this->coreMetrics($rangeStart, $rangeEnd, '', $targetGroupId, $allowedGroups);
        $this->success('查询成功', $cards);
    }

    /** 是否读 runtime static_pnl 预聚合的 stat 表。默认读表；DATAINSIGHT_READ_STAT=0 回退实时计算（用于对拍）。 */
    private function readFromStat(): bool
    {
        return getenv('DATAINSIGHT_READ_STAT') !== '0';
    }

    /**
     * 未对冲份额（读表版）：直接读各片 stat_unhedged + stat_pnl_run_meta，app 层按 outcome 合并。
     * 口径与实时版一致：stat_unhedged 已是各片按 (token, side) 聚合的 FG−Poly 数量差(>0)。
     */
    private function unhedgedPositionStatsFromStat(): array
    {
        $perShard = ShardManager::fanOutRead(function (string $conn) {
            $rows = Db::connect($conn)->name('stat_unhedged')
                ->field('target_token_id, side, qty')->select()->toArray();
            $marketCount = (int)Db::connect($conn)->name('stat_pnl_run_meta')
                ->where('id', 1)->value('market_count_active');
            return ['rows' => $rows, 'marketCount' => $marketCount];
        });

        $marketCount = 0;
        $totalSell = 0.0;
        $totalBuy  = 0.0;
        $sellByOutcome = [];
        $buyByOutcome  = [];
        foreach ($perShard as $r) {
            $marketCount += (int)$r['marketCount'];
            foreach ($r['rows'] as $row) {
                $qty = (float)$row['qty'];
                $tok = (string)($row['target_token_id'] ?? '');
                if ($tok === '') continue;
                if ($row['side'] === 'SELL') {
                    $totalSell += $qty;
                    $sellByOutcome[$tok] = ($sellByOutcome[$tok] ?? 0.0) + $qty;
                } else {
                    $totalBuy += $qty;
                    $buyByOutcome[$tok] = ($buyByOutcome[$tok] ?? 0.0) + $qty;
                }
            }
        }
        arsort($sellByOutcome);
        arsort($buyByOutcome);

        $topSell = [];
        foreach (array_slice($sellByOutcome, 0, 5, true) as $tok => $qty) {
            $topSell[] = ['outcome_id' => $tok, 'outcome_short' => $this->shortenTokenId($tok), 'val' => round((float)$qty, 4)];
        }
        $topBuy = [];
        foreach (array_slice($buyByOutcome, 0, 5, true) as $tok => $qty) {
            $topBuy[] = ['outcome_id' => $tok, 'outcome_short' => $this->shortenTokenId($tok), 'val' => round((float)$qty, 4)];
        }

        return [
            'market_count'        => $marketCount,
            'total_unhedged_sell' => round($totalSell, 4),
            'total_unhedged_buy'  => round($totalBuy, 4),
            'total_unhedged_all'  => round($totalSell + $totalBuy, 4),
            'top_sell_outcomes'   => $topSell,
            'top_buy_outcomes'    => $topBuy,
        ];
    }

    /**
     * 未对冲份额：每片各算 + app 层按 outcome 合并。
     */
    private function unhedgedPositionStats(): array
    {
        if ($this->readFromStat()) {
            return $this->unhedgedPositionStatsFromStat();
        }
        $sqlTpl = <<<SQL
SELECT market_config_id, target_token_id, 'SELL' AS side,
       (t_qty - IFNULL(s_qty, 0)) AS qty_diff
FROM (
    SELECT market_config_id, target_token_id, SUM(trade_quantity) AS t_qty
    FROM target_trader_records
    WHERE order_side = 'SELL' AND status = 'FILLED'
      AND target_token_id IS NOT NULL AND target_token_id <> ''
      AND market_config_id IN (
          SELECT id FROM market_mapping_config
          WHERE source_status = 'ACTIVE' AND target_status = 'ACTIVE'
      )
    GROUP BY market_config_id, target_token_id
) t
LEFT JOIN (
    SELECT market_config_id, target_token_id, SUM(trade_quantity) AS s_qty
    FROM source_trader_records
    WHERE order_side = 'BUY' AND status = 'FILLED'
      AND target_token_id IS NOT NULL AND target_token_id <> ''
      AND market_config_id IN (
          SELECT id FROM market_mapping_config
          WHERE source_status = 'ACTIVE' AND target_status = 'ACTIVE'
      )
    GROUP BY market_config_id, target_token_id
) s USING (market_config_id, target_token_id)
WHERE (t_qty - IFNULL(s_qty, 0)) > 0

UNION ALL

SELECT market_config_id, target_token_id, 'BUY' AS side,
       (t_qty - IFNULL(s_qty, 0)) AS qty_diff
FROM (
    SELECT market_config_id, target_token_id, SUM(trade_quantity) AS t_qty
    FROM target_trader_records
    WHERE order_side = 'BUY' AND status = 'FILLED'
      AND target_token_id IS NOT NULL AND target_token_id <> ''
      AND market_config_id IN (
          SELECT id FROM market_mapping_config
          WHERE source_status = 'ACTIVE' AND target_status = 'ACTIVE'
      )
    GROUP BY market_config_id, target_token_id
) t
LEFT JOIN (
    SELECT market_config_id, target_token_id, SUM(trade_quantity) AS s_qty
    FROM source_trader_records
    WHERE order_side = 'SELL' AND status = 'FILLED'
      AND target_token_id IS NOT NULL AND target_token_id <> ''
      AND market_config_id IN (
          SELECT id FROM market_mapping_config
          WHERE source_status = 'ACTIVE' AND target_status = 'ACTIVE'
      )
    GROUP BY market_config_id, target_token_id
) s USING (market_config_id, target_token_id)
WHERE (t_qty - IFNULL(s_qty, 0)) > 0
SQL;

        $perShard = ShardManager::fanOutRead(function (string $conn) use ($sqlTpl) {
            $marketCount = (int)Db::connect($conn)->name('market_mapping_config')
                ->where('source_status', 'ACTIVE')
                ->where('target_status', 'ACTIVE')
                ->count('DISTINCT target_group_id');
            $rows = Db::connect($conn)->query($sqlTpl);
            return ['marketCount' => $marketCount, 'rows' => $rows];
        });

        $marketCount = 0;
        $totalSell = 0.0;
        $totalBuy  = 0.0;
        $sellByOutcome = [];
        $buyByOutcome  = [];
        foreach ($perShard as $r) {
            $marketCount += $r['marketCount'];
            foreach ($r['rows'] as $row) {
                $diff = (float)$row['qty_diff'];
                $tok  = (string)($row['target_token_id'] ?? '');
                if ($tok === '') continue;
                if ($row['side'] === 'SELL') {
                    $totalSell += $diff;
                    $sellByOutcome[$tok] = ($sellByOutcome[$tok] ?? 0.0) + $diff;
                } else {
                    $totalBuy += $diff;
                    $buyByOutcome[$tok] = ($buyByOutcome[$tok] ?? 0.0) + $diff;
                }
            }
        }
        arsort($sellByOutcome);
        arsort($buyByOutcome);

        $topSell = [];
        foreach (array_slice($sellByOutcome, 0, 5, true) as $tok => $qty) {
            $topSell[] = [
                'outcome_id'    => $tok,
                'outcome_short' => $this->shortenTokenId($tok),
                'val'           => round((float)$qty, 4),
            ];
        }
        $topBuy = [];
        foreach (array_slice($buyByOutcome, 0, 5, true) as $tok => $qty) {
            $topBuy[] = [
                'outcome_id'    => $tok,
                'outcome_short' => $this->shortenTokenId($tok),
                'val'           => round((float)$qty, 4),
            ];
        }

        return [
            'market_count'         => $marketCount,
            'total_unhedged_sell'  => round($totalSell, 4),
            'total_unhedged_buy'   => round($totalBuy, 4),
            'total_unhedged_all'   => round($totalSell + $totalBuy, 4),
            'top_sell_outcomes'    => $topSell,
            'top_buy_outcomes'     => $topBuy,
        ];
    }

    private function shortenTokenId($tokenId): string
    {
        $tokenId = trim((string)$tokenId);
        if ($tokenId === '') return '-';
        if (strlen($tokenId) <= 28) return $tokenId;
        return substr($tokenId, 0, 12) . '…' . substr($tokenId, -10);
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function resolveRange(string $preset, array $get): array
    {
        $end = date('Y-m-d');
        if ($preset === 'custom' && !empty($get['range_start']) && !empty($get['range_end'])) {
            $s = (string)$get['range_start'];
            $e = (string)$get['range_end'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $e) && $s <= $e) {
                return [$s, $e];
            }
        }
        $days = match ($preset) {
            '7' => 7,
            '90' => 90,
            default => 30,
        };
        return [date('Y-m-d', strtotime('-' . $days . ' days')), $end];
    }

    /**
     * 跨片求 target_group_id 集合（category / sub_category 筛选下）。
     * target_group_id 是全局唯一字符串，跨片可安全合并。
     *
     * @return list<string>|null null = 无筛选；[] = 没有匹配的市场
     */
    protected function allowedTargetGroupIds(string $category, string $subCategory): ?array
    {
        if ($category === '' && $subCategory === '') return null;
        $perShard = ShardManager::fanOutRead(function (string $conn) use ($category, $subCategory) {
            $q = Db::connect($conn)->name('market_mapping_config')->whereRaw('1=1');
            if ($category !== '') $q->where('market_category', $category);
            if ($subCategory !== '') $q->where('market_sub_category', $subCategory);
            return array_values(array_filter(array_unique($q->column('target_group_id'))));
        });
        $merged = [];
        foreach ($perShard as $list) {
            foreach ($list as $tgid) {
                $merged[(string)$tgid] = true;
            }
        }
        return array_keys($merged);
    }

    /**
     * 在指定分片上求 market_config_id 列表（按 target_group_id / allowedGroups 筛选）。
     * 注意：返回值仅在该分片上下文内有效，绝对不能跨片传递。
     *
     * @return list<int|string>|null null = 无筛选；[] = 该片上无匹配
     */
    private function resolveMappingIdsOnShard(string $conn, string $targetGroupId, ?array $allowedGroups): ?array
    {
        if ($targetGroupId === '' && $allowedGroups === null) return null;

        if ($allowedGroups === null) {
            $agSig = '__ALL__';
        } elseif ($allowedGroups === []) {
            return [];
        } else {
            $sorted = array_map('strval', $allowedGroups);
            sort($sorted);
            $agSig = md5(implode("\0", $sorted));
        }
        $cacheKey = $conn . '|' . $targetGroupId . '|' . $agSig;
        if (array_key_exists($cacheKey, self::$mappingIdsCache)) {
            return self::$mappingIdsCache[$cacheKey];
        }

        $q = Db::connect($conn)->name('market_mapping_config')->whereRaw('1=1');
        if ($targetGroupId !== '') $q->where('target_group_id', $targetGroupId);
        if ($allowedGroups !== null) {
            $q->whereIn('target_group_id', $allowedGroups);
        }
        return self::$mappingIdsCache[$cacheKey] = array_values(array_unique($q->column('id')));
    }

    /**
     * @return array<string,float> target_group_id => pnl
     */
    private function combinedPnlByMarket(
        string $startDate,
        string $endDate,
        string $targetAccountId,
        string $targetGroupId,
        ?array $allowedGroups
    ): array {
        return $this->combinedPnlByGroup(
            'target_group_id', $startDate, $endDate, $targetAccountId, $targetGroupId, $allowedGroups
        );
    }

    /**
     * @return array<string,float> target_token_id => pnl
     */
    private function combinedPnlByToken(
        string $startDate,
        string $endDate,
        string $targetAccountId,
        string $targetGroupId,
        ?array $allowedGroups
    ): array {
        return $this->combinedPnlByGroup(
            'target_token_id', $startDate, $endDate, $targetAccountId, $targetGroupId, $allowedGroups
        );
    }

    /**
     * 通用按列分组的跨片 PNL（读表版）：group→stat_pnl_market_daily，token→stat_pnl_token_daily。
     * 按 [stat_date 区间] + [target_group_id / allowedGroups 筛选] 求和，app 层按 key 合并（group/token 跨片唯一）。
     * 口径与实时版一致：stat 表建表时 market_daily 已排除空 group、token_daily 已排除空 token。
     */
    private function combinedPnlByGroupFromStat(
        string $groupCol,
        string $startDate,
        string $endDate,
        string $targetGroupId,
        ?array $allowedGroups
    ): array {
        if ($allowedGroups === []) return []; // category/subcategory 筛选无匹配市场
        $table = $groupCol === 'target_token_id' ? 'stat_pnl_token_daily' : 'stat_pnl_market_daily';
        return ShardManager::fanOutSumByKey(function (string $conn) use (
            $table, $groupCol, $startDate, $endDate, $targetGroupId, $allowedGroups
        ) {
            $q = Db::connect($conn)->name($table)->whereBetween('stat_date', [$startDate, $endDate]);
            if ($targetGroupId !== '') $q->where('target_group_id', $targetGroupId);
            if ($allowedGroups !== null) $q->whereIn('target_group_id', $allowedGroups);
            $rows = $q->field($groupCol . ' AS k, SUM(pnl) AS pnl')->group($groupCol)->select()->toArray();
            $out = [];
            foreach ($rows as $r) {
                $out[(string)$r['k']] = (float)$r['pnl'];
            }
            return $out;
        });
    }

    /**
     * 通用按列分组的跨片 PNL。每片各算 → app 层按 groupCol 的 key 求和。
     * 把分片闭包内的 Part 1/2 全部委托给 PnlCalcTrait::buildShardPnlByGroup。
     */
    private function combinedPnlByGroup(
        string $groupCol,
        string $startDate,
        string $endDate,
        string $targetAccountId,
        string $targetGroupId,
        ?array $allowedGroups
    ): array {
        if ($this->readFromStat()) {
            return $this->combinedPnlByGroupFromStat($groupCol, $startDate, $endDate, $targetGroupId, $allowedGroups);
        }
        $t0 = $startDate . ' 00:00:00';
        $t1 = $endDate . ' 23:59:59';
        return ShardManager::fanOutSumByKey(function (string $conn) use (
            $groupCol, $t0, $t1, $targetAccountId, $targetGroupId, $allowedGroups
        ) {
            return $this->buildShardPnlByGroup(
                $conn, $groupCol, $t0, $t1,
                $this->makeShardExtra($conn, $targetAccountId, $targetGroupId, $allowedGroups)
            );
        });
    }

    /**
     * 构造 buildShardPnl(ByGroup) 用的 $extra：双侧账户 + 全局 target_group_id 范围 + 本片 mc_ids。
     */
    private function makeShardExtra(
        string $conn,
        string $targetAccountId,
        string $targetGroupId,
        ?array $allowedGroups
    ): array {
        $extra = [];
        if ($targetAccountId !== '') {
            // FG 端列名 target_account_id，Poly 端列名 source_account_id，但 admin 这里两边复用同一个 account id
            $extra['target_account_id'] = $targetAccountId;
            $extra['source_account_id'] = $targetAccountId;
        }
        if ($targetGroupId !== '') {
            $extra['target_group_id'] = $targetGroupId;
        } elseif ($allowedGroups !== null) {
            $extra['target_group_id'] = $allowedGroups;
        }
        $extra['mc_ids_local'] = $this->resolveMappingIdsOnShard($conn, $targetGroupId, $allowedGroups);
        return $extra;
    }

    /**
     * @return list<array{d:string,pnl:float,cum:float}>
     */
    private function dailyPnlSeries(
        string $startDate,
        string $endDate,
        string $targetAccountId,
        string $targetGroupId,
        ?array $allowedGroups
    ): array {
        if ($this->readFromStat()) {
            $byDay = ShardManager::fanOutSumByKey(function (string $conn) use ($startDate, $endDate) {
                $rows = Db::connect($conn)->name('stat_pnl_hourly')
                    ->whereBetween('stat_date', [$startDate, $endDate])
                    ->field('stat_date AS d, SUM(pnl) AS pnl')->group('stat_date')->select()->toArray();
                $m = [];
                foreach ($rows as $r) {
                    $m[substr((string)$r['d'], 0, 10)] = (float)$r['pnl'];
                }
                return $m;
            });
        } else {
            $t0 = $startDate . ' 00:00:00';
            $t1 = $endDate . ' 23:59:59';
            $byDay = ShardManager::fanOutSumByKey(function (string $conn) use (
                $t0, $t1, $targetAccountId, $targetGroupId, $allowedGroups
            ) {
                return $this->buildShardPnlByGroup(
                    $conn, 'DATE(created_at)', $t0, $t1,
                    $this->makeShardExtra($conn, $targetAccountId, $targetGroupId, $allowedGroups)
                );
            });
        }

        $out = [];
        $cum = 0.0;
        $cur = strtotime($startDate);
        $endTs = strtotime($endDate);
        while ($cur <= $endTs) {
            $d   = date('Y-m-d', $cur);
            $pnl = $byDay[$d] ?? 0.0;
            $cum += $pnl;
            $out[] = ['d' => $d, 'pnl' => round($pnl, 4), 'cum' => round($cum, 4)];
            $cur = strtotime('+1 day', $cur);
        }
        return $out;
    }

    /**
     * @return list<array{d:string,pnl:float,cum:float}>
     */
    private function hourlyPnlSeries(
        string $dayDate,
        string $targetAccountId,
        string $targetGroupId,
        ?array $allowedGroups
    ): array {
        if ($this->readFromStat()) {
            $byHour = ShardManager::fanOutSumByKey(function (string $conn) use ($dayDate) {
                $rows = Db::connect($conn)->name('stat_pnl_hourly')
                    ->where('stat_date', $dayDate)
                    ->field('hour AS h, SUM(pnl) AS pnl')->group('hour')->select()->toArray();
                $m = [];
                foreach ($rows as $r) {
                    $m[(string)(int)$r['h']] = (float)$r['pnl'];
                }
                return $m;
            });
        } else {
            $t0 = $dayDate . ' 00:00:00';
            $t1 = $dayDate . ' 23:59:59';
            $byHour = ShardManager::fanOutSumByKey(function (string $conn) use (
                $t0, $t1, $targetAccountId, $targetGroupId, $allowedGroups
            ) {
                return $this->buildShardPnlByGroup(
                    $conn, 'HOUR(created_at)', $t0, $t1,
                    $this->makeShardExtra($conn, $targetAccountId, $targetGroupId, $allowedGroups)
                );
            });
        }

        $out = [];
        $cum = 0.0;
        for ($h = 0; $h <= 23; ++$h) {
            $pnl = $byHour[(string)$h] ?? 0.0;
            $cum += $pnl;
            $out[] = [
                'd'   => sprintf('%02d:00', $h),
                'pnl' => round($pnl, 4),
                'cum' => round($cum, 4),
            ];
        }
        return $out;
    }

    /**
     * @return array{0:list<array{market_id:string,pnl:float}>,1:list<array{market_id:string,pnl:float}>}
     */
    private function marketRankings(
        string $startDate,
        string $endDate,
        string $targetAccountId,
        string $targetGroupId,
        ?array $allowedGroups,
        int $limit
    ): array {
        $combined = $this->combinedPnlByMarket($startDate, $endDate, $targetAccountId, $targetGroupId, $allowedGroups);
        $profitRows = [];
        $lossRows   = [];
        foreach ($combined as $mid => $pnl) {
            $val = (float)$pnl;
            if ($val > 0) {
                $profitRows[] = ['mid' => $mid, 'pnl' => $val];
            } elseif ($val < 0) {
                $lossRows[] = ['mid' => $mid, 'pnl' => $val];
            }
        }
        usort($profitRows, static fn($a, $b) => $b['pnl'] <=> $a['pnl']);
        $top = [];
        foreach (array_slice($profitRows, 0, $limit) as $r) {
            $top[] = ['market_id' => (string)$r['mid'], 'pnl' => (float)$r['pnl']];
        }
        usort($lossRows, static fn($a, $b) => $a['pnl'] <=> $b['pnl']);
        $bottom = [];
        foreach (array_slice($lossRows, 0, $limit) as $r) {
            $bottom[] = ['market_id' => (string)$r['mid'], 'pnl' => (float)$r['pnl']];
        }
        return [$top, $bottom];
    }

    /**
     * @return array<string, mixed>
     */
    protected function coreMetrics(
        string $startDate,
        string $endDate,
        string $targetAccountId,
        string $targetGroupId,
        ?array $allowedGroups
    ): array {
        $byMarket = $this->combinedPnlByMarket($startDate, $endDate, $targetAccountId, $targetGroupId, $allowedGroups);
        $totalPnl    = array_sum($byMarket);
        $marketCount = count($byMarket);
        $win = 0;
        $lose = 0;
        foreach ($byMarket as $p) {
            if ($p > 0) ++$win;
            elseif ($p < 0) ++$lose;
        }

        $byToken = $this->combinedPnlByToken($startDate, $endDate, $targetAccountId, $targetGroupId, $allowedGroups);
        $minPnl = null;
        $minTokenId = '';
        foreach ($byToken as $tokenId => $p) {
            if ($minPnl === null || $p < $minPnl) {
                $minPnl = $p;
                $minTokenId = (string)$tokenId;
            }
        }
        $hasLoss = $minPnl !== null && $minPnl < 0;

        $avg     = $marketCount > 0 ? $totalPnl / $marketCount : 0.0;
        $winPct  = $marketCount > 0 ? ($win / $marketCount) * 100 : 0.0;
        $losePct = $marketCount > 0 ? ($lose / $marketCount) * 100 : 0.0;

        return [
            'single_market'     => false,
            'total_pnl'         => $totalPnl,
            'total_pnl_fmt'     => number_format($totalPnl, 2),
            'avg_pnl_fmt'       => number_format($avg, 2),
            'market_count'      => $marketCount,
            'win_count'         => $win,
            'lose_count'        => $lose,
            'win_pct_fmt'       => number_format($winPct, 1),
            'lose_pct_fmt'      => number_format($losePct, 1),
            'max_loss_fmt'      => $hasLoss ? number_format($minPnl, 2) : '0.00',
            'max_loss_id'       => $hasLoss ? $minTokenId : '',
            'max_loss_id_short' => $hasLoss ? $this->shortenTokenId($minTokenId) : '',
        ];
    }
}
