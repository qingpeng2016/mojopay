<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\const\Enum;
use app\scarb\trait\ScarbListTrait;
use think\admin\Controller;
use think\db\Query;

class Positions extends Controller
{
    use ScarbListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '持仓';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)->order('updated_at', 'desc')->order('id', 'desc');
        $result      = $this->paginateScarb($query, $get, 'positions/index');
        $list        = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'       => $list,
            'pagehtml'   => $result->render(),
            'get'        => $get,
            'platforms'  => array_combine(Enum::getAllPlatforms(), Enum::getAllPlatforms()),
            'statuses'   => array_combine(Enum::getPositionStatuses(), Enum::getPositionStatuses()),
            'pos_sides'  => array_combine(Enum::getPositionSides(), Enum::getPositionSides()),
        ]);

        return $this->fetch();
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->scarbDb()->name('positions');
        $this->applyCommonListFilters($query, $get, 'updated_at');

        if (!empty($get['symbol'])) {
            $symbol = trim((string)$get['symbol']);
            if ($symbol !== '' && strlen($symbol) <= 32) {
                $query->where('symbol', $symbol);
            }
        }
        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getPositionStatuses());
            if ($status) {
                $query->where('status', $status);
            }
        }
        if (!empty($get['position_side'])) {
            $side = $this->sanitizeEnum($get['position_side'], Enum::getPositionSides());
            if ($side) {
                $query->where('position_side', $side);
            }
        }

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['qty_fmt']           = $this->trimDecimal($row['qty'] ?? 0, 8);
        $row['unrealized_pnl_fmt']= $this->trimDecimal($row['unrealized_pnl'] ?? 0, 8);
        $row['entry_price_fmt']   = $this->trimDecimal($row['entry_price'] ?? null, 8);
        $row['notional_usdt_fmt'] = $this->trimDecimal($row['notional_usdt'] ?? null, 8);
        $row['last_sync_at']      = $this->formatDatetime($row['last_sync_at'] ?? null);
        $row['updated_at']        = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}
