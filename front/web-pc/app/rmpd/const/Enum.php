<?php
declare (strict_types=1);

namespace app\rmpd\const;
// 这个文件属于 app\rmpd\const 命名空间（子命名空间，类似子文件夹）。

/**
 * 全局枚举常量类
 *
 * 为什么要用这个类？
 * 如果代码里到处写 'review'、'monitoring' 这样的字符串，
 * 一旦拼写出错（如 'reviw'），PHP 不会报错，查 Bug 会很难。
 * 把这些字符串集中定义为常量，IDE 可以自动补全，拼写错会直接报错。
 */
class Enum
{
    // ══════════════════════════════════════════════════════════════
    // market_pair 表的 status 字段取值
    // 代表"市场对"从 AI 发现到最终过期的生命周期状态
    // ══════════════════════════════════════════════════════════════

    // const 定义"类常量"（不可修改的值）。
    // 类常量与变量（$var）的区别：常量没有 $ 前缀，且一经定义不能改变。
    const MP_STATUS_REVIEW     = 'review';      // 待人工审核
    const MP_STATUS_AI_REVIEW  = 'ai_review';   // AI 复核中，同样等待人工确认（与 review 同为"待复核"，区别在来源）
    const MP_STATUS_MONITORING = 'monitoring';  // 审核通过，策略机器人正在监控套利机会
    const MP_STATUS_REJECTED   = 'rejected';    // 人工审核拒绝，不再使用
    const MP_STATUS_EXPIRED    = 'expired';     // 市场对应的 Polymarket 问题已截止，自动过期
    const MP_STATUS_ARCHIVED   = 'archived';    // 已归档（过期/失效后由策略归档，生产库中数量最多，只读留档）

    // ══════════════════════════════════════════════════════════════
    // market_pair 表的 review_action 字段取值
    // 记录人工审核时的操作结果
    // ══════════════════════════════════════════════════════════════

    const MP_ACTION_APPROVED = 'approved'; // 审核动作：通过
    const MP_ACTION_REJECTED = 'rejected'; // 审核动作：拒绝（注意：与 MP_STATUS_REJECTED 值相同，但语义不同）

    // ══════════════════════════════════════════════════════════════
    // market_pair 表的 dependency_type 字段取值
    // 代表两个市场问题之间的逻辑关系类型，决定套利策略方向
    // ══════════════════════════════════════════════════════════════

    const DEP_TYPE_SUBSET           = 'subset';           // 子集关系：A 发生则 B 必然发生
    const DEP_TYPE_IMPLIES          = 'implies';          // 蕴含关系：A 为真则 B 必然为真
    const DEP_TYPE_MUTUAL_EXCLUSIVE = 'mutual_exclusive'; // 互斥关系：A 和 B 不能同时为真
    const DEP_TYPE_EQUIVALENT       = 'equivalent';       // 等价关系：A 和 B 结果必然相同
    const DEP_TYPE_NONE             = 'none';             // 无关系（AI 未识别出逻辑依赖）

    // ══════════════════════════════════════════════════════════════
    // 交易平台（market.platform / market_pair.platform_a|b / arb_record.platform_a|b）
    // 平台唯一真源；跨平台对的 platform_a ≠ platform_b。
    // 生产库现存值仅 polymarket / kalshi 两类（2026-06-29 SHOW DISTINCT 实测）。
    // ══════════════════════════════════════════════════════════════

    const PLATFORM_POLYMARKET = 'polymarket';
    const PLATFORM_KALSHI     = 'kalshi';

    // ══════════════════════════════════════════════════════════════
    // market_pair 表的 detect_source 字段取值
    // 市场对的"发现来源"：AI 扫描产出 vs 代码规则检测器产出
    // 注：rule_* 是开放式前缀（未来可能新增 rule_<其他域>），
    //     列表展示与过滤对未知 rule_ 值都有兜底处理。
    // ══════════════════════════════════════════════════════════════

