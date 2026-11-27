<?php
declare (strict_types=1);

namespace app\rmpd\controller;

use app\rmpd\const\Enum;
use think\admin\Controller;
use think\facade\Db;

/**
 * PNL（Profit and Loss，盈亏）报表
 *
 * 这是整个 RMPD 模块最核心的监控仪表盘，分为几个区域：
 *
 * 1. 运营健康卡片：实时统计（直接查 arb_record）
 *    - 监控中市场对数、单腿暴露、总交易笔数、成功率
 *
 * 2. 资金盈亏卡片：全量累计统计
 *    - 总预估利润、累计实际利润、总投入资金、年化收益率、账户余额
 *
 * 3. 每日统计（近 7 天）：pnl_daily 最新 7 行（按日期 desc，最新日在前）
 *    - 每天交易数、成功数、成功率、实际利润、投入资金、ROI
 *
 * 4. 依赖类型分组统计：来自 pnl_daily_dim 维度表（默认近 30 天，可通过 URL ?days=7/30/90 切换）
 *
 * 重要设计说明：
 * - 卡片第一行/第二行的数据 → 直接查 arb_record，实时精确
 * - 近 7 天表 & 分组表 → 查 pnl_daily / pnl_daily_dim 快照，由每日 00:05 的定时任务生成
 *
 * 相关数据库表：market_pair, arb_record, pnl_daily, pnl_daily_dim, accounts
 */
