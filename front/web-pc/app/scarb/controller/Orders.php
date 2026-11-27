<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\const\Enum;
use app\scarb\trait\ScarbListTrait;
use think\admin\Controller;
use think\db\Query;
use think\facade\Db;

/**
 * 订单记录（orders，只读列表）
 */
class Orders extends Controller
{
    use ScarbListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '订单记录';
        $get = $this->request->get();

        $pageQuery = $this->filteredQuery($get)->order('created_at', 'desc')->order('id', 'desc');
        $result    = $this->paginateScarb($pageQuery, $get, 'orders');

        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'       => $list,
            'pagehtml'   => $result->render(),
            'get'        => $get,
            'platforms'  => array_combine(Enum::getAllPlatforms(), Enum::getAllPlatforms()),
            'sides'      => array_combine(Enum::getAllSides(), Enum::getAllSides()),
            'statuses'   => array_combine(Enum::getAllOrderStatuses(), Enum::getAllOrderStatuses()),
        ]);

        return $this->fetch();
    }

    /**
     * @param array<string, mixed> $get
     */
    private function filteredQuery(array $get): Query
    {
        /** @var Query $query */
        $query = Db::connect('db_sc_arb')->name('orders');
        $this->applyDateRangeFilter($query, $get, 'created_at');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }

        if (!empty($get['exchange_account_id'])) {
            $accountId = $this->sanitizeId($get['exchange_account_id']);
            if ($accountId) {
                $query->where('exchange_account_id', $accountId);
            }
        }

        if (!empty($get['platform'])) {
            $platform = $this->sanitizeEnum($get['platform'], Enum::getAllPlatforms());
            if ($platform) {
                $query->where('platform', $platform);
            }
        }

        if (!empty($get['symbol'])) {
            $symbol = trim((string)$get['symbol']);
            if ($symbol !== '' && strlen($symbol) <= 32) {
                $query->where('symbol', $symbol);
            }
        }

        if (!empty($get['side'])) {
            $side = $this->sanitizeEnum($get['side'], Enum::getAllSides());
            if ($side) {
                $query->where('side', $side);
            }
        }

        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getAllOrderStatuses());
            if ($status) {
                $query->where('status', $status);
            }
        }

        if (!empty($get['client_order_id'])) {
            $clientOrderId = trim((string)$get['client_order_id']);
            if ($clientOrderId !== '') {
                $query->where('client_order_id', 'like', '%' . $this->sanitizeLike($clientOrderId, 64) . '%');
            }
        }

        if (!empty($get['purpose'])) {
            $purpose = trim((string)$get['purpose']);
            if ($purpose !== '' && strlen($purpose) <= 32) {
                $query->where('purpose', $purpose);
            }
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRow(array $row): array
    {
        $row['orig_qty_fmt']     = $this->trimDecimal($row['orig_qty'] ?? 0, 8);
        $row['executed_qty_fmt'] = $this->trimDecimal($row['executed_qty'] ?? 0, 8);
        $row['avg_price_fmt']    = $this->trimDecimal($row['avg_price'] ?? null, 8);
        $row['price_fmt']        = $this->trimDecimal($row['price'] ?? null, 8);
        $row['created_at']       = $this->formatDatetime($row['created_at'] ?? null);
        $row['placed_at']        = $this->formatDatetime($row['placed_at'] ?? null);
        $row['updated_at']       = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}
