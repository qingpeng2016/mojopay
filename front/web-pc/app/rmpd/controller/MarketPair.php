<?php
declare (strict_types=1);

namespace app\rmpd\controller;

use app\rmpd\const\Enum;
use app\rmpd\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 市场对审核管理
 *
 * 业务流程背景：
 * AI 扫描 Polymarket 上的预测市场问题，发现问题之间的逻辑依赖关系
 * （如问题 A 和问题 B 互斥），并在 market_pair 表中创建一条记录，
 * 状态设为 'review' 或 'ai_review'（均为待复核）。
 *
 * 运营人员在这个页面对市场对进行人工复核（产品规则 2026-06-11）：
 * - 通过（approve）→ 仅待复核态（review / ai_review）可通过，状态改为 'monitoring'，策略机器人开始监控
 * - 拒绝（reject） → 任何非终态（review / ai_review / monitoring）均可拒绝，状态改为 'rejected'
 *   （rejected / expired / archived 为终态，不可再操作）
 *
 * 相关数据库表：market_pair
 */
class MarketPair extends Controller
{
    use SafeQueryTrait;

    /**
     * 市场对列表（默认显示待审核的 review 状态）
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '市场对审核';
        $get = $this->request->get();

        // 默认队列状态集合（产品规则 2026-06-13）：仅显示 review（待人工审核）。
        // ai_review（AI 复核中）、monitoring（监控中）以及终态 rejected/expired/archived
        // 默认都不显示，需在状态下拉里单独选。
        $defaultStatuses = [Enum::MP_STATUS_REVIEW]; // ['review']

        // 精确 ID 查询（供 arb_record 页点击市场对ID跳转使用）。
        // 注意：arb_record 引用的市场对几乎都是 monitoring/rejected/expired，
        // 不会是 review，所以一旦指定了精确 ID，就必须跳过下面"默认只看 review"
        // 的状态过滤，否则下钻永远查不到目标行。
        $exactId = !empty($get['id']) ? $this->sanitizeId($get['id']) : null;

        // 是否处于默认队列视图（决定排序：默认视图按 confidence 从高到低）。
        // 默认（未传 status / 传空）→ review 队列；但精确 ID 查询不算默认视图。
        $isDefaultView = (!isset($get['status']) || $get['status'] === '') && !$exactId;

        $query = Db::connect('db_rmpd')->name('market_pair');

        // ── 过滤条件 1：按 ID 精确查找 ───────────────────────────────────────

        if ($exactId) {
            $query->where('id', $exactId);
        }

        // ── 过滤条件 2：按问题文本关键词搜索 ─────────────────────────────────

        if (!empty($get['question'])) {
            $kw = $this->sanitizeLike($get['question']); // 转义 LIKE 特殊字符

            // 闭包（Closure）：function($q) use ($kw) { ... }
            // PHP 中用 function 定义匿名函数（没有函数名的函数），
            // use ($kw) 表示把外部变量 $kw 的值"捕获"进闭包内部使用。
            // 这里用闭包实现 WHERE (question_a LIKE '%kw%' OR question_b LIKE '%kw%')
            // 如果没有闭包，多个 where 之间默认是 AND 关系，
            // 用闭包可以把多个条件用括号括起来变成 OR 关系。
            $query->where(function ($q) use ($kw) {
                $q->where('question_a', 'like', "%{$kw}%")  // 在问题A中搜索
                  ->whereOr('question_b', 'like', "%{$kw}%"); // 或者在问题B中搜索
            });
        }

        // ── 过滤条件 3：按依赖类型过滤 ─────────────────────────────────────

        if (!empty($get['dependency_type'])) {
            $dt = $this->sanitizeEnum($get['dependency_type'], Enum::getAllDepTypes());
            if ($dt) {
                $query->where('dependency_type', $dt);
            }
        }

        // ── 过滤条件 4：按状态过滤 ────────────────────────────────────────

        // 默认视图（未选具体状态）：仅显示 review（待人工审核）。
        // 选了具体状态：精确过滤该状态（sanitizeEnum 防注入）。
        if ($isDefaultView) {
            $query->whereIn('status', $defaultStatuses);
        } else {
            $status = $this->sanitizeEnum($get['status'] ?? '', Enum::getAllMpStatuses());
            if ($status) {
                $query->where('status', $status);
            }
            // 如果 $status 为 null（用户传入非法值），不加 status 过滤 → 显示全部（容错）。
        }

        // ── 过滤条件 5：按发现来源过滤 ────────────────────────────────────

        // detect_source：市场对的发现来源（ai / rule_crypto…）。
        // 用 sanitizeEnum 做白名单精确校验（非法值忽略，不加过滤 → 显示全部）。
        if (!empty($get['detect_source'])) {
            $ds = $this->sanitizeEnum($get['detect_source'], Enum::getAllDetectSources());
            if ($ds) {
                $query->where('detect_source', $ds);
            }
        }

        // ── 过滤条件 5b：按平台过滤（A腿 / B腿，平台字段各自独立筛选） ──────

        // platform_a / platform_b 是平台唯一真源（polymarket|kalshi）。
        // 产品要求：有几个平台字段就给几个筛选项，故 A腿、B腿各一个下拉。
        // sanitizeEnum 白名单校验，非法值忽略（不加过滤）。
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

        // ── 过滤条件 6：按创建时间范围过滤 ─────────────────────────────────

        if (!empty($get['date_range'])) {
            if ($dates = $this->sanitizeDateRange($get['date_range'])) {
                [$start, $end] = $dates;
                $query->whereBetween('created_at', ["{$start} 00:00:00", "{$end} 23:59:59"]);
            }
        }

        // 排序：
        //   默认队列（review） → 按 AI 置信度从高到低（confidence desc），同置信度再按 ID 倒序。
        //     领导要求：优先处理高置信度的市场对（越可能是真实依赖）。
        //   选了具体状态 → 仍按 ID 倒序（最新的排最前面）。
        $orderBy = $isDefaultView
            ? 'confidence desc, id desc'
            : 'id desc';

        $result = $query->order($orderBy)->paginate([
            'list_rows' => isset($get['pageSize']) ? intval($get['pageSize']) : 20,
            'page'      => isset($get['page']) ? intval($get['page']) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/rmpd/market_pair/index.html',
        ], false);

        $list = $result->items();

        foreach ($list as &$item) {
            $item['created_at']  = !empty($item['created_at'])
                ? date('Y-m-d H:i:s', strtotime($item['created_at']))
                : '-';

            // reviewed_at 是审核时间，review 状态的记录此字段为空
            $item['reviewed_at'] = !empty($item['reviewed_at'])
                ? date('Y-m-d H:i:s', strtotime($item['reviewed_at']))
                : '-';

            // updated_at：生产表新增字段（on update CURRENT_TIMESTAMP）
            $item['updated_at'] = !empty($item['updated_at'])
                ? date('Y-m-d H:i:s', strtotime($item['updated_at']))
                : '-';
        }

        $this->assign([
            'list'      => $list,
            'pagehtml'  => $result->render(),
            'get'       => $get,
            'statuses'  => $this->getStatusLabels(),
            'dep_types' => Enum::getDepTypeLabels(),
            'detect_sources'       => Enum::getDetectSourceLabels(),
            'detect_source_short'  => Enum::getDetectSourceShortLabels(),
            'platforms'            => Enum::getPlatformLabels(),
        ]);

        return $this->fetch();
    }

    /**
     * 审核通过：将待复核（review / ai_review）市场对状态改为 monitoring
     *
     * 产品规则（2026-06-11）：review 与 ai_review 同为"待复核"，均可同意。
     *
     * 支持两种调用方式：
     *   GET  ?id=123           → 审核单条记录
     *   POST ids=1,2,3         → 批量审核多条记录
     *
     * @auth true  → 需要登录权限
     */
    public function approve()
    {
        // resolveIds() 是私有辅助方法，负责从请求中提取 ID 列表（见下方）
        $ids  = $this->resolveIds();

        // $this->request->param() 同时从 GET 和 POST 中读取参数（比 get() 更通用）
        // trim() 去除字符串首尾的空白字符（空格、换行等）
        // (string) 是类型强转，把任意值转成字符串，防止 param() 返回 null 时 trim 报错
        $note = trim((string)$this->request->param('review_note', ''));

        if (empty($ids)) {
            // $this->error() 返回 JSON 错误响应，格式：{"code": 0, "info": "消息", ...}
            // 前端 JS 收到 code=0 时会弹出错误提示框
            return $this->error('请选择要审核的记录');
        }

        // 执行 UPDATE 语句：
        //   UPDATE market_pair
        //   SET status='monitoring', review_action='approved', review_note=?, reviewed_at=?
        //   WHERE id IN (1,2,3)
        //   AND status IN ('review','ai_review')   ← 这个状态白名单非常重要！
        //
        // 为什么要加这个 status 白名单？
        // 1) 终态保护：只把仍处于待复核态的行改为 monitoring，已处理/已归档的行不受影响。
        // 2) 防止并发覆盖：两个运营同时打开同一批记录，A 先点通过（已改为 monitoring），
        //    B 后点拒绝时，因 status 已不在待复核白名单内，更新行数为 0，安全跳过。
        $affected = Db::connect('db_rmpd')->name('market_pair')
            ->whereIn('id', $ids)               // WHERE id IN (...)
            ->whereIn('status', Enum::getApprovableStatuses()) // AND status IN ('review','ai_review')
            ->update([
                'status'        => Enum::MP_STATUS_MONITORING, // 改为监控中
                'review_action' => Enum::MP_ACTION_APPROVED,   // 记录操作为"通过"
                'review_note'   => $note,                      // 保存审核备注
                'reviewed_at'   => date('Y-m-d H:i:s'),        // 记录审核时间（当前时间）
            ]);

        // $affected 是实际更新的行数（受 status 白名单影响，可能小于 $ids 数量）
        $skipped = count($ids) - $affected; // count() 返回数组长度

        $msg = "成功处理 {$affected} 条";
        if ($skipped > 0) {
            $msg .= "，跳过 {$skipped} 条（非待复核状态）";
        }

        // $this->success() 返回 JSON 成功响应，前端收到后弹出成功提示并刷新列表
        return $this->success($msg);
    }

