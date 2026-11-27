<?php
declare (strict_types=1);

namespace app\rmpd\controller;

use app\rmpd\const\Enum;
use app\rmpd\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 本地市场查询（只读）
 *
 * market 表是策略侧从各平台（Polymarket / Kalshi）抓取并落地的本地市场快照，
 * 是 market_pair 配对、套利监控的上游数据源。本页仅供查询，不提供写操作。
 *
 * 相关数据库表：market
 */
class Market extends Controller
{
    use SafeQueryTrait;

    /**
     * 本地市场列表（按主键 id 倒序，最新抓取的排最前）
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '本地市场';
        $get = $this->request->get();

        $query = Db::connect('db_rmpd')->name('market');

        // ── 过滤条件 1：按本地主键 ID 精确查找 ─────────────────────────────
        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }

        // ── 过滤条件 2：按平台市场 ID（market_id，唯一键）精确查找 ──────────
        if (!empty($get['market_id'])) {
            // market_id 为平台原始 ID（数字串/哈希串），精确等值匹配（sanitizeLike 仅转义）
            $query->where('market_id', $this->sanitizeLike($get['market_id'], 255));
        }

        // ── 过滤条件 3：按链上 conditionId 精确查找 ────────────────────────
        if (!empty($get['condition_id'])) {
            $query->where('condition_id', $this->sanitizeLike($get['condition_id'], 255));
        }

        // ── 过滤条件 4：按市场问题关键词模糊搜索 ──────────────────────────
        if (!empty($get['question'])) {
            $kw = $this->sanitizeLike($get['question']);
            $query->where('question', 'like', "%{$kw}%");
        }

        // ── 过滤条件 5：按平台过滤（polymarket|kalshi） ───────────────────
        if (!empty($get['platform'])) {
            $pf = $this->sanitizeEnum($get['platform'], Enum::getAllPlatforms());
            if ($pf) {
                $query->where('platform', $pf);
            }
        }

        // ── 过滤条件 6：按是否活跃过滤（is_active 0/1） ───────────────────
        // 注意用 isset 判断（值可能是字符串 "0"，empty('0') 为 true 会误判）
        if (isset($get['is_active']) && $get['is_active'] !== '') {
            if (in_array($get['is_active'], ['0', '1'], true)) {
                $query->where('is_active', (int)$get['is_active']);
            }
        }

        // ── 过滤条件 7：按创建时间范围过滤 ─────────────────────────────────
        if (!empty($get['date_range'])) {
            if ($dates = $this->sanitizeDateRange($get['date_range'])) {
                [$start, $end] = $dates;
                $query->whereBetween('created_at', ["{$start} 00:00:00", "{$end} 23:59:59"]);
            }
        }

        // 排序：按主键 id 倒序（产品要求）。id desc + LIMIT 走主键反向扫描，
        // 即便 market 表数十万行也无需额外时间兜底。
        $result = $query->order('id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? intval($get['pageSize']) : 20,
            'page'      => isset($get['page']) ? intval($get['page']) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/rmpd/market/index.html',
        ], false);

        $list = $result->items();

        foreach ($list as &$item) {
            foreach (['created_at', 'updated_at'] as $col) {
                $item[$col] = !empty($item[$col])
                    ? date('Y-m-d H:i:s', strtotime($item[$col]))
                    : '-';
            }
        }

        $this->assign([
            'list'      => $list,
            'pagehtml'  => $result->render(),
            'get'       => $get,
            'platforms' => Enum::getPlatformLabels(),
        ]);

        return $this->fetch();
    }
}
