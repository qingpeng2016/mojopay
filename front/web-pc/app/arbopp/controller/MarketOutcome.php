<?php

declare (strict_types=1);

namespace app\arbopp\controller;

use app\arbopp\const\Enum;
use app\arbopp\trait\ArboppListTrait;
use think\admin\Controller;
use think\db\Query;

class MarketOutcome extends Controller
{
    use ArboppListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '市场盘口';
        $get         = $this->request->get();
        $result      = $this->paginateArbopp(
            $this->filteredQuery($get)->order('id', 'desc'),
            $get,
            'market_outcome/index'
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
        ]);

        return $this->fetch();
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->arboppDb()->name('market_outcome');
        $this->applyOptionalId($query, $get);
        $this->applyOptionalPlatform($query, $get);
        if (!empty($get['market_row_id'])) {
            $mid = $this->sanitizeId($get['market_row_id']);
            if ($mid) {
                $query->where('market_row_id', $mid);
            }
        }
        $this->applyOptionalLike($query, $get, 'outcome_type', 'outcome_type', 32);
        $this->applyDateRangeFilter($query, $get, 'updated_at', $this->sanitizeId($get['id'] ?? null) === null);

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['outcome_name_fmt'] = $this->truncateText($row['outcome_name'] ?? '', 50);
        $row['updated_at']       = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}