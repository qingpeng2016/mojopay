<?php
declare (strict_types=1);

namespace app\rmpd\controller;

use app\rmpd\const\Enum;
use app\rmpd\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 新市场分流统计（只读）
 *
 * market_intake_daily 由上游任务按日统计各平台新增市场的分流去向：
 * - rule_markets：二元且规则可认领 → 走规则
 * - ai_markets：  二元且规则不认领 → 走 AI
 * - non_binary：  非二元(O/U/队名/多结果) → 策略不做
 * 每日每平台一行（uk_date_platform）。
 *
 * 相关数据库表：market_intake_daily
 */
class MarketIntake extends Controller
{
    use SafeQueryTrait;

    /**
     * 新市场分流统计列表（按主键 id 降序）
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '新市场分流统计';
        $get = $this->request->get();

        $query = Db::connect('db_rmpd')->name('market_intake_daily');

        // ── 按平台过滤（polymarket|kalshi） ───────────────────────────────
        if (!empty($get['platform'])) {
            $pf = $this->sanitizeEnum($get['platform'], Enum::getAllPlatforms());
            if ($pf) {
                $query->where('platform', $pf);
            }
        }

        // ── 按统计日期范围过滤（date 字段为 'YYYY-MM-DD' 字符串，可直接字典序比较） ──
        if (!empty($get['date_range'])) {
            if ($dates = $this->sanitizeDateRange($get['date_range'])) {
                [$start, $end] = $dates;
                $query->whereBetween('date', [$start, $end]);
            }
        }

        // 排序：按主键 id 降序（产品要求）
        $result = $query->order('id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? intval($get['pageSize']) : 20,
            'page'      => isset($get['page']) ? intval($get['page']) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/rmpd/market_intake/index.html',
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
