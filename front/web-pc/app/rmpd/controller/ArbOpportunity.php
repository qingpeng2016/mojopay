<?php
declare (strict_types=1);

namespace app\rmpd\controller;

use app\rmpd\const\Enum;
use app\rmpd\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 套利机会快照（只读评估，只留最新）
 *
 * arb_opportunity_snapshot 由上游 arb_opportunity_snapshot 任务每小时对所有 monitoring
 * 市场对只读检测：记录可执行边际(price_deviation)/顶档最优价差(top_price_deviation)/
 * 可套利量(arbitrable_qty)/预估净利(est_profit)，纯评估不下单。按 market_pair_id 唯一键 upsert。
 *
 * 【2026-07-03 口径调整】任务改为【只写 has_opportunity=1 或 price_deviation>0 的行】——
 * 无价差(=0)的对不再入表（占 ~99.9%），表从 5万+ 行缩到几十行。因此：
 *   - 快照行数 ≠ 监控中市场对数量；监控总数须从 market_pair(status=monitoring) 取。
 *   - "无价差"数量 = 监控总数 − 快照行数（见 buildStats()）。
 * 价差有两个口径：price_deviation=可执行边际（吃满量后≈利润门限，有机会的对都挤在门限附近、看不出宽窄）；
 * top_price_deviation=顶档最优价差（1-两腿最优卖价=真实最大价差，用于区分"宽机会 vs 勉强够本"）。
 *
 * 相关数据库表：arb_opportunity_snapshot、market_pair（监控总数）
 */
class ArbOpportunity extends Controller
{
    use SafeQueryTrait;

