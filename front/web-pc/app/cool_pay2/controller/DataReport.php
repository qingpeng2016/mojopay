<?php
declare(strict_types=1);

namespace app\cool_pay2\controller;

use app\cool_pay2\const\Enum;
use app\cool_pay2\service\ShardManager;
use app\cool_pay2\trait\PnlCalcTrait;
use app\cool_pay2\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;
use think\Paginator;

/**
 * 数据报表（目标市场成交明细 + 汇总指标）
 */
class DataReport extends Controller
{
    use SafeQueryTrait;
    use PnlCalcTrait;

    /**
     * 数据报表
     * @auth true
     */
    public function index()
    {
        $this->title = '数据报表';
        $get = array_merge([
            'date_range'        => '',
            'order_side'        => '',
            'status'            => '',
            'target_account_email' => '',
            'target_group_id'   => '',
            'source_account_email' => '',
            'source_order_id'   => '',
            'page'              => 1,
            'pageSize'          => 15,
        ], $this->request->get());
        $page = max(1, (int)$get['page']);
        $pageSize = max(1, (int)$get['pageSize']);

        // 邮箱→account_id 一次性解析（accounts 在所有分片上是同步副本，任一片查即可）
        // 返回 null = 无邮箱筛选；返回 [] = 邮箱不存在（让查询 1=0）；返回非空数组 = 走 IN
        $targetAccountIds = $get['target_account_email'] !== ''
            ? $this->resolveMakerAccountIdsByEmail((string)$get['target_account_email']) : null;
        $sourceAccountIds = $get['source_account_email'] !== ''
            ? $this->resolveHedgeAccountIdsByEmail((string)$get['source_account_email']) : null;

        $stats = $this->buildStats();

        // 跨片 fan-out 列表：
        //   - 每片各 count(total) + 各拉 page*pageSize 行（按 updated_at desc 排序）
        //   - app 层合并 → 全局排序 → 切目标页
        //   - id 跨片重复（各分片独立自增），不作为全局排序键；用 updated_at+id 兜底
        $perShard = ShardManager::fanOutRead(function (string $conn) use (
            $get, $page, $pageSize, $targetAccountIds, $sourceAccountIds
        ) {
            $q = Db::connect($conn)->name('source_trader_records');
            $hasFilter = $this->applyListFilters($q, $get, $targetAccountIds, $sourceAccountIds);
            if (!$hasFilter) {
                $q->where('updated_at', '>=', date('Y-m-d H:i:s', strtotime('-3 months')));
            }
            $count = (clone $q)->count();
            $rows = (clone $q)
                ->order('updated_at', 'desc')
                ->order('id', 'desc')
                ->limit($page * $pageSize)
                ->select()
                ->toArray();

            // is_win 标签：本片 mc_id → mmc.source_winning_token_id lookup
            // mc_id 是分片本地自增，lookup 必须在闭包内完成不能跨片
            $mcIds = array_values(array_unique(array_filter(
                array_map('intval', array_column($rows, 'market_config_id'))
            )));
            $winLookup = $this->loadWinningTokenLookup($conn, $mcIds);
            foreach ($rows as &$row) {
                $row['is_win'] = $this->computeIsWin($row, $winLookup);
            }
            unset($row);

            return ['count' => (int)$count, 'rows' => $rows];
        });

        $total = 0;
        $merged = [];
        foreach ($perShard as $r) {
            $total += $r['count'];
            foreach ($r['rows'] as $row) {
                $merged[] = $row;
            }
        }
        usort($merged, function ($a, $b) {
            $cmp = strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
            if ($cmp !== 0) return $cmp;
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        });
        $pageRows = array_slice($merged, ($page - 1) * $pageSize, $pageSize);

        $result = Paginator::make($pageRows, $pageSize, $page, $total, false, [
            'query' => $get,
            'path'  => '/admin.html#/cool_pay2/data_report/index.html',
        ]);

        $list = $result->items();
        foreach ($list as &$item) {
            $item['desc'] = $item['desc'] ?? '';
            $item['trade_price'] = number_format((float)($item['trade_price'] ?? 0), 8, '.', '');
            $item['trade_quantity'] = number_format((float)($item['trade_quantity'] ?? 0), 8, '.', '');
            $item['trade_amount'] = number_format((float)($item['trade_amount'] ?? 0), 8, '.', '');
            $item['real_trade_amount'] = number_format((float)($item['real_trade_amount'] ?? 0), 8, '.', '');
            $item['trade_fee'] = number_format((float)($item['trade_fee'] ?? 0), 8, '.', '');
            $item['trade_commission'] = number_format((float)($item['trade_commission'] ?? 0), 8, '.', '');
            if (!empty($item['created_at'])) {
                $item['created_at'] = date('Y-m-d H:i:s', strtotime((string)$item['created_at']));
            }
            if (!empty($item['updated_at'])) {
                $item['updated_at'] = date('Y-m-d H:i:s', strtotime((string)$item['updated_at']));
            }
            $oid = (string)($item['source_order_id'] ?? '');
            if (strlen($oid) > 20) {
                $item['source_order_id_display'] = substr($oid, 0, 8) . '......' . substr($oid, -6);
            } else {
                $item['source_order_id_display'] = $oid;
            }
            $item['source_token_id_display'] = (string)($item['source_token_id'] ?? '');
        }
        unset($item);

        $qDateRange = (string)$get['date_range'];
        if ($qDateRange !== '') {
            $qDateRange = str_replace(['+-+', '%2B-%2B', '%2b-%2b'], ' - ', $qDateRange);
            $qDateRange = preg_replace('/\s+/u', ' ', trim($qDateRange));
        }

        $this->assign([
            'list'                => $list,
            'pagehtml'            => $result->render(),
            'get'                 => $get,
            'q_date_range'        => $qDateRange,
            'q_order_side'        => (string)$get['order_side'],
            'q_status'            => (string)$get['status'],
            'q_target_account_email' => (string)$get['target_account_email'],
            'q_target_group_id'   => (string)$get['target_group_id'],
            'q_source_account_email' => (string)$get['source_account_email'],
            'q_source_order_id'   => (string)$get['source_order_id'],
            'order_sides'         => Enum::GetAllOrderSide(),
            'statuses'            => Enum::GetAllOrderStatus(),
            'stats'               => $stats,
        ]);

        return $this->fetch();
    }

