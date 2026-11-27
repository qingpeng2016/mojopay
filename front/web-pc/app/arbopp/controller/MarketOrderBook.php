<?php

declare (strict_types=1);

namespace app\arbopp\controller;

use app\arbopp\const\Enum;
use app\arbopp\trait\ArboppListTrait;
use think\admin\Controller;
use think\db\Query;

class MarketOrderBook extends Controller
{
    use ArboppListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = 'REST 盘口';
        $get         = $this->request->get();
        $result      = $this->paginateArbopp(
            $this->filteredQuery($get)->order('id', 'desc'),
            $get,
            'market_order_book/index'
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
        $query = $this->arboppDb()->name('market_order_book');
        $this->applyOptionalId($query, $get);
        $this->applyOptionalPlatform($query, $get);
        if (!empty($get['market_row_id'])) {
            $mid = $this->sanitizeId($get['market_row_id']);
            if ($mid) {
                $query->where('market_row_id', $mid);
            }
        }
        $this->applyOptionalLike($query, $get, 'token_id', 'token_id', 160);
        $this->applyOptionalLike($query, $get, 'map_key', 'map_key', 192);
        $this->applyDateRangeFilter($query, $get, 'updated_at', $this->sanitizeId($get['id'] ?? null) === null);

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['token_id_fmt'] = $this->truncateText($row['token_id'] ?? '', 24);
        $row['map_key_fmt']  = $this->truncateText($row['map_key'] ?? '', 36);
        $row['updated_at']   = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}