class PnlReport extends Controller
{
    /**
     * PNL 报表主页
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = 'PNL 报表';

        $get  = $this->request->get();

        // 时间范围参数校验：只允许 7、30、90 三个合法值，默认 30 天。
        // 先用 ?? 兜底取值并转 int，再检查白名单，避免 truthy 分支再次访问 $get['days'] 触发未定义键告警。
        $days = (int)($get['days'] ?? 30);
        if (!in_array($days, [7, 30, 90], true)) {
            $days = 30;
        }

        // 复用数据库连接对象，减少重复代码
        $db = Db::connect('db_rmpd');

        // ══════════════════════════════════════════════════════════════
        // 第一行：运营健康卡片（全量实时数据，不受 $days 影响）
        // ══════════════════════════════════════════════════════════════

        // 监控中市场对数：status='monitoring' 的 market_pair 记录总数
        // (int) 把 count() 返回值强转整数（ThinkPHP count() 已经是整数，但显式转换更安全）
        $monitoringCount = (int)$db->name('market_pair')
            ->where('status', Enum::MP_STATUS_MONITORING)
            ->count();

        // 单腿暴露笔数：status='single_leg_exposed' 的 arb_record 记录数
        // 这是高优先级警报指标，该值 > 0 时页面会显示橙色警告样式
        $singleLegCount = (int)$db->name('arb_record')
            ->where('status', Enum::ARB_SINGLE_LEG_EXPOSED)
            ->count();

        // 总交易笔数：排除 pending 状态（pending 是等待执行的，尚未真正交易）
        // '<>' 是"不等于"运算符（等同于 !=，但更符合 SQL 风格）
        $totalTrades = (int)$db->name('arb_record')
            ->where('status', '<>', Enum::ARB_PENDING)
            ->count();

        // 成功交易笔数：包含已执行、已结算、单腿已平仓三种状态
        // whereIn('字段', [值列表]) 生成 WHERE status IN ('executed', 'completed', 'single_leg_closed')
        $successTrades = (int)$db->name('arb_record')
            ->whereIn('status', [Enum::ARB_EXECUTED, Enum::ARB_COMPLETED, Enum::ARB_SINGLE_LEG_CLOSED])
            ->count();

        // 成功率 = 成功数 / 总数 × 100，保留 1 位小数
        // 先判断 $totalTrades > 0 防止除以零（当没有任何交易记录时）
        // round($number, $decimals) 四舍五入到指定小数位数
        $successRate = $totalTrades > 0 ? round($successTrades / $totalTrades * 100, 1) : 0;

        // ══════════════════════════════════════════════════════════════
        // 第二行：资金盈亏卡片（全量累计）
        // ══════════════════════════════════════════════════════════════

        // 累计已实现利润：只统计已结算完结的交易（completed/single_leg_closed）。
        // 产品规则（2026-06）：arb_record 已用 realized_profit（已实现，未结算写 0）
        // 取代旧的 actual_profit 列；PNL 口径以"已实现利润"为准。
        $totalActualProfit = (float)$db->name('arb_record')
            ->whereIn('status', [Enum::ARB_COMPLETED, Enum::ARB_SINGLE_LEG_CLOSED])
            ->sum('realized_profit');

        // 总投入资金：双腿价格 × 成交量之和（A腿 + B腿 的资金总量）
        //
        // 为什么不用 sum() 而用 field()->find()？
        // ThinkPHP 的 sum('字段名') 只支持单个字段名，不支持表达式 "price_a * fill_qty_a"。
        // 解决方案：用 field('表达式 as 别名')->find() 执行聚合表达式查询，
        // 返回包含别名字段的关联数组。
        //
        // 等价 SQL：
        //   SELECT SUM(price_a * fill_qty_a + price_b * fill_qty_b) as total
        //   FROM arb_record
        //   WHERE status IN ('executed', 'completed', 'single_leg_closed')
        $capitalRow = $db->name('arb_record')
            ->whereIn('status', [Enum::ARB_EXECUTED, Enum::ARB_COMPLETED, Enum::ARB_SINGLE_LEG_CLOSED])
            ->field('SUM(price_a * fill_qty_a + price_b * fill_qty_b) as total')
            ->find();

        // $capitalRow['total'] ?? 0 → 如果结果为 null（无匹配行时）则用 0
        $totalCapital = (float)($capitalRow['total'] ?? 0);

        // 年化收益率：简单线性外推 = (实际利润 / 投入资金) × (365 / 运营天数) × 100
        // 注意：不计复利、不考虑资金周转、不限制最小样本天数；
        // 运营天数很短（几小时）时数字会被放大数百倍，需后续根据业务需求自行解读。
        $annualizedReturn = 'N/A';

        if ($totalCapital > 0) {
            // ->min('字段', false) 第二个参数 false 关闭 ThinkORM 默认的"强制转 float"，
            // 否则 DATETIME 字符串 "2026-05-14 ..." 会被转成 2026.0，
            // 在 strict_types=1 下传给 strtotime(string) 会抛 TypeError。
            $firstDate = $db->name('arb_record')->min('created_at', false);

            if ($firstDate) {
                $runDays = max(1, (int)ceil((time() - strtotime($firstDate)) / 86400));
                $annualizedReturn = round(
                    $totalActualProfit / $totalCapital * (365 / $runDays) * 100,
                    2
                ) . '%';
            }
        }

        // 账户余额相关数据：从 accounts 表读取各交易平台账户余额。
        // kalshi+poly 跨平台上线后，accounts 同时含 POLYMARKET 与 KALSHI 账户，
        // 卡片标题为「账户总余额」，故按平台白名单汇总两者（含各平台多子账户）。
        $accountVendors = ['POLYMARKET', 'KALSHI'];
        $accountBalance = (float)$db->name('accounts')->whereIn('vendor', $accountVendors)->sum('balance');

        // max('字段', false) 取该字段的最大值，第二个参数 false 关闭强制转 float，
        // 保持 DATETIME 字符串原样，否则视图里 strtotime() 会因类型不符抛 TypeError。
        $accountUpdatedAt = $db->name('accounts')->whereIn('vendor', $accountVendors)->max('updated_at', false);

        // ══════════════════════════════════════════════════════════════
        // 近 7 天每日统计：直接列 pnl_daily 最新 7 行（每日快照已聚合好）
        // 排序按 date desc，最近一天放最前面。
        // ══════════════════════════════════════════════════════════════

        $weeklyRows = $db->name('pnl_daily')
            ->order('date desc')
            ->limit(7)
            ->field('date, total_trades, executed_count, closed_count,
                     total_actual_profit, total_capital')
            ->select()
            ->toArray();

        // 为每行补两个派生字段：成功数（executed + closed）和成功率、ROI。
        // 与 arb_record 状态枚举里"成功"的定义保持一致：executed + single_leg_closed。
        foreach ($weeklyRows as &$row) {
            $trades    = (int)$row['total_trades'];
            $successes = (int)$row['executed_count'] + (int)$row['closed_count'];
            $capital   = (float)$row['total_capital'];
            $profit    = (float)$row['total_actual_profit'];

            $row['successes']    = $successes;
            $row['success_rate'] = $trades > 0 ? round($successes / $trades * 100, 1) . '%' : 'N/A';
            $row['roi']          = $capital > 0 ? round($profit / $capital * 100, 2) . '%' : 'N/A';
        }
        unset($row); // 解除最后一次循环留下的引用，避免后续误用

        // 计算时间范围起点（$days 天前的日期），用于下面的依赖类型分组统计。
        $since = date('Y-m-d', strtotime("-{$days} days"));

        // ══════════════════════════════════════════════════════════════
        // 按依赖类型分组统计：从 pnl_daily_dim 维度表汇总
        // ══════════════════════════════════════════════════════════════

        // pnl_daily_dim 是一个多维度的快照表，dim_type 区分不同维度。
        // 这里只取 dependency_type 维度（还可能有 assigned_machine 等其他维度）。
        //
        // 实际表结构中没有 total_trades / success_count 字段，而是按状态拆分了
        // executed_count / failed_count / closed_count 三列：
        //   总交易数 = executed_count + failed_count + closed_count
        //   成功数   = executed_count + closed_count  （与 arb_record 中"成功"的定义一致：
        //                                              executed + single_leg_closed）
        $dimRows = $db->name('pnl_daily_dim')
            ->where('dim_type', 'dependency_type')
            ->where('date', '>=', $since)
            ->field('dim_value,
                     SUM(executed_count + failed_count + closed_count) as trades,
                     SUM(executed_count + closed_count) as successes,
                     SUM(total_actual_profit) as actual_profit,
                     SUM(total_capital) as capital')
            ->group('dim_value')
            ->select()
            ->toArray();

        // 遍历分组结果，为每行计算成功率和 ROI（这些是派生值，不存在数据库中）
        foreach ($dimRows as &$dim) {
            // 三元运算：有交易才计算比率，否则显示 N/A
            $dim['success_rate'] = $dim['trades'] > 0
                ? round($dim['successes'] / $dim['trades'] * 100, 1) . '%'
                : 'N/A';
            $dim['roi'] = $dim['capital'] > 0
                ? round($dim['actual_profit'] / $dim['capital'] * 100, 2) . '%'
                : 'N/A';
        }

        // ══════════════════════════════════════════════════════════════
        // 把所有变量传给模板
        // ══════════════════════════════════════════════════════════════

        // compact() 是 PHP 内置函数，根据变量名字符串创建关联数组。
        // compact('days', 'monitoringCount') 等价于：
        //   ['days' => $days, 'monitoringCount' => $monitoringCount]
        // 用 compact 可以避免重复写 '变量名' => $变量名 这种冗余代码。
        $this->assign(compact(
            'days',                 // 当前选中的时间范围（7/30/90）
            'monitoringCount',      // 监控中市场对数
            'singleLegCount',       // 单腿暴露数
            'totalTrades',          // 总交易笔数
            'successRate',          // 总成功率（百分比数字）
            'totalActualProfit',    // 累计实际利润（float）
            'totalCapital',         // 总投入资金（float）
            'annualizedReturn',     // 年化收益率（字符串，含%）
            'accountBalance',       // 账户总余额
            'accountUpdatedAt',     // 账户信息更新时间
            'weeklyRows',           // 近 7 天每日统计（最新日在前）
            'dimRows'               // 按依赖类型分组统计（数组）
        ));

        return $this->fetch();
    }
}