    /**
     * 导出 CSV（与列表筛选一致）
     *
     * 多分片：表头一次写入，按分片顺序串行 chunk(500) 流式写。
     * 同一行的 id 跨分片不可比，所以 CSV 内整体按"先 mm_0 后 mm_1"分段，每段内 id desc。
     * 财务对账时按 updated_at 列重新排序即可（导出脚本里也有同样的处理思路）。
     *
     * @auth true
     */
    public function export()
    {
        $get = $this->request->get();

        $targetAccountIds = !empty($get['target_account_email'])
            ? $this->resolveMakerAccountIdsByEmail((string)$get['target_account_email']) : null;
        $sourceAccountIds = !empty($get['source_account_email'])
            ? $this->resolveHedgeAccountIdsByEmail((string)$get['source_account_email']) : null;

        $filename = 'data_report_' . date('YmdHis') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, [
            'shard_name',
            'id', 'created_at', 'updated_at', 'source_vendor', 'source_order_id', 'order_type', 'order_side',
            'trade_price', 'trade_quantity', 'trade_amount', 'final_amount', 'trade_fee', 'trade_commission',
            'status', 'is_win', 'remark',
            'source_account_id', 'source_group_id', 'source_market_id', 'source_token_id', 'market_config_id',
            'target_hedge_record_id', 'target_vendor', 'target_account_id', 'target_group_id', 'target_market_id',
            'target_token_id',
        ], ',', '"', '');

