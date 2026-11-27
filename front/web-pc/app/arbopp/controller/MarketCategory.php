<?php

declare (strict_types=1);

namespace app\arbopp\controller;

use app\arbopp\const\Enum;
use app\arbopp\trait\ArboppListTrait;
use think\admin\Controller;
use think\db\Query;

class MarketCategory extends Controller
{
    use ArboppListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '联赛分类';
        $get         = $this->request->get();
        $result      = $this->paginateArbopp(
            $this->filteredQuery($get)->order('sort_no', 'asc')->order('id', 'asc'),
            $get,
            'market_category/index'
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
        $query = $this->arboppDb()->name('market_category');
        $this->applyOptionalId($query, $get);
        $this->applyOptionalPlatform($query, $get);
        $this->applyOptionalLike($query, $get, 'sport_cn', 'sport_cn', 64);
        $this->applyOptionalLike($query, $get, 'sub_category', 'sub_category', 64);

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['updated_at'] = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}