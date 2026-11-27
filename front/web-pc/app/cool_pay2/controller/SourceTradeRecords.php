<?php
declare (strict_types=1);

namespace app\cool_pay2\controller;

use app\cool_pay2\model\SourceTradeRecords as SourceTradeRecordsModel;
use app\cool_pay2\const\Enum;
use app\cool_pay2\service\ShardManager;
use app\cool_pay2\trait\IsWinLookupTrait;
use app\cool_pay2\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;
use think\facade\Log;
use think\Paginator;

/**
 * 数据看板
 */
class SourceTradeRecords extends Controller
{
    use SafeQueryTrait;
    use IsWinLookupTrait;
    /**
     * 数据看板
     * @auth true
     * @menu true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $this->title = '数据看板';
        $get = $this->request->get();
        $page = max(1, intval($get['page'] ?? 1));
        $pageSize = max(1, intval($get['pageSize'] ?? 15));

        // 判断是否有用户筛选；没有则默认 created_at >= -30 天
        $hasFilter = !empty($get['date_range']) || !empty($get['order_side']) || !empty($get['status'])
            || !empty($get['target_account_id']) || !empty($get['target_group_id'])
            || !empty($get['source_account_id']) || !empty($get['source_order_id'])
            || !empty($get['target_market_id']) || !empty($get['target_token_id']);
        $defaultStart = $hasFilter ? null : date('Y-m-d H:i:s', strtotime('-30 days'));

        // 顶部 stats 固定按"近 30 天"计（不受用户筛选影响）
        $startTime30d = date('Y-m-d H:i:s', strtotime('-30 days'));

        $perShard = ShardManager::fanOutRead(function (string $conn) use ($get, $page, $pageSize, $defaultStart, $startTime30d) {
            // ── stats ──（30 天 PNL 卡片：按 created_at 过滤，与做市仪表盘 PnlCalcTrait 口径一致；
            //              避免 updated_at 被回填脚本 bump 导致数字漂移）
            $totalVolume = (float)Db::connect($conn)->name('source_trader_records')
                ->where('created_at', '>=', $startTime30d)->sum('trade_amount');
            $activeMarkets = (int)Db::connect($conn)->name('market_mapping_config')
                ->where('target_status', 'ACTIVE')->where('source_status', 'ACTIVE')
                ->count('DISTINCT source_market_id');
            $targetDb = Db::connect($conn)->name('target_trader_records')->where('created_at', '>=', $startTime30d);
            $targetSellSum = (float)(clone $targetDb)->where('order_side', 'SELL')->sum('trade_amount');
            $targetBuySum  = (float)(clone $targetDb)->where('order_side', 'BUY')->sum('trade_amount');
            $sourceDb = Db::connect($conn)->name('source_trader_records')->where('created_at', '>=', $startTime30d);
            $sourceBuySum  = (float)(clone $sourceDb)->where('order_side', 'BUY')->sum('trade_amount');
            $sourceSellSum = (float)(clone $sourceDb)->where('order_side', 'SELL')->sum('trade_amount');
            $sourceFeeSum  = (float)(clone $sourceDb)->sum('fee_amount');

            // ── list（每片各取 count 和 page*pageSize 行）──
            $q = Db::connect($conn)->name('source_trader_records');
            $this->applyIndexFilters($q, $get);
            if ($defaultStart !== null) {
                $q->where('created_at', '>=', $defaultStart);
            }
            $count = (int)(clone $q)->count();
            $rows = (clone $q)->order('updated_at', 'desc')->order('id', 'desc')
                              ->limit($page * $pageSize)->select()->toArray();

            // is_win 标签：本片 mc_id → mmc.source_winning_token_id lookup
            // mc_id 是分片本地自增，lookup 必须在闭包内完成不能跨片
            $mcIds = array_values(array_unique(array_filter(
                array_map('intval', array_column($rows, 'market_config_id'))
            )));
            $winLookup = $this->loadIsWinLookup($conn, $mcIds);
            foreach ($rows as &$row) {
                $row['is_win'] = $this->computeIsWinPoly($row, $winLookup);
            }
            unset($row);

            return compact('totalVolume', 'activeMarkets', 'targetSellSum', 'targetBuySum',
                'sourceBuySum', 'sourceSellSum', 'sourceFeeSum', 'count', 'rows');
        });

        // 跨片合并
        $totalVolume = $activeMarkets = 0.0;
        $tSell = $tBuy = $sBuy = $sSell = $sFee = 0.0;
        $totalCount = 0;
        $merged = [];
        foreach ($perShard as $s) {
            $totalVolume += $s['totalVolume'];
            $activeMarkets += $s['activeMarkets'];
            $tSell += $s['targetSellSum'];
            $tBuy += $s['targetBuySum'];
            $sBuy += $s['sourceBuySum'];
            $sSell += $s['sourceSellSum'];
            $sFee += $s['sourceFeeSum'];
            $totalCount += $s['count'];
            foreach ($s['rows'] as $row) $merged[] = $row;
        }
        $totalPnl = ($tSell - $tBuy) - $sBuy + $sSell - $sFee;
        $stats = [
            'active_markets' => number_format((float)$activeMarkets),
            'total_volume'   => number_format($totalVolume, 2),
            'pnl'            => number_format($totalPnl, 2),
        ];

        // 全局排序 + 切页
        usort($merged, static function ($a, $b) {
            $cmp = strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
            if ($cmp !== 0) return $cmp;
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        });
        $pageRows = array_slice($merged, ($page - 1) * $pageSize, $pageSize);

        $result = Paginator::make($pageRows, $pageSize, $page, $totalCount, false, [
            'query' => $get,
            'path'  => '/admin.html#/cool_pay2/source_trade_records/index.html',
        ]);

        $list = $result->items();

        foreach ($list as &$item) {
            // 处理NULL值，避免htmlentities错误
            $item['desc'] = $item['desc'] ?? ''; //to delete 
            
            // 处理金额格式
            $item['trade_price'] = number_format((float)$item['trade_price'], 8, '.', '');
            $item['trade_quantity'] = number_format((float)$item['trade_quantity'], 8, '.', '');
            $item['trade_amount'] = number_format((float)$item['trade_amount'], 8, '.', '');
            
            // 处理时间
            if (!empty($item['created_at'])) {
                $item['created_at'] = date('Y-m-d H:i:s', strtotime($item['created_at']));
            }
            if (!empty($item['updated_at'])) {
                $item['updated_at'] = date('Y-m-d H:i:s', strtotime($item['updated_at']));
            }

            // make the long token id display clean
            $longString_1 = $item['source_order_id'] ?? ''; 
            
            if (strlen($longString_1) > 20) {
                $first = substr($longString_1, 0, 8); // Get first 8
                $last = substr($longString_1, -6);    // Get last 6
                $item['source_order_id_display'] = "{$first}......{$last}";
            } else {
                $item['source_order_id_display'] = $longString_1;
            }

            $longString_2 = $item['source_token_id'] ?? ''; 
            
            if (strlen($longString_2) > 20) {
                $first = substr($longString_2, 0, 8); // Get first 8
                $last = substr($longString_2, -6);    // Get last 6
                $item['source_token_id_display'] = "{$first}......{$last}";
            } else {
                $item['source_token_id_display'] = $longString_2;
            }

        }

        // 分配变量到视图
        $this->assign([
            'list' => $list,
            'pagehtml' => $result->render(),
            'get' => $get,
            'vendors' => $this->getVendors(),
            'order_sides' => $this->getOrderSides(),
            'statuses' => $this->getOrderStatuses(),
            'stats'    => $stats, // Pass the stats to the view
        ]);
        $this->app->log->info('Variables assigned to view');

        // 渲染视图
        $this->app->log->info('Rendering view');
        return $this->fetch();
    }  


    /**
     * 导出数据到 CSV（跨片）
     * @auth true
     */
    public function export()
    {
        $get = $this->request->get();

        $filename = 'trade_records_' . date('YmdHis') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['shard_name', 'id', 'created_at','updated_at', 'source_vendor','source_order_id','order_type','order_side',
                    'trade_price', 'trade_quantity', 'trade_amount','fee_amount','trade_commission','status','is_win','remark',
                    'source_account_id','source_market_id','source_token_id','market_config_id',
                    'target_hedge_record_id','target_vendor','target_account_id','target_group_id','target_market_id','target_token_id'
                    ], ",", "\"", "");

