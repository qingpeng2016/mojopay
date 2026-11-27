<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\const\Enum;
use app\scarb\trait\ScarbListTrait;
use think\admin\Controller;
use think\db\Query;

class AccountAssetConverts extends Controller
{
    use ScarbListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '兑换记录';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)->order('created_at', 'desc')->order('id', 'desc');
        $result      = $this->paginateScarb($query, $get, 'account_asset_converts/index');
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
            'statuses'  => array_combine(Enum::getConvertStatuses(), Enum::getConvertStatuses()),
        ]);

        return $this->fetch();
    }

    /**
     * 人工确认已兑换：INIT/PENDING → SUCCESS
     *
     * @auth true
     */
    public function confirm()
    {
        if (!$this->request->isPost()) {
            return $this->error('请求方式错误');
        }

        $id = $this->sanitizeId($this->request->post('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $affected = $this->scarbDb()->name('account_asset_converts_records')
            ->where('id', $id)
            ->whereIn('status', ['INIT', 'PENDING'])
            ->update(['status' => 'SUCCESS']);

        if ($affected < 1) {
            $row = $this->scarbDb()->name('account_asset_converts_records')->where('id', $id)->find();
            if (empty($row)) {
                return $this->error('记录不存在');
            }
            $cur = strtoupper(trim((string)($row['status'] ?? '')));
            if (!in_array($cur, ['INIT', 'PENDING'], true)) {
                return $this->error('仅 INIT/PENDING 可确认，当前：' . ($cur !== '' ? $cur : '-'));
            }

            return $this->error('确认失败，请重试');
        }

        return $this->success('已确认兑换', 'javascript:location.reload()');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->scarbDb()->name('account_asset_converts_records');
        $this->applyCommonListFilters($query, $get, 'created_at');

        if (!empty($get['from_asset'])) {
            $a = trim((string)$get['from_asset']);
            if ($a !== '' && strlen($a) <= 16) {
                $query->where('from_asset', $a);
            }
        }
        if (!empty($get['to_asset'])) {
            $a = trim((string)$get['to_asset']);
            if ($a !== '' && strlen($a) <= 16) {
                $query->where('to_asset', $a);
            }
        }
        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getConvertStatuses());
            if ($status) {
                $query->where('status', $status);
            }
        }

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['from_qty_fmt'] = $this->trimDecimal($row['from_qty'] ?? 0, 8);
        $toQty               = $row['to_qty'] ?? null;
        $row['to_qty_fmt']   = ($toQty === null || $toQty === '') ? '-' : $this->trimDecimal($toQty, 8);
        $row['created_at']   = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at']   = $this->formatDatetime($row['updated_at'] ?? null);
        $status              = strtoupper(trim((string)($row['status'] ?? '')));
        $row['status']       = $status;
        $row['show_confirm'] = in_array($status, ['INIT', 'PENDING'], true);

        return $row;
    }
}