    /**
     * 审核拒绝：将市场对状态从 review → rejected
     *
     * 逻辑与 approve() 基本相同，区别仅在于目标状态和操作标记。
     *
     * @auth true
     */
    public function reject()
    {
        $ids  = $this->resolveIds();
        $note = trim((string)$this->request->param('review_note', ''));

        if (empty($ids)) {
            return $this->error('请选择要拒绝的记录');
        }

        $affected = Db::connect('db_rmpd')->name('market_pair')
            ->whereIn('id', $ids)
            // 并发安全 + 终态保护：只更新仍处于非终态（review/ai_review/monitoring）的记录
            ->whereIn('status', Enum::getRejectableStatuses())
            ->update([
                'status'        => Enum::MP_STATUS_REJECTED,
                'review_action' => Enum::MP_ACTION_REJECTED,
                'review_note'   => $note,
                'reviewed_at'   => date('Y-m-d H:i:s'),
            ]);

        $skipped = count($ids) - $affected;
        $msg = "已拒绝 {$affected} 条";
        if ($skipped > 0) {
            $msg .= "，跳过 {$skipped} 条（终态不可拒绝）";
        }

        return $this->success($msg);
    }

    // ── 私有辅助方法 ──────────────────────────────────────────────────────────

    /**
     * 从请求中提取 ID 列表
     *
     * GET 请求（单条）：?id=123      → 返回 [123]
     * POST 请求（批量）：ids=1,2,3   → 返回 [1, 2, 3]
     *
     * @return array 正整数 ID 数组（空数组表示没有合法 ID）
     */
    private function resolveIds(): array
    {
        if ($this->request->isPost()) {
            // isPost() 判断当前请求是否是 HTTP POST 请求
            $raw = (string)$this->request->post('ids', '');
            // post('ids', '') 从 POST 请求体中读取 ids 字段，默认值为 ''

            // explode(',', $raw) 把字符串按逗号分割成数组：'1,2,3' → ['1', '2', '3']
            // array_map('intval', ...) 对数组每个元素应用 intval 转换：['1','2','3'] → [1,2,3]
            // array_filter(...) 过滤数组，默认去除"假值"元素（0, false, null, ''）
            //   即去掉 intval('abc')=0 这类非法输入
            // array_values(...) 重新索引数组（array_filter 会保留原来的 key，可能造成间隙）
            return array_values(array_filter(array_map('intval', explode(',', $raw))));
        } else {
            // GET 请求：从 URL 参数中读取单个 id
            $id = $this->sanitizeId($this->request->get('id'));
            // 三元运算：$id 存在且合法则返回 [$id]，否则返回空数组 []
            return $id ? [$id] : [];
        }
    }

    /**
     * 返回市场对状态的"值 => 中文标签"映射，供下拉框使用。
     */
    private function getStatusLabels(): array
    {
        return [
            Enum::MP_STATUS_REVIEW     => '待复核',
            Enum::MP_STATUS_AI_REVIEW  => 'AI待复核',
            Enum::MP_STATUS_MONITORING => '监控中',
            Enum::MP_STATUS_REJECTED   => '已拒绝',
            Enum::MP_STATUS_EXPIRED    => '已过期',
            Enum::MP_STATUS_ARCHIVED   => '已归档',
        ];
    }
}