        ShardManager::fanOutRead(function (string $conn, array $info) use ($fp, $get) {
            $q = Db::connect($conn)->name('source_trader_records');
            $this->applyIndexFilters($q, $get);
            $shardName = (string)$info['name'];
            // 预加载本片全表 mmc lookup，给 chunk 内逐行打 is_win 用
            // 重的片 (~25k 行 × 短字符串) 内存几 MB 可接受
            $winLookup = $this->loadIsWinLookup($conn, null);
            $q->order('id', 'desc')->chunk(500, function ($rows) use ($fp, $shardName, $winLookup) {
                foreach ($rows as $row) {
                    fputcsv($fp, [
                        $shardName,
                        $row['id'] ?? '',
                        $row['created_at'] ?? '',
                        $row['updated_at'] ?? '',
                        $row['source_vendor'] ?? '',
                        $row['source_order_id'] ?? '',
                        $row['order_type'] ?? '',
                        $row['order_side'] ?? '',
                        number_format((float)($row['trade_price'] ?? 0), 8, '.', ''),
                        number_format((float)($row['trade_quantity'] ?? 0), 8, '.', ''),
                        number_format((float)($row['trade_amount'] ?? 0), 8, '.', ''),
                        number_format((float)($row['fee_amount'] ?? 0), 8, '.', ''),
                        number_format((float)($row['trade_commission'] ?? 0), 8, '.', ''),
                        $row['status'] ?? '',
                        $this->computeIsWinPoly($row, $winLookup),
                        $row['remark'] ?? '',
                        $row['source_account_id'] ?? '',
                        $row['source_market_id'] ?? '',
                        $row['source_token_id'] ?? '',
                        $row['market_config_id'] ?? '',
                        $row['target_hedge_record_id'] ?? '',
                        $row['target_vendor'] ?? '',
                        $row['target_account_id'] ?? '',
                        $row['target_group_id'] ?? '',
                        $row['target_market_id'] ?? '',
                        $row['target_token_id'] ?? ''],
                        ",", "\"", "");
                }
            }, 'id');
            return null;
        });

        fclose($fp);
        exit();
    }

    /**
     * 把 GET 里的 source_trade_records 筛选条件 apply 到 query 上。
     * date_range → created_at 区间；其它字段直查。index() 和 export() 共用。
     */
    private function applyIndexFilters($query, array $get): void
    {
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start_date, $end_date] = $dates;
            $query->whereBetween('created_at', ["{$start_date} 00:00:00", "{$end_date} 23:59:59"]);
        }
        $filterFields = ['order_side', 'status', 'target_account_id', 'target_group_id',
                         'source_account_id', 'source_order_id', 'target_market_id', 'target_token_id'];
        foreach ($filterFields as $field) {
            if (!empty($get[$field])) {
                $query->where($field, $get[$field]);
            }
        }
    }


    /**
     * 获取渠道选项
     * @return array
     */
    private function getVendors()
    {
        return Enum::GetAllVendor();
    }

    /**
     * 获取订单状态选项
     * @return array
     */
    private function getOrderSides()
    {
        return Enum::GetAllOrderSide();
    }

    private function getOrderStatuses()
    {
        return Enum::GetAllOrderStatus();
    }
}