        ShardManager::fanOutRead(function (string $conn, array $info) use ($fp, $get, $targetAccountIds, $sourceAccountIds) {
            $q = Db::connect($conn)->name('source_trader_records');
            $hasFilter = $this->applyListFilters($q, $get, $targetAccountIds, $sourceAccountIds);
            if (!$hasFilter) {
                $q->where('updated_at', '>=', date('Y-m-d H:i:s', strtotime('-3 months')));
            }
            $shardName = (string)$info['name'];
            // 预加载本片全表 mmc lookup，给 chunk 内逐行打 is_win 用
            // 重的片 (~25k 行 × 短字符串) 内存几 MB 可接受
            $winLookup = $this->loadWinningTokenLookup($conn, null);
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
                        number_format((float)($row['real_trade_amount'] ?? 0), 8, '.', ''),
                        number_format((float)($row['trade_fee'] ?? 0), 8, '.', ''),
                        number_format((float)($row['trade_commission'] ?? 0), 8, '.', ''),
                        $row['status'] ?? '',
                        $this->computeIsWin($row, $winLookup),
                        $row['remark'] ?? '',
                        $row['source_account_id'] ?? '',
                        $row['source_group_id'] ?? '',
                        $row['source_market_id'] ?? '',
                        $row['source_token_id'] ?? '',
                        $row['market_config_id'] ?? '',
                        $row['target_hedge_record_id'] ?? '',
                        $row['target_vendor'] ?? '',
                        $row['target_account_id'] ?? '',
                        $row['target_group_id'] ?? '',
                        $row['target_market_id'] ?? '',
                        $row['target_token_id'] ?? '',
                    ], ',', '"', '');
                }
            }, 'id');
            return null;
        });

        fclose($fp);
        exit;
    }

    /**
     * 顶部指标：运行中市场、总交易量、运行中市场交易量、近30天 PNL
     *
     * 多分片实现：每个 status=1 分片各算一份 stats，应用层相加。
     *   - active_markets / total_volume / running_volume：直接相加（一个市场永远只属于一个分片）
     *   - PNL_30 = Σ(FG_net + Poly_real_net + Part2_poly_qty + Part2_fg_qty) over shards
     *   - getWinningTokens / sumIsWonQtyDiff 在每片自己的 market_mapping_config + trader 表上跑
     */
    private function buildStats(): array
    {
        $start30 = date('Y-m-d H:i:s', strtotime('-30 days'));

        $perShard = ShardManager::fanOutRead(function (string $conn) use ($start30) {
            $active = (int)Db::connect($conn)->name('market_mapping_config')
                ->where('target_status', 'ACTIVE')->where('source_status', 'ACTIVE')
                ->count('DISTINCT target_group_id');

            $totalVol = (float)Db::connect($conn)->name('source_trader_records')
                ->where('status', 'FILLED')
                ->sum('trade_amount');

            $activeGroupIds = Db::connect($conn)->name('market_mapping_config')
                ->where('target_status', 'ACTIVE')->where('source_status', 'ACTIVE')
                ->column('target_group_id');
            $activeGroupIds = array_values(array_filter(array_unique($activeGroupIds)));
            $runningVol = 0.0;
            if ($activeGroupIds !== []) {
                $runningVol = (float)Db::connect($conn)->name('source_trader_records')
                    ->where('status', 'FILLED')
                    ->whereIn('target_group_id', $activeGroupIds)
                    ->sum('trade_amount');
            }

            $pnl = $this->buildShardPnl($conn, $start30, null);
            return [
                'active' => $active, 'totalVol' => $totalVol, 'runningVol' => $runningVol,
                'pnl' => $pnl['total'],
            ];
        });

        $activeMarkets = 0;
        $totalVolume = 0.0;
        $runningVolume = 0.0;
        $pnl30 = 0.0;
        foreach ($perShard as $s) {
            $activeMarkets += $s['active'];
            $totalVolume   += $s['totalVol'];
            $runningVolume += $s['runningVol'];
            $pnl30         += $s['pnl'];
        }

        return [
            'active_markets'  => number_format((float)$activeMarkets),
            'total_volume'    => number_format($totalVolume, 2),
            'running_volume'  => number_format($runningVolume, 2),
            'pnl_30'          => number_format($pnl30, 2),
            'pnl_30_raw'      => $pnl30,
        ];
    }

    /**
     * 在单个分片的 Query 上应用列表筛选条件，返回是否有任一过滤器命中。
     * 邮箱预先在跨片调用前已 resolve 成 account_id 列表；null = 无该筛选；[] = 邮箱找不到→直接 1=0。
     */
    private function applyListFilters(
        $query,
        array $get,
        ?array $targetAccountIds,
        ?array $sourceAccountIds
    ): bool {
        $hasFilter = false;
        if (!empty($get['date_range'])) {
            if ($dates = $this->sanitizeDateRange((string)$get['date_range'])) {
                [$start, $end] = $dates;
                $query->whereBetween('updated_at', ["{$start} 00:00:00", "{$end} 23:59:59"]);
                $hasFilter = true;
            }
        }
        foreach (['order_side', 'status', 'target_group_id', 'source_order_id'] as $field) {
            if (!empty($get[$field])) {
                $query->where($field, $get[$field]);
                $hasFilter = true;
            }
        }
        if ($targetAccountIds !== null) {
            if ($targetAccountIds === []) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('target_account_id', $targetAccountIds);
            }
            $hasFilter = true;
        }
        if ($sourceAccountIds !== null) {
            if ($sourceAccountIds === []) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('source_account_id', $sourceAccountIds);
            }
            $hasFilter = true;
        }
        return $hasFilter;
    }

    /**
     * 做市账户邮箱：匹配 accounts.info JSON 中 email，返回 accounts.id 列表。
     *
     * @return list<int|string>
     */
    private function resolveMakerAccountIdsByEmail(string $email): array
    {
        $email = trim($email);
        if ($email === '') {
            return [];
        }

        return array_values(array_unique(Db::name('accounts')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`info`, '$.email')) = :email", ['email' => $email])
            ->column('id')));
    }

    /**
     * 对冲账户邮箱：匹配 accounts.market_account_id，返回 accounts.id 列表。
     *
     * @return list<int|string>
     */
    private function resolveHedgeAccountIdsByEmail(string $email): array
    {
        $email = trim($email);
        if ($email === '') {
            return [];
        }

        return array_values(array_unique(Db::name('accounts')
            ->where('market_account_id', $email)
            ->column('id')));
    }

    /**
     * 读本分片 market_mapping_config 的 (id → source_winning_token_id) 查找表。
     *
     * mc_id 是分片本地自增，调用方必须保证此查询在对应分片闭包内执行，
     * 否则 lookup 会拿到错片的赢家 token。
     *
     * @param string     $conn   ThinkPHP 连接名（必填，强制分片本地）
     * @param array|null $mcIds  null = 加载本片全表（export 用）；[] = 直接返回空；非空数组 = 仅查指定 ids
     * @return array<int, string>  [mc_id => source_winning_token_id]
     */
    private function loadWinningTokenLookup(string $conn, ?array $mcIds): array
    {
        $q = Db::connect($conn)->name('market_mapping_config');
        if ($mcIds !== null) {
            if ($mcIds === []) {
                return [];
            }
            $q->whereIn('id', $mcIds);
        }
        return $q->column('source_winning_token_id', 'id');
    }

    /**
     * 算单行 is_win 标签，口径与 PnlCalcTrait::getWinningTokens 一致：
     *   mmc 缺失 或 winning_token = ''        → 'PENDING'（未结算）
     *   trader.source_token_id == winning_token → 'WIN'
     *   否则                                    → 'LOSE'
     *
     * @param array|\ArrayAccess        $row     source_trader_records 一行
     * @param array<int, string>        $lookup  loadWinningTokenLookup 返回的 [mc_id => winning_token]
     */
    private function computeIsWin($row, array $lookup): string
    {
        $mcId = (int)($row['market_config_id'] ?? 0);
        $winner = (string)($lookup[$mcId] ?? '');
        if ($winner === '') {
            return 'PENDING';
        }
        return ((string)($row['source_token_id'] ?? '') === $winner) ? 'WIN' : 'LOSE';
    }
}
