<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\const\Enum;
use app\scarb\trait\ScarbListTrait;
use think\admin\Controller;
use think\db\Query;

class AccountAsset extends Controller
{
    use ScarbListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '账户资产';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)->order('updated_at', 'desc')->order('id', 'desc');
        $result      = $this->paginateScarb($query, $get, 'account_asset/index');
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
        ]);

        return $this->fetch();
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->scarbDb()->name('account_asset');
        $this->applyCommonListFilters($query, $get, 'updated_at');

        if (!empty($get['asset'])) {
            $asset = trim((string)$get['asset']);
            if ($asset !== '' && strlen($asset) <= 16) {
                $query->where('asset', $asset);
            }
        }
        if (!empty($get['wallet_type'])) {
            $wt = trim((string)$get['wallet_type']);
            if ($wt !== '' && strlen($wt) <= 32) {
                $query->where('wallet_type', $wt);
            }
        }

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['balance_total_fmt']     = $this->trimDecimal($row['balance_total'] ?? 0, 8);
        $row['balance_available_fmt'] = $this->trimDecimal($row['balance_available'] ?? 0, 8);
        $row['balance_frozen_fmt']    = $this->trimDecimal($row['balance_frozen'] ?? 0, 8);
        $row['usdt_value_fmt']        = $this->trimDecimal($row['usdt_value'] ?? null, 8);
        $row['last_sync_at']          = $this->formatDatetime($row['last_sync_at'] ?? null);
        $row['updated_at']            = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}
