<?php

declare (strict_types=1);

namespace app\arbopp\controller;

use app\arbopp\const\Enum;
use app\arbopp\trait\ArboppListTrait;
use think\admin\Controller;
use think\db\Query;

class Market extends Controller
{
    use ArboppListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '市场场次';
        $get         = $this->request->get();
        $result      = $this->paginateArbopp(
            $this->filteredQuery($get)->order('id', 'desc'),
            $get,
            'market/index'
        );
        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'     => $list,
            'pagehtml' => $result->render(),
            'get'      => $get,
            'platforms' => array_combine(Enum::getPlatforms(), Enum::getPlatforms()),
            'statuses' => array_combine(Enum::getMarketStatuses(), Enum::getMarketStatuses()),
        ]);

        return $this->fetch();
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->arboppDb()->name('market');
        $this->applyOptionalId($query, $get);
        $this->applyOptionalPlatform($query, $get);
        $this->applyOptionalLike($query, $get, 'map_key', 'map_key', 192);
        $this->applyOptionalLike($query, $get, 'competition', 'competition', 64);
        if (!empty($get['status'])) {
            $st = $this->sanitizeEnum(strtoupper((string)$get['status']), Enum::getMarketStatuses());
            if ($st) {
                $query->where('status', $st);
            }
        }
        $this->applyDateRangeFilter($query, $get, 'updated_at', $this->sanitizeId($get['id'] ?? null) === null);

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['name_fmt']     = $this->truncateText($row['name'] ?? '', 60);
        $row['map_key_fmt']  = $this->truncateText($row['map_key'] ?? '', 40);
        $row['updated_at']   = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}