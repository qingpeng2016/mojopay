<?php
declare (strict_types=1);

namespace app\rmpd\controller;

use app\rmpd\const\Enum;
// 引入 Enum 枚举常量类，用于获取状态值和白名单列表。
// use 语句让我们可以直接写 Enum::ARB_PENDING 而不用写完整路径
// app\rmpd\const\Enum::ARB_PENDING。

use app\rmpd\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 套利交易记录明细
 *
 * 展示每一笔套利交易的执行详情：
 * - 双腿价格（A腿/B腿）
 * - 成交量
 * - 预估利润 vs 实际利润
 * - 执行状态（成功/失败/单腿暴露等）
 *
 * 相关数据库表：arb_record
 */
class ArbRecord extends Controller
{
    use SafeQueryTrait;
    // 混入安全查询工具方法

    /**
     * 交易记录列表（默认展示近 30 天）
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '交易记录';

        // 获取所有 GET 参数（URL 中 ? 后面的键值对）
        $get = $this->request->get();

        // 创建针对 arb_record 表的查询构造器（尚未执行 SQL）
        $query = Db::connect('db_rmpd')->name('arb_record');

        // $hasFilter 是一个布尔标志变量，用于判断用户是否设置了任何过滤条件。
        // 初始为 false（"没有过滤条件"）。
        // 如果任何一个 if 块执行，就把它设为 true（"有过滤条件"）。
        // 最后根据这个标志决定是否自动加上"近 30 天"的默认时间限制。
        $hasFilter = false;

        // ── 过滤条件 0：按套利记录 ID 精确查找（供成交流水页点击套利记录ID下钻使用） ──

        if (!empty($get['id'])) {
            $arbId = $this->sanitizeId($get['id']);
            if ($arbId) {
                $query->where('id', $arbId);
                $hasFilter = true;
            }
        }

        // ── 过滤条件 1：按市场对 ID 精确查找 ─────────────────────────────

        if (!empty($get['market_pair_id'])) {
            // sanitizeId() 验证必须是正整数，防止注入非法值
            $mpId = $this->sanitizeId($get['market_pair_id']);
            if ($mpId) {
                // where('字段', 值) 精确等值匹配，生成 WHERE market_pair_id = N
                $query->where('market_pair_id', $mpId);
                $hasFilter = true;
            }
        }

        // ── 过滤条件 2：按交易状态过滤 ─────────────────────────────────────

        if (!empty($get['status'])) {
            // sanitizeEnum() 验证值必须在白名单内（由 Enum::getAllArbStatuses() 提供）
            $status = $this->sanitizeEnum($get['status'], Enum::getAllArbStatuses());
            if ($status) {
                $query->where('status', $status);
                $hasFilter = true;
            }
        }

        // ── 过滤条件 3：按依赖类型过滤 ─────────────────────────────────────

        if (!empty($get['dependency_type'])) {
            $dt = $this->sanitizeEnum($get['dependency_type'], Enum::getAllDepTypes());
            if ($dt) {
                $query->where('dependency_type', $dt);
                $hasFilter = true;
            }
        }

        // ── 过滤条件 4：按执行机器名称模糊搜索 ─────────────────────────────

        if (!empty($get['assigned_machine'])) {
            // sanitizeLike() 转义 LIKE 特殊字符后精确等值匹配（不加 % 通配符）
            // 因为机器名是固定格式（如 "worker-01"），精确匹配比模糊匹配更安全准确
            $query->where('assigned_machine', $this->sanitizeLike($get['assigned_machine']));
            $hasFilter = true;
        }

        // ── 过滤条件 4b：按平台过滤（A腿 / B腿，平台字段各自独立筛选） ──────

        // platform_a / platform_b 是各腿平台唯一真源（polymarket|kalshi）。
        // 产品要求：有几个平台字段就给几个筛选项，故 A腿、B腿各一个下拉。
        if (!empty($get['platform_a'])) {
            $pa = $this->sanitizeEnum($get['platform_a'], Enum::getAllPlatforms());
            if ($pa) {
                $query->where('platform_a', $pa);
                $hasFilter = true;
            }
        }
        if (!empty($get['platform_b'])) {
            $pb = $this->sanitizeEnum($get['platform_b'], Enum::getAllPlatforms());
            if ($pb) {
                $query->where('platform_b', $pb);
                $hasFilter = true;
            }
        }

        // ── 过滤条件 5：按创建时间范围过滤 ─────────────────────────────────

        if (!empty($get['date_range'])) {
            // sanitizeDateRange() 解析 "YYYY-MM-DD - YYYY-MM-DD" 格式
            // 返回 ['开始日期', '结束日期'] 或 null（格式非法时）
            if ($dates = $this->sanitizeDateRange($get['date_range'])) {
                // [$start, $end] 数组解构：一行赋值两个变量
                [$start, $end] = $dates;
                // whereBetween 生成：WHERE created_at BETWEEN '2024-01-01 00:00:00' AND '2024-01-31 23:59:59'
                $query->whereBetween('created_at', ["{$start} 00:00:00", "{$end} 23:59:59"]);
                $hasFilter = true;
            }
        }

        // ── 默认兜底：无任何过滤条件时自动限制近 30 天 ─────────────────────

        // 不加任何过滤条件时，arb_record 可能有数万条记录，全量查询会很慢。
        // 用 $hasFilter 标志避免在已有精确条件时多此一举地再加时间限制。
        if (!$hasFilter) {
            $query->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')));
        }

        // 执行分页查询
        // intval() 把字符串 "20" 转成整数 20，防止 paginate() 收到字符串参数出错
        // isset() 检查 key 是否存在（比 !empty 更严格：值为 "0" 时 isset 返回 true，empty 返回 true）
        $result = $query->order('id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? intval($get['pageSize']) : 20,
            'page'      => isset($get['page']) ? intval($get['page']) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/rmpd/arb_record/index.html',
        ], false);

        $list = $result->items();

        // 格式化每条记录的时间字段（统一空值显示为 '-'）
        // 新增 claimed_at（被执行机领取）/ single_leg_exposed_at（进入单腿暴露）/ updated_at，
        // 与生产 arb_record 表字段保持一致。
        foreach ($list as &$item) {
            foreach (['executed_at', 'created_at', 'updated_at', 'claimed_at', 'single_leg_exposed_at'] as $col) {
                $item[$col] = !empty($item[$col])
                    ? date('Y-m-d H:i:s', strtotime($item[$col]))
                    : '-';
            }
        }

        $this->assign([
            'list'      => $list,
            'pagehtml'  => $result->render(),
            'get'       => $get,
            // 状态下拉选项（'db值' => '中文显示名'）
            'statuses'  => $this->getStatusLabels(),
            // 依赖类型下拉选项（来自 Enum 集中管理）
            'dep_types' => Enum::getDepTypeLabels(),
            // 平台下拉选项（A腿/B腿共用同一份 polymarket|kalshi）
            'platforms' => Enum::getPlatformLabels(),
        ]);

        return $this->fetch();
    }

    /**
     * 返回 arb_record 状态的"值 => 中文标签"映射，供视图下拉框使用。
     *
     * private 修饰符表示只能在本类内部调用，外部无法访问。
     * 这是封装原则：辅助性的内部方法不需要暴露给外部。
     *
     * @return array 关联数组
     */
    private function getStatusLabels(): array
    {
        return [
            // 注意：这里使用 Enum 常量而非硬编码字符串，确保与数据库存储值一致
            Enum::ARB_PENDING            => '待执行',
            Enum::ARB_EXECUTED           => '双腿已下单',
            Enum::ARB_COMPLETED          => '已结算完成',
            Enum::ARB_CANCELLED          => '已取消',
            Enum::ARB_FAILED             => '执行失败',
            Enum::ARB_SINGLE_LEG_EXPOSED => '单腿暴露中',
            Enum::ARB_SINGLE_LEG_CLOSED  => '单腿已平仓',
            Enum::ARB_ARCHIVED           => '已归档',
        ];
    }
}