    const DETECT_SOURCE_AI             = 'ai';                // AI 扫描产出（默认）
    const DETECT_SOURCE_RULE_THRESHOLD = 'rule_threshold';   // 规则：通用阈值单调蕴含（加密/黄金/大宗/FDV 等 "above/over $N" 序列）
    const DETECT_SOURCE_RULE_PLACEMENT = 'rule_placement';   // 规则：名次嵌套子集（同主体同赛事 win ⊂ Top5 ⊂ … ⊂ make cut）
    const DETECT_SOURCE_RULE_DEADLINE  = 'rule_deadline';    // 规则：截止时点单调（同事件 "X by 早 date" ⊂ "X by 晚 date"）
    const DETECT_SOURCE_RULE_COUNT     = 'rule_count';       // 规则：计数单调（同主体 "k+ 计数" ⟹ "j+ 计数"，k>j）
    const DETECT_SOURCE_RULE_FAMILY    = 'rule_family';      // 规则：同主体跨措辞确定性依赖（如 夺冠 ⟹ 进季后赛）
    const DETECT_SOURCE_RULE_SCORE     = 'rule_score';       // 规则：精确比分 ⟹ 结果（同场 Exact Score X-Y ⊂ 胜/平/先进球）
    const DETECT_SOURCE_RULE_WEATHER   = 'rule_weather';     // 规则：跨平台天气（美国城市°F 高/低温，同城同日 equivalent / 跨阈值 subset）
    const DETECT_SOURCE_RULE_CRYPTO    = 'rule_crypto';      // 规则：加密价格阈值（首个 rule_ 检测器，crypto 域）
    const DETECT_SOURCE_RULE_STREAMS   = 'rule_streams';     // 规则：音乐播放量（艺人 streams 计数单调蕴含）
    const DETECT_SOURCE_RULE_RANKING   = 'rule_ranking';     // 规则：榜单排名嵌套（同榜单 rank 越小越严）
    const DETECT_SOURCE_RULE_ADVANCE   = 'rule_advance';     // 规则：赛事晋级轮次单调（同队 round 越深越难）
    const DETECT_SOURCE_RULE_WCPROP    = 'rule_wcprop';      // 规则：世界杯球员道具（同球员 prop 阈值单调）
    const DETECT_SOURCE_RULE_ELECTION  = 'rule_election';    // 规则：选举提名/胜选跨事件确定性依赖
    const DETECT_SOURCE_RULE_GEOEVENT  = 'rule_geoevent';    // 规则：地缘事件截止时点单调（同事件 by 早 date ⊂ by 晚 date）
    const DETECT_SOURCE_RULE_MLBRFI    = 'rule_mlbrfi';      // 规则：MLB 首局得分（同场 first-inning-run 跨措辞 equivalent）
    const DETECT_SOURCE_RULE_CHAMPION  = 'rule_champion';    // 规则：体育夺冠（同赛事 champion 确定性蕴含）
    // Kalshi 专属域（上游 0eefcea「新增4规则」，生产暂未落数据，先放进下拉备用）
    const DETECT_SOURCE_RULE_KXCRYPTOPT = 'rule_kxcryptopt'; // 规则：Kalshi 加密点位（crypto price point 阈值单调）
    const DETECT_SOURCE_RULE_KXINDEX    = 'rule_kxindex';    // 规则：Kalshi 指数（index 阈值单调）
    const DETECT_SOURCE_RULE_IPODATE    = 'rule_ipodate';    // 规则：Kalshi IPO 日期（同公司上市 by 早 date ⊂ by 晚 date）
    const DETECT_SOURCE_RULE_KXBUCKET   = 'rule_kxbucket';   // 规则：Kalshi 区间桶（ticker bucket 区间嵌套）
    const DETECT_SOURCE_BACKFILL_SEMANTIC = 'backfill_semantic'; // 历史语义回填（早期数据补写来源）

    // ══════════════════════════════════════════════════════════════
    // arb_record 表的 status 字段取值
    // 代表每一笔套利交易从创建到结算的完整状态机
    // ══════════════════════════════════════════════════════════════