    /**
     * 套利机会快照列表
     * 排序（产品要求）：顶档最优价差 → 可执行边际 → 可套利量 → 预估净利 → 主键 id，全部降序。
     * 首排序键用 top_price_deviation：有机会的对 price_deviation 都≈利润门限（挤在一起排不出宽窄），
     * 顶档最优价差才能把"真正宽的机会"顶到最前。
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '套利机会快照';
        $get = $this->request->get();

        $query = Db::connect('db_rmpd')->name('arb_opportunity_snapshot');

        // ── 按市场对 ID 精确查找（供 market_pair 页下钻） ──────────────────
        if (!empty($get['market_pair_id'])) {
            $mpId = $this->sanitizeId($get['market_pair_id']);
            if ($mpId) {
                $query->where('market_pair_id', $mpId);
            }
        }

        // ── 按平台过滤（A腿 / B腿各自独立） ───────────────────────────────
        if (!empty($get['platform_a'])) {
            $pa = $this->sanitizeEnum($get['platform_a'], Enum::getAllPlatforms());
            if ($pa) {
                $query->where('platform_a', $pa);
            }
        }
        if (!empty($get['platform_b'])) {
            $pb = $this->sanitizeEnum($get['platform_b'], Enum::getAllPlatforms());
            if ($pb) {
                $query->where('platform_b', $pb);
            }
        }

        // ── 按依赖类型过滤 ────────────────────────────────────────────────
        if (!empty($get['dependency_type'])) {
            $dt = $this->sanitizeEnum($get['dependency_type'], Enum::getAllDepTypes());
            if ($dt) {
                $query->where('dependency_type', $dt);
            }
        }

        // ── 按是否存在机会过滤（has_opportunity 0/1） ────────────────────
        if (isset($get['has_opportunity']) && $get['has_opportunity'] !== '') {
            if (in_array($get['has_opportunity'], ['0', '1'], true)) {
                $query->where('has_opportunity', (int)$get['has_opportunity']);
            }
        }

        // ── 按套利方向过滤（buy_side A/B/NO_BOTH） ───────────────────────
        if (!empty($get['buy_side'])) {
            $bs = $this->sanitizeEnum($get['buy_side'], Enum::getAllOppSides());
            if ($bs) {
                $query->where('buy_side', $bs);
            }
        }

        // ── 按快照时间范围过滤 ────────────────────────────────────────────
        if (!empty($get['date_range'])) {
            if ($dates = $this->sanitizeDateRange($get['date_range'])) {
                [$start, $end] = $dates;
                $query->whereBetween('snapshot_time', ["{$start} 00:00:00", "{$end} 23:59:59"]);
            }
        }

        // 排序：顶档最优价差 desc → 可执行边际 desc → 可套利量 desc → 预估净利 desc → id desc（产品要求）
        $result = $query->order('top_price_deviation desc, price_deviation desc, arbitrable_qty desc, est_profit desc, id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? intval($get['pageSize']) : 20,
            'page'      => isset($get['page']) ? intval($get['page']) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/rmpd/arb_opportunity/index.html',
        ], false);

        $list = $result->items();

        foreach ($list as &$item) {
            foreach (['snapshot_time', 'created_at'] as $col) {
                $item[$col] = !empty($item[$col])
                    ? date('Y-m-d H:i:s', strtotime($item[$col]))
                    : '-';
            }
        }

        // ── 顶部统计（产品需求：领导加的统计卡片） ───────────────────────
        // 口径：统计「所有监控中的市场对」（全表，不随列表筛选变化）。
        // 快照表按 market_pair_id 唯一键 upsert（每对一行），故行数=监控中市场对数量。
        $stats = $this->buildStats();

        $this->assign([
            'list'      => $list,
            'pagehtml'  => $result->render(),
            'get'       => $get,
            'platforms' => Enum::getPlatformLabels(),
            'dep_types' => Enum::getDepTypeLabels(),
            'opp_sides' => Enum::getOppSideLabels(),
            'stats'     => $stats,
        ]);

        return $this->fetch();
    }

    /**
     * 计算顶部统计（全表口径，所有监控中市场对）：
     *   - monitoring_pairs：监控中市场对数量（取自 market_pair status=monitoring，**不是**快照行数）
     *   - arbitrable_pairs：可套利市场对数量（has_opportunity=1）
     *   - est_profit_sum：  可套利预估净利合计（Σest_profit，仅 has_opportunity=1）
     *   - deviation_dist：  顶档最优价差分布（无价差桶由「监控总数 − 快照行数」倒推）
     *
     * 【2026-07-03 口径调整】快照任务只写 has_opportunity=1 或 price_deviation>0 的行，
     * 无价差(=0)的对不入表。故：监控总数须从 market_pair 取；"无价差"数量 = 监控总数 − 快照行数。
     *
     * @return array
     */
    private function buildStats(): array
    {
        $conn = Db::connect('db_rmpd');

        // 监控总数取自 market_pair（快照表已不含无价差行，行数≠监控对数）。
        $monitoringPairs = $conn->name('market_pair')->where('status', Enum::MP_STATUS_MONITORING)->count();
        // 快照行数 = 有机会 或 有价差(>0) 的对数（无价差的对不入表）。
        $snapshotRows    = $conn->name('arb_opportunity_snapshot')->count();
        $arbitrablePairs = $conn->name('arb_opportunity_snapshot')->where('has_opportunity', 1)->count();
        // 只统计有套利机会（has_opportunity=1）的预估净利：低价差对扣手续费后可能为亏损，不计入。
        $estProfitSum    = (float)$conn->name('arb_opportunity_snapshot')->where('has_opportunity', 1)->sum('est_profit');

        // "无价差(=0)" = 监控总数 − 快照行数（这些对未入表）。防越界取 max(0,...)（快照残留陈旧行时可能短暂 >）。
        $eqZero = max(0, $monitoringPairs - $snapshotRows);

        // 价差分布改用**顶档最优价差**(top_price_deviation)分桶：有机会的对 price_deviation 都≈利润门限、
        // 挤在一起看不出宽窄；顶档最优价差=真实最大价差，才能区分"宽机会 vs 勉强够本"。
        // 步长与档位动态读取，不写死上界；正价差按 [i*step,(i+1)*step) 连续分桶。
        $mm = $conn->query("SELECT MAX(top_price_deviation) mx FROM arb_opportunity_snapshot");
        $maxDev = (float)($mm[0]['mx'] ?? 0);

        // 无价差桶（倒推得来，非表内查询）。表内行 top_price_deviation 恒 >0，无需负边际桶。
        $dist   = [];
        $dist[] = ['label' => '无价差 (=0)', 'count' => $eqZero];

        // 自适应步长：候选「整齐」步长里，选最小且能让正价差档数 ≤ 目标(约10)的那个。
        $step = $this->pickDeviationStep($maxDev, 8);
        if ($maxDev > 0 && $step > 0) {
            // 用整数微单位（top_price_deviation 为 DECIMAL(10,6)，*1e6 后为精确整数）避免浮点边界误差。
            $stepMicro = (int)round($step * 1000000);
            $rawRows = $conn->query(
                "SELECT FLOOR(ROUND(top_price_deviation*1000000)/{$stepMicro}) AS g, COUNT(*) AS c
                 FROM arb_opportunity_snapshot WHERE top_price_deviation > 0 GROUP BY g"
            );
            $byIdx = [];
            foreach ($rawRows as $r) {
                $byIdx[(int)$r['g']] = (int)$r['c'];
            }
            $maxIdx = (int)floor(round($maxDev * 1000000) / $stepMicro);
            for ($i = 0; $i <= $maxIdx; $i++) {
                $lo = $this->fmtDev($i * $step);
                $hi = $this->fmtDev(($i + 1) * $step);
                $dist[] = ['label' => "{$lo} ~ {$hi}", 'count' => $byIdx[$i] ?? 0];
            }
        }

        // 倒序：价差大的档位排前面（正价差从大到小 → 无价差(=0)）。
        $dist = array_reverse($dist);

        $maxCount = 0;
        foreach ($dist as $d) {
            if ($d['count'] > $maxCount) {
                $maxCount = $d['count'];
            }
        }

        return [
            'monitoring_pairs' => $monitoringPairs,
            'arbitrable_pairs' => $arbitrablePairs,
            'est_profit_sum'   => $estProfitSum,
            'deviation_step'   => $step,
            'deviation_dist'   => $dist,
            'deviation_max'    => $maxCount, // 供视图算条形宽度
        ];
    }

    /**
     * 自适应选取价差分桶步长：在「整齐」候选步长中，
     * 选最小且能使正价差 (0, $maxDev] 的档数 ≤ $targetBuckets 的步长。
     * $maxDev ≤ 0 时返回默认 0.01。
     */
    private function pickDeviationStep(float $maxDev, int $targetBuckets): float
    {
        $candidates = [0.005, 0.01, 0.02, 0.05, 0.1, 0.2, 0.5, 1.0];
        if ($maxDev <= 0) {
            return 0.01;
        }
        foreach ($candidates as $s) {
            if ((int)ceil($maxDev / $s) <= $targetBuckets) {
                return $s;
            }
        }
        // 价差异常大时兜底：直接按目标档数均分
        return $maxDev / $targetBuckets;
    }

    /**
     * 价差数值格式化为简洁标签（去掉多余尾零）：0.0100 → "0.01"，0 → "0"。
     */
    private function fmtDev(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
