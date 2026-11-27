<?php

declare (strict_types=1);

namespace app\arbopp\controller;

use app\arbopp\const\Enum;
use app\arbopp\trait\ArboppListTrait;
use think\admin\Controller;
use think\db\Query;

class ArbBinaryOpportunity extends Controller
{
    use ArboppListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '套利机会';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)->order('snapshot_at', 'desc')->order('id', 'desc');
        $result      = $this->paginateArbopp($query, $get, 'arb_binary_opportunity/index');
        $list        = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'      => $list,
            'pagehtml'  => $result->render(),
            'get'       => $get,
            'opp_kinds' => array_combine(Enum::getOppKinds(), Enum::getOppKinds()),
        ]);

        return $this->fetch();
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->arboppDb()->name('arb_binary_opportunity');
        $this->applyOptionalId($query, $get);
        $this->applyOptionalLike($query, $get, 'map_key', 'map_key', 192);
        if (!empty($get['opp_kind'])) {
            $k = $this->sanitizeEnum((string)$get['opp_kind'], Enum::getOppKinds());
            if ($k) {
                $query->where('opp_kind', $k);
            }
        }
        $this->applyOptionalLike($query, $get, 'competition', 'competition', 64);
        $this->applyDateRangeFilter($query, $get, 'snapshot_at', $this->sanitizeId($get['id'] ?? null) === null);

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['map_key_fmt']    = $this->truncateText($row['map_key'] ?? '', 48);
        $row['total_cost_fmt'] = $this->formatDecimalField($row['total_cost'] ?? null);
        $row['profit_space_fmt'] = $this->formatDecimalField($row['profit_space'] ?? null);
        $row['arb_qty_fmt']    = $this->formatDecimalField($row['arb_qty'] ?? null);
        $row['snapshot_at']    = $this->formatDatetime($row['snapshot_at'] ?? null);

        return $row;
    }
}