    const ARB_PENDING            = 'pending';            // 已创建，等待执行机器人处理
    const ARB_EXECUTED           = 'executed';           // 双腿订单均已下单（但未必全部成交）
    const ARB_COMPLETED          = 'completed';          // 双腿均成交并完成结算，交易完结
    const ARB_CANCELLED          = 'cancelled';          // 执行前被取消（价差消失、风控拦截等）
    const ARB_FAILED             = 'failed';             // 执行过程中出错（API 超时、余额不足等）
    const ARB_SINGLE_LEG_EXPOSED = 'single_leg_exposed'; // 危险状态：只有一条腿成交，存在单边风险敞口
    const ARB_SINGLE_LEG_CLOSED  = 'single_leg_closed';  // 已对单腿暴露进行平仓处理
    const ARB_ARCHIVED           = 'archived';           // 已归档：终态交易归档留档（生产库中存在）

    // ══════════════════════════════════════════════════════════════
    // ai_call_log 表的 scan_mode 字段取值
    // 当前在跑的两种 AI 扫描模式（不再是早期的 incremental 增量扫描）
    // 注：策略侧已多模型化，model 字段可能是 claude-sonnet-4-6 / deepseek-v4-flash 等
    //     （费率见 ai_model 表，按 name 引用）。
    // ══════════════════════════════════════════════════════════════

    const SCAN_MODE_ARB_AI_SCAN    = 'arb_ai_scan';    // 套利机会 AI 扫描（在 monitoring 市场对上找套利机会）
    const SCAN_MODE_AI_PAIR_REVIEW = 'ai_pair_review'; // 市场对依赖关系 AI 审核（产生 market_pair）
    // 注：早期还有 incremental（增量，2026-05-30 停用）/ full_batch_N_of_M（一次性批量）等历史值，
    //     已不在搜索下拉中提供（老日志列表仍按原始值展示），故不再定义为常量。

    // ══════════════════════════════════════════════════════════════
    // arb_trade_records 表字段取值（fill 级成交流水，PNL 唯一数据源）
    // ══════════════════════════════════════════════════════════════

    // leg：成交腿 / 阶段（建仓 BUILD / 平仓 CLOSE / 结算 SETTLE，各分 A/B 腿）
    const TRADE_LEG_BUILD_A  = 'BUILD_A';
    const TRADE_LEG_BUILD_B  = 'BUILD_B';
    const TRADE_LEG_CLOSE_A  = 'CLOSE_A';
    const TRADE_LEG_CLOSE_B  = 'CLOSE_B';
    const TRADE_LEG_SETTLE_A = 'SETTLE_A';
    const TRADE_LEG_SETTLE_B = 'SETTLE_B';

    // side：CLOB 真实买卖方向（注意：与 arb_record 的 YES/NO 语义不同）
    const TRADE_SIDE_BUY  = 'BUY';
    const TRADE_SIDE_SELL = 'SELL';

    // data_source：流水来源（下单占位 / 链上回捞真实成交 / 结算虚拟）
    const TRADE_SRC_PENDING_CAPTURE  = 'pending_capture';  // 下单登记占位行
    const TRADE_SRC_GET_TRADES       = 'get_trades';       // 链上回捞真实成交
    const TRADE_SRC_SETTLE_INFERENCE = 'settle_inference'; // 结算推断虚拟行

    // ══════════════════════════════════════════════════════════════
    // 以下是工具方法（static 静态方法，可以不实例化类直接调用）
    // 调用方式：Enum::getAllMpStatuses()
    // static 方法不能访问 $this，但可以访问 self:: 引用本类的常量
    // ══════════════════════════════════════════════════════════════

    /**
     * 返回 market_pair.status 的所有合法值（用于输入校验白名单）
     * 返回类型 array 表示这个方法一定返回一个数组。
     */
    public static function getAllMpStatuses(): array
    {
        // self:: 在静态方法中引用当前类自身（类似实例方法中的 $this->）
        return [
            self::MP_STATUS_REVIEW,
            self::MP_STATUS_AI_REVIEW,
            self::MP_STATUS_MONITORING,
            self::MP_STATUS_REJECTED,
            self::MP_STATUS_EXPIRED,
            self::MP_STATUS_ARCHIVED,
        ];
    }

