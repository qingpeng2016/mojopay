<?php
declare (strict_types=1);

namespace app\cool_pay3\controller;

use app\cool_pay3\const\Enum;
use app\cool_pay3\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 自做市 - 目标市场（成交记录）
 *
 * 与对冲策略（cool_pay2）的「数据看板 / 目标市场」整体保持一致，差异仅两点：
 *   1. 去掉顶部「运行中市场 / 总交易量」两个统计卡片（自做市不需要看板统计）。
 *   2. 分页/导出的数据强制锁定为「自做市策略自己的账号」。
 *
 * 自做市策略账号 = accounts 表里类型为 MARKET_MAKING_FOREGATE（做市商）
 * 或 TRADE_FOREGATE（刷量）的账号。两类账号的成交都落在本库 trader_records，
 * 通过 trader_records.account_id = accounts.id 关联。
 * 这样能自动排除 trader_records 里 account_id 未在 accounts 登记的历史脏数据。
 *
 * 数据来源：fgmm_self_mm.trader_records（cool_pay3 默认连接 db_pay3，单库非分片）。
 */
class TargetTradeRecords extends Controller
{
    use SafeQueryTrait;

    /**
     * 自做市策略账号类型白名单（做市商 + 刷量）。
     */
    private const SELF_MM_ACCOUNT_TYPES = [
        Enum::ACCOUNT_TYPE_MARKET_MAKING_FOREGATE, // 做市商
        Enum::ACCOUNT_TYPE_TRADE_FOREGATE,         // 刷量
    ];

    /**
     * 目标市场（成交记录）列表
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '目标市场';
        $get = $this->request->get();

        $query = Db::name('trader_records');
        $this->applySelfMmScope($query);   // 强制锁定自做市账号
        $this->applyIndexFilters($query, $get);

        $result = $query->order('updated_at', 'desc')->order('id', 'desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? intval($get['pageSize']) : 20,
            'page'      => isset($get['page']) ? intval($get['page']) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/cool_pay3/target_trade_records/index.html',
        ], false);

        $list = $result->items();
        foreach ($list as &$item) {
            $item['trade_price']      = number_format((float)($item['trade_price'] ?? 0), 8, '.', '');
            $item['trade_quantity']   = number_format((float)($item['trade_quantity'] ?? 0), 8, '.', '');
            $item['trade_amount']     = number_format((float)($item['trade_amount'] ?? 0), 8, '.', '');
            $item['trade_fee']        = number_format((float)($item['trade_fee'] ?? 0), 8, '.', '');
            $item['trade_commission'] = number_format((float)($item['trade_commission'] ?? 0), 8, '.', '');
            $item['outcome'] = $item['outcome'] ?? '';

            // vendor 库里存小写（foregate），统一转大写供前端徽标判断（与对冲页一致比较大写字面量）
            $item['vendor'] = strtoupper((string)($item['vendor'] ?? ''));

            $item['created_at'] = !empty($item['created_at']) ? date('Y-m-d H:i:s', strtotime($item['created_at'])) : '-';
            $item['updated_at'] = !empty($item['updated_at']) ? date('Y-m-d H:i:s', strtotime($item['updated_at'])) : '-';

            // 长 ID 列表展示截断（与对冲页一致的处理）
            $item['order_id_display'] = $this->shorten((string)($item['order_id'] ?? ''));
            $item['token_id_display'] = $this->shorten((string)($item['token_id'] ?? ''));
        }
        unset($item);

        $this->assign([
            'list'        => $list,
            'pagehtml'    => $result->render(),
            'get'         => $get,
            'vendors'     => Enum::GetAllVendor(),
            'order_sides' => Enum::GetAllOrderSide(),
            'statuses'    => Enum::GetAllOrderStatus(),
        ]);
        return $this->fetch();
    }

    /**
     * 导出 CSV（仅导出自做市账号的成交，沿用列表搜索条件）
     * @auth true
     */
    public function export()
    {
        $get = $this->request->get();

        $filename = 'self_mm_trade_records_' . date('YmdHis') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM，Excel 不乱码
        fputcsv($fp, [
            'id', 'created_at', 'updated_at', 'vendor', 'account_id', 'order_id', 'trade_id',
            'order_type', 'order_side', 'trade_price', 'trade_quantity', 'trade_amount',
            'trade_fee', 'trade_commission', 'status', 'data_source', 'remark',
            'outcome', 'group_id', 'market_id', 'token_id', 'market_config_id', 'user_trade_time',
        ], ",", "\"", "");

        $query = Db::name('trader_records');
        $this->applySelfMmScope($query);
        $this->applyIndexFilters($query, $get);
        $query->order('id', 'desc')->chunk(500, function ($rows) use ($fp) {
            foreach ($rows as $row) {
                fputcsv($fp, [
                    $row['id'] ?? '',
                    $row['created_at'] ?? '',
                    $row['updated_at'] ?? '',
                    $row['vendor'] ?? '',
                    $row['account_id'] ?? '',
                    $row['order_id'] ?? '',
                    $row['trade_id'] ?? '',
                    $row['order_type'] ?? '',
                    $row['order_side'] ?? '',
                    number_format((float)($row['trade_price'] ?? 0), 8, '.', ''),
                    number_format((float)($row['trade_quantity'] ?? 0), 8, '.', ''),
                    number_format((float)($row['trade_amount'] ?? 0), 8, '.', ''),
                    number_format((float)($row['trade_fee'] ?? 0), 8, '.', ''),
                    number_format((float)($row['trade_commission'] ?? 0), 8, '.', ''),
                    $row['status'] ?? '',
                    $row['data_source'] ?? '',
                    $row['remark'] ?? '',
                    $row['outcome'] ?? '',
                    $row['group_id'] ?? '',
                    $row['market_id'] ?? '',
                    $row['token_id'] ?? '',
                    $row['market_config_id'] ?? '',
                    $row['user_trade_time'] ?? '',
                ], ",", "\"", "");
            }
        }, 'id');

        fclose($fp);
        exit();
    }

