<?php
declare (strict_types=1);

namespace app\rmpd\controller;

use app\rmpd\const\Enum;
use app\rmpd\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 套利成交流水明细（fill 级，只读）
 *
 * arb_trade_records 是 PNL 的唯一权威数据源：arb_pnl_reconciler 逐单
 * GetOrderById → GetTrades 取真实成交写入本表，再聚合 actual_profit 回写 arb_record。
 * 一条 arb_record 对应多条流水（建仓/平仓/结算 × A/B 腿）。
 *
 * 相关数据库表：arb_trade_records
 */
class ArbTradeRecords extends Controller
{
    use SafeQueryTrait;

    /**
     * 成交流水列表（默认近 30 天）
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '成交流水';
        $get = $this->request->get();

        $query = Db::connect('db_rmpd')->name('arb_trade_records');
        $hasFilter = false;

        // 按所属 arb_record.id 精确查找（供交易记录页下钻使用）
        if (!empty($get['arb_record_id'])) {
            $arbId = $this->sanitizeId($get['arb_record_id']);
            if ($arbId) {
                $query->where('arb_record_id', $arbId);
                $hasFilter = true;
            }
        }

        // 按成交腿过滤
        if (!empty($get['leg'])) {
            $leg = $this->sanitizeEnum($get['leg'], Enum::getAllTradeLegs());
            if ($leg) {
                $query->where('leg', $leg);
                $hasFilter = true;
            }
        }

        // 按买卖方向过滤
        if (!empty($get['side'])) {
            $side = $this->sanitizeEnum($get['side'], Enum::getAllTradeSides());
            if ($side) {
                $query->where('side', $side);
                $hasFilter = true;
            }
        }

        // 按流水来源过滤
        if (!empty($get['data_source'])) {
            $ds = $this->sanitizeEnum($get['data_source'], Enum::getAllTradeSources());
            if ($ds) {
                $query->where('data_source', $ds);
                $hasFilter = true;
            }
        }

        // 按创建时间范围过滤
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange($get['date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween('created_at', ["{$start} 00:00:00", "{$end} 23:59:59"]);
            $hasFilter = true;
        }

        // 防全表扫描：无任何过滤时默认近 30 天
        if (!$hasFilter) {
            $query->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')));
        }

        $result = $query->order('id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? intval($get['pageSize']) : 20,
            'page'      => isset($get['page']) ? intval($get['page']) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/rmpd/arb_trade_records/index.html',
        ], false);

        $list = $result->items();

        foreach ($list as &$item) {
            foreach (['trade_time', 'created_at'] as $col) {
                $item[$col] = !empty($item[$col])
                    ? date('Y-m-d H:i:s', strtotime($item[$col]))
                    : '-';
            }
        }

        $this->assign([
            'list'         => $list,
            'pagehtml'     => $result->render(),
            'get'          => $get,
            'legs'         => Enum::getTradeLegLabels(),
            'sides'        => Enum::getTradeSideLabels(),
            'data_sources' => Enum::getTradeSourceLabels(),
        ]);

        return $this->fetch();
    }
}