    /**
     * 可"审核通过"的状态白名单（待复核态）。
     * 产品规则（2026-06-11）：review 与 ai_review 同为待复核，均可点击同意。
     */
    public static function getApprovableStatuses(): array
    {
        return [
            self::MP_STATUS_REVIEW,
            self::MP_STATUS_AI_REVIEW,
        ];
    }

    /**
     * 可"拒绝"的状态白名单（非终态）。
     * 产品规则（2026-06-11）：监控中 monitoring、待复核 review/ai_review 等非终态均可拒绝；
     * rejected/expired/archived 为终态，不可再拒绝。
     */
    public static function getRejectableStatuses(): array
    {
        return [
            self::MP_STATUS_REVIEW,
            self::MP_STATUS_AI_REVIEW,
            self::MP_STATUS_MONITORING,
        ];
    }

    /**
     * 返回 dependency_type 的所有合法值（用于输入校验白名单）
     */
    public static function getAllDepTypes(): array
    {
        return [
            self::DEP_TYPE_SUBSET,
            self::DEP_TYPE_IMPLIES,
            self::DEP_TYPE_MUTUAL_EXCLUSIVE,
            self::DEP_TYPE_EQUIVALENT,
            self::DEP_TYPE_NONE,
        ];
    }

    /**
     * 返回 arb_record.status 的所有合法值（用于输入校验白名单）
     */
    public static function getAllArbStatuses(): array
    {
        return [
            self::ARB_PENDING,
            self::ARB_EXECUTED,
            self::ARB_COMPLETED,
            self::ARB_CANCELLED,
            self::ARB_FAILED,
            self::ARB_SINGLE_LEG_EXPOSED,
            self::ARB_SINGLE_LEG_CLOSED,
            self::ARB_ARCHIVED,
        ];
    }

    /**
     * 返回 dependency_type 的"值 => 中文显示名"映射（用于下拉框和表格展示）
     *
     * 返回格式：
     *   [ 'subset' => '子集 (subset)', 'implies' => '蕴含 (implies)', ... ]
     *
     * 这是一个"关联数组"（associative array），PHP 数组可以用字符串作为 key（索引）。
     * 普通数组 key 是 0, 1, 2...；关联数组 key 是自定义字符串。
     */
    public static function getDepTypeLabels(): array
    {
        return [
            // => 是 PHP 数组中键值对的分隔符，左边是 key，右边是 value
            self::DEP_TYPE_SUBSET           => '子集 (subset)',
            self::DEP_TYPE_IMPLIES          => '蕴含 (implies)',
            self::DEP_TYPE_MUTUAL_EXCLUSIVE => '互斥 (mutual_exclusive)',
            self::DEP_TYPE_EQUIVALENT       => '等价 (equivalent)',
            self::DEP_TYPE_NONE             => '无 (none)',
        ];
    }