    /**
     * 强制把查询限定在自做市策略账号（做市商 + 刷量）范围内。
     * 取 accounts 表对应类型的 id 列表，再约束 trader_records.account_id。
     * 若一个都没有，whereIn 空数组 → 查不出任何行（安全默认，不泄露脏数据）。
     */
    private function applySelfMmScope($query): void
    {
        $accountIds = Db::name('accounts')
            ->whereIn('account_type', self::SELF_MM_ACCOUNT_TYPES)
            ->column('id');
        $accountIds = array_values(array_unique(array_map('intval', $accountIds)));
        $query->whereIn('account_id', $accountIds ?: [0]);
    }

    /**
     * 把 GET 里的搜索条件 apply 到 query 上。index() 和 export() 共用。
     * date_range → created_at 区间；其它字段精确匹配。
     */
    private function applyIndexFilters($query, array $get): void
    {
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween('created_at', ["{$start} 00:00:00", "{$end} 23:59:59"]);
        }
        if (!empty($get['order_side']) && ($side = $this->sanitizeEnum($get['order_side'], Enum::GetAllOrderSide()))) {
            $query->where('order_side', $side);
        }
        if (!empty($get['status']) && ($st = $this->sanitizeEnum($get['status'], Enum::GetAllOrderStatus()))) {
            $query->where('status', $st);
        }
        // account_id：可选，仅在自做市账号范围内进一步缩小（applySelfMmScope 已先兜底）
        if (!empty($get['account_id']) && ($aid = $this->sanitizeId($get['account_id']))) {
            $query->where('account_id', $aid);
        }
        foreach (['order_id', 'group_id', 'market_id', 'token_id'] as $field) {
            if (!empty($get[$field])) {
                $query->where($field, (string)$get[$field]);
            }
        }
    }

    /**
     * 长字符串列表截断展示：前8...后6。
     */
    private function shorten(string $s): string
    {
        if (strlen($s) > 20) {
            return substr($s, 0, 8) . '......' . substr($s, -6);
        }
        return $s;
    }
}
