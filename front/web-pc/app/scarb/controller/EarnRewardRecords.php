<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\const\Enum;
use app\scarb\trait\ScarbListTrait;
use think\admin\Controller;
use think\db\Query;

class EarnRewardRecords extends Controller
{
    use ScarbListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '理财奖励';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)->order('reward_time', 'desc')->order('id', 'desc');
        $result      = $this->paginateScarb($query, $get, 'earn_reward_records/index');
        $list        = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);
        $this->attachAccountNames($list);

        $this->assign([
            'list'      => $list,
            'pagehtml'  => $result->render(),
            'get'       => $get,
            'platforms' => array_combine(Enum::getAllPlatforms(), Enum::getAllPlatforms()),
        ]);

        return $this->fetch();
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->scarbDb()->name('earn_reward_records');
        $this->applyDateRangeFilter($query, $get, 'reward_time');

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
            if ($p !== '' && strlen($p) <= 32) {
                $query->where('platform', $p);
            }
        }
        if (!empty($get['position_asset'])) {
            $asset = trim((string)$get['position_asset']);
            if ($asset !== '' && strlen($asset) <= 16) {
                $query->where('position_asset', $asset);
            }
        }
        if (!empty($get['reward_asset'])) {
            $asset = trim((string)$get['reward_asset']);
            if ($asset !== '' && strlen($asset) <= 16) {
                $query->where('reward_asset', $asset);
            }
        }

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['position_qty_fmt'] = $this->trimDecimal($row['position_qty'] ?? 0, 8);
        $row['reward_qty_fmt']   = $this->trimDecimal($row['reward_qty'] ?? 0, 8);
        $apr                     = $row['annual_percentage_rate'] ?? null;
        $row['apr_fmt']          = ($apr === null || $apr === '') ? '-' : $this->trimDecimal($apr, 8);
        $row['reward_time']      = $this->formatDatetime($row['reward_time'] ?? null);
        $row['created_at']       = $this->formatDatetime($row['created_at'] ?? null);

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $list
     */
    private function attachAccountNames(array &$list): void
    {
        if ($list === []) {
            return;
        }

        $accountIds = [];
        foreach ($list as $row) {
            $aid = (int)($row['exchange_account_id'] ?? 0);
            if ($aid > 0) {
                $accountIds[$aid] = $aid;
            }
        }
        if ($accountIds === []) {
            return;
        }

        $names = $this->scarbDb()
            ->name('account_exchange')
            ->whereIn('id', array_values($accountIds))
            ->column('account_name', 'id');

        foreach ($list as &$row) {
            $aid = (int)($row['exchange_account_id'] ?? 0);
            $row['account_name'] = (string)($names[$aid] ?? '-');
        }
        unset($row);
    }
}