    /**
     * 返回 detect_source 的"值 => 中文显示名"映射（用于搜索下拉框和列表展示）。
     * rule_* 为开放式前缀，下拉只提供当前在跑的已知来源；
     * 列表展示对未知 rule_ 值有 strpos 前缀兜底（详见 market_pair/index.html）。
     */
    public static function getDetectSourceLabels(): array
    {
        return [
            self::DETECT_SOURCE_AI                => 'AI 扫描 (ai)',
            self::DETECT_SOURCE_RULE_THRESHOLD    => '规则·阈值 (rule_threshold)',
            self::DETECT_SOURCE_RULE_PLACEMENT    => '规则·名次 (rule_placement)',
            self::DETECT_SOURCE_RULE_DEADLINE     => '规则·截止 (rule_deadline)',
            self::DETECT_SOURCE_RULE_COUNT        => '规则·计数 (rule_count)',
            self::DETECT_SOURCE_RULE_FAMILY       => '规则·家族 (rule_family)',
            self::DETECT_SOURCE_RULE_SCORE        => '规则·比分 (rule_score)',
            self::DETECT_SOURCE_RULE_WEATHER      => '规则·天气 (rule_weather)',
            self::DETECT_SOURCE_RULE_CRYPTO       => '规则·加密 (rule_crypto)',
            self::DETECT_SOURCE_RULE_STREAMS      => '规则·播放量 (rule_streams)',
            self::DETECT_SOURCE_RULE_RANKING      => '规则·榜单 (rule_ranking)',
            self::DETECT_SOURCE_RULE_ADVANCE      => '规则·晋级 (rule_advance)',
            self::DETECT_SOURCE_RULE_WCPROP       => '规则·世界杯道具 (rule_wcprop)',
            self::DETECT_SOURCE_RULE_ELECTION     => '规则·选举 (rule_election)',
            self::DETECT_SOURCE_RULE_GEOEVENT     => '规则·地缘事件 (rule_geoevent)',
            self::DETECT_SOURCE_RULE_MLBRFI       => '规则·MLB首局 (rule_mlbrfi)',
            self::DETECT_SOURCE_RULE_CHAMPION     => '规则·夺冠 (rule_champion)',
            self::DETECT_SOURCE_RULE_KXCRYPTOPT   => '规则·K加密点位 (rule_kxcryptopt)',
            self::DETECT_SOURCE_RULE_KXINDEX      => '规则·K指数 (rule_kxindex)',
            self::DETECT_SOURCE_RULE_IPODATE      => '规则·K上市日 (rule_ipodate)',
            self::DETECT_SOURCE_RULE_KXBUCKET     => '规则·K区间桶 (rule_kxbucket)',
            self::DETECT_SOURCE_BACKFILL_SEMANTIC => '历史回填 (backfill_semantic)',
        ];
    }

    /**
     * 返回 detect_source 的所有合法值（用于 sanitizeEnum 白名单校验）。
     */
    public static function getAllDetectSources(): array
    {
        return array_keys(self::getDetectSourceLabels());
    }

    /**
     * 返回 detect_source 的简短中文标签（用于列表徽章，节省宽度；hover title 显示完整值）。
     */
    public static function getDetectSourceShortLabels(): array
    {
        return [
            self::DETECT_SOURCE_AI                => 'AI',
            self::DETECT_SOURCE_RULE_THRESHOLD    => '阈值',
            self::DETECT_SOURCE_RULE_PLACEMENT    => '名次',
            self::DETECT_SOURCE_RULE_DEADLINE     => '截止',
            self::DETECT_SOURCE_RULE_COUNT        => '计数',
            self::DETECT_SOURCE_RULE_FAMILY       => '家族',
            self::DETECT_SOURCE_RULE_SCORE        => '比分',
            self::DETECT_SOURCE_RULE_WEATHER      => '天气',
            self::DETECT_SOURCE_RULE_CRYPTO       => '加密',
            self::DETECT_SOURCE_RULE_STREAMS      => '播放量',
            self::DETECT_SOURCE_RULE_RANKING      => '榜单',
            self::DETECT_SOURCE_RULE_ADVANCE      => '晋级',
            self::DETECT_SOURCE_RULE_WCPROP       => '世界杯',
            self::DETECT_SOURCE_RULE_ELECTION     => '选举',
            self::DETECT_SOURCE_RULE_GEOEVENT     => '地缘',
            self::DETECT_SOURCE_RULE_MLBRFI       => 'MLB',
            self::DETECT_SOURCE_RULE_CHAMPION     => '夺冠',
            self::DETECT_SOURCE_RULE_KXCRYPTOPT   => 'K加密',
            self::DETECT_SOURCE_RULE_KXINDEX      => 'K指数',
            self::DETECT_SOURCE_RULE_IPODATE      => 'K上市',
            self::DETECT_SOURCE_RULE_KXBUCKET     => 'K桶',
            self::DETECT_SOURCE_BACKFILL_SEMANTIC => '回填',
        ];
    }

    // ── 平台标签与白名单 ────────────────────────────────────────────

    /**
     * 返回交易平台 platform 的"值 => 显示名"映射（用于筛选下拉框）。
     * 仅 polymarket / kalshi 两类（生产库实测）。
     */
    public static function getPlatformLabels(): array
    {
        return [
            self::PLATFORM_POLYMARKET => 'Polymarket',
            self::PLATFORM_KALSHI     => 'Kalshi',
        ];
    }

