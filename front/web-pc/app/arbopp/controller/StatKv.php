<?php

declare (strict_types=1);

namespace app\arbopp\controller;

use app\arbopp\trait\ArboppListTrait;
use think\admin\Controller;
use think\db\Query;

class StatKv extends Controller
{
    use ArboppListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '统计键值';
        $get         = $this->request->get();
        $result      = $this->paginateArbopp(
            $this->filteredQuery($get)->order('group_name', 'asc')->order('sort_no', 'asc')->order('id', 'asc'),
            $get,
            'stat_kv/index'
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
        ]);

        return $this->fetch();
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->arboppDb()->name('stat_kv');
        $this->applyOptionalId($query, $get);
        $this->applyOptionalLike($query, $get, 'group_name', 'group_name', 64);
        $this->applyOptionalLike($query, $get, 'item_key', 'item_key', 128);

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['item_value_fmt'] = $this->truncateText($row['item_value'] ?? '', 80);
        $row['updated_at']     = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}