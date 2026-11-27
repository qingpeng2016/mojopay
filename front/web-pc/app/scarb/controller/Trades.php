<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\const\Enum;
use app\scarb\trait\ScarbListTrait;
use think\admin\Controller;
use think\db\Query;

class Trades extends Controller
{
    use ScarbListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '成交记录';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)->order('trade_time', 'desc')->order('id', 'desc');
        $result      = $this->paginateScarb($query, $get, 'trades/index');
        $list        = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'      => $list,
            'pagehtml'  => $result->render(),
            'get'       => $get,
            'platforms' => array_combine(Enum::getAllPlatforms(), Enum::getAllPlatforms()),
            'sides'     => array_combine(Enum::getAllSides(), Enum::getAllSides()),
        ]);

        return $this->fetch();
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->scarbDb()->name('trades');
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween('trade_time', ["{$start} 00:00:00", "{$end} 23:59:59"]);
        } else {
            $query->where('trade_time', '>=', date('Y-m-d H:i:s', strtotime('-30 days')));
        }

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }
        if (!empty($get['exchange_account_id'])) {
            $aid = $this->sanitizeId($get['exchange_account_id']);
            if ($aid) {
                $query->where('exchange_account_id', $aid);
            }
        }
        if (!empty($get['platform']) && is_string($get['platform'])) {
            $p = trim($get['platform']);
            if ($p !== '') {
                $query->where('platform', $p);
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
        if (!empty($get['order_id'])) {
            $oid = $this->sanitizeId($get['order_id']);
            if ($oid) {
                $query->where('order_id', $oid);
            }
        }

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['price_fmt']        = $this->trimDecimal($row['price'] ?? 0, 8);
        $row['qty_fmt']          = $this->trimDecimal($row['qty'] ?? 0, 8);
        $row['quote_qty_fmt']    = $this->trimDecimal($row['quote_qty'] ?? 0, 8);
        $row['realized_pnl_fmt']= $this->trimDecimal($row['realized_pnl'] ?? null, 8);
        $row['commission_fmt']  = $this->trimDecimal($row['commission'] ?? 0, 8);
        $row['trade_time']       = $this->formatDatetime($row['trade_time'] ?? null);
        $row['created_at']       = $this->formatDatetime($row['created_at'] ?? null);

        return $row;
    }
}