    /**
     * 返回 platform 的所有合法值（用于 sanitizeEnum 白名单校验）。
     */
    public static function getAllPlatforms(): array
    {
        return array_keys(self::getPlatformLabels());
    }

    // ── arb_opportunity_snapshot 套利方向 buy_side 标签与白名单 ──────────

    // buy_side：本轮套利建议方向（A=买A腿 / B=买B腿 / NO_BOTH=两腿都不买；无机会时为空串）
    const OPP_SIDE_A       = 'A';
    const OPP_SIDE_B       = 'B';
    const OPP_SIDE_NO_BOTH = 'NO_BOTH';

    /**
     * 返回 buy_side 的"值 => 显示名"映射（用于筛选下拉）。
     */
    public static function getOppSideLabels(): array
    {
        return [
            self::OPP_SIDE_A       => '买A腿 (A)',
            self::OPP_SIDE_B       => '买B腿 (B)',
            self::OPP_SIDE_NO_BOTH => '两腿皆不买 (NO_BOTH)',
        ];
    }

    /**
     * 返回 buy_side 的所有合法值（用于 sanitizeEnum 白名单校验）。
     */
    public static function getAllOppSides(): array
    {
        return array_keys(self::getOppSideLabels());
    }

    /**
     * 返回 ai_call_log.scan_mode 的"值 => 中文显示名"映射（用于搜索下拉框）。
     * 只提供当前在跑的两种模式；历史值（incremental 等）不再进下拉。
     */
    public static function getScanModeLabels(): array
    {
        return [
            self::SCAN_MODE_ARB_AI_SCAN    => '套利扫描 (arb_ai_scan)',
            self::SCAN_MODE_AI_PAIR_REVIEW => '市场对审核 (ai_pair_review)',
        ];
    }

    /**
     * 返回 scan_mode 的所有合法值（用于 sanitizeEnum 白名单校验）。
     */
    public static function getAllScanModes(): array
    {
        return array_keys(self::getScanModeLabels());
    }

    // ── arb_trade_records 标签与白名单 ──────────────────────────────

    /**
     * 返回成交腿 leg 的"值 => 中文显示名"映射（下拉框 + 列表展示）。
     */
    public static function getTradeLegLabels(): array
    {
        return [
            self::TRADE_LEG_BUILD_A  => '建仓·A腿 (BUILD_A)',
            self::TRADE_LEG_BUILD_B  => '建仓·B腿 (BUILD_B)',
            self::TRADE_LEG_CLOSE_A  => '平仓·A腿 (CLOSE_A)',
            self::TRADE_LEG_CLOSE_B  => '平仓·B腿 (CLOSE_B)',
            self::TRADE_LEG_SETTLE_A => '结算·A腿 (SETTLE_A)',
            self::TRADE_LEG_SETTLE_B => '结算·B腿 (SETTLE_B)',
        ];
    }

    public static function getAllTradeLegs(): array
    {
        return array_keys(self::getTradeLegLabels());
    }

    /**
     * 返回买卖方向 side 的"值 => 中文显示名"映射。
     */
    public static function getTradeSideLabels(): array
    {
        return [
            self::TRADE_SIDE_BUY  => '买入 (BUY)',
            self::TRADE_SIDE_SELL => '卖出 (SELL)',
        ];
    }

    public static function getAllTradeSides(): array
    {
        return array_keys(self::getTradeSideLabels());
    }

    /**
     * 返回流水来源 data_source 的"值 => 中文显示名"映射。
     */
    public static function getTradeSourceLabels(): array
    {
        return [
            self::TRADE_SRC_PENDING_CAPTURE  => '下单占位 (pending_capture)',
            self::TRADE_SRC_GET_TRADES       => '链上成交 (get_trades)',
            self::TRADE_SRC_SETTLE_INFERENCE => '结算虚拟 (settle_inference)',
        ];
    }

    public static function getAllTradeSources(): array
    {
        return array_keys(self::getTradeSourceLabels());
    }
}
