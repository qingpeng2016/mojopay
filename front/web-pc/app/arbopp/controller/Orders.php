<?php

declare (strict_types=1);

namespace app\arbopp\controller;

use app\arbopp\const\Enum;
use app\arbopp\trait\ArboppListTrait;
use think\admin\Controller;
use think\db\Query;

class Orders extends Controller
{
    use ArboppListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '下单记录';
        $get         = $this->request->get();
        $result      = $this->paginateArbopp(
            $this->filteredQuery($get)->order('sent_at', 'desc')->order('id', 'desc'),
            $get,
            'orders/index'
        );
        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'      => $list,
            'pagehtml'  => $result->render(),
            'get'       => $get,
            'platforms' => array_combine(Enum::getPlatforms(), Enum::getPlatforms()),
            'statuses'  => array_combine(Enum::getOrderStatuses(), Enum::getOrderStatuses()),
        ]);

        return $this->fetch();
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->arboppDb()->name('orders');
        $this->applyOptionalId($query, $get);
        $this->applyOptionalPlatform($query, $get);
        if (!empty($get['arb_opp_id'])) {
            $oid = $this->sanitizeId($get['arb_opp_id']);
            if ($oid) {
                $query->where('arb_opp_id', $oid);
            }
        }
        $this->applyOptionalLike($query, $get, 'map_key', 'map_key', 192);
        if (!empty($get['status'])) {
            $st = strtoupper(trim((string)$get['status']));
            if ($st !== '' && strlen($st) <= 32) {
                $query->where('status', $st);
            }
        }
        $this->applyDateRangeFilter($query, $get, 'sent_at', $this->sanitizeId($get['id'] ?? null) === null);

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['price_fmt']    = $this->formatDecimalField($row['price'] ?? null);
        $row['qty_fmt']      = $this->formatDecimalField($row['qty'] ?? null);
        $row['fill_qty_fmt'] = $this->formatDecimalField($row['fill_qty'] ?? null);
        $row['sent_at']      = $this->formatDatetime($row['sent_at'] ?? null);
        $row['map_key_fmt']  = $this->truncateText($row['map_key'] ?? '', 36);

        return $row;
    }
}