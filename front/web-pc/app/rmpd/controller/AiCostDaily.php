<?php
declare (strict_types=1);

namespace app\rmpd\controller;

use app\rmpd\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 每日 AI 费用 / 扫描汇总（只读）
 *
 * 由策略侧 daily_ai_cost_snapshot 任务每小时聚合 ai_call_log 写入（幂等 upsert）。
 * 初审 / 复审已在表内拆列（scan_* / review_*），按 model 细分请直接查 AI 扫描日志页。
 *
 * 相关数据库表：ai_cost_daily（date 为 VARCHAR(10) YYYY-MM-DD 字符串）
 */
class AiCostDaily extends Controller
{
    use SafeQueryTrait;

    /**
     * 每日 AI 费用列表（默认近 30 天）
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = 'AI 费用日报';
        $get = $this->request->get();

        $query = Db::connect('db_rmpd')->name('ai_cost_daily');

        // date 是 YYYY-MM-DD 字符串，按字符串比较即可正确排序/范围过滤。
        // 防全表扫描：无 date_range 时默认近 30 天。
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange($get['date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween('date', [$start, $end]);
        } else {
            $query->where('date', '>=', date('Y-m-d', strtotime('-30 days')));
        }

        $result = $query->order('date desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? intval($get['pageSize']) : 31,
            'page'      => isset($get['page']) ? intval($get['page']) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/rmpd/ai_cost_daily/index.html',
        ], false);

        $list = $result->items();

        $this->assign([
            'list'     => $list,
            'pagehtml' => $result->render(),
            'get'      => $get,
        ]);

        return $this->fetch();
    }
}
