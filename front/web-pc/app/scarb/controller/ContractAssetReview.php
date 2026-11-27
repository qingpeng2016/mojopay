<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\const\Enum;
use app\scarb\trait\ScarbAccountTrait;
use think\admin\Controller;
use think\db\Query;

class ContractAssetReview extends Controller
{
    use ScarbAccountTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '开仓审核';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)->order('created_at', 'desc')->order('id', 'desc');
        $result      = $this->paginateScarb($query, $get, 'contract_asset_review/index');
        $list        = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'      => $list,
            'pagehtml'  => $result->render(),
            'get'       => $get,
            'statuses'  => array_combine(Enum::getContractReviewStatuses(), Enum::getContractReviewStatuses()),
        ]);

        return $this->fetch();
    }

    /**
     * 后台确认：INIT → PENDING，策略方可执行开仓。
     *
     * @auth true
     */
    public function approve()
    {
        if (!$this->request->isPost()) {
            return $this->error('请求方式错误');
        }

        $id = $this->sanitizeId($this->request->post('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $affected = $this->scarbDb()->name('contract_asset_review')
            ->where('id', $id)
            ->where('status', 'INIT')
            ->update(['status' => 'PENDING']);

        if ($affected < 1) {
            $row = $this->scarbDb()->name('contract_asset_review')->where('id', $id)->find();
            if (empty($row)) {
                return $this->error('记录不存在');
            }
            if (strcasecmp((string)($row['status'] ?? ''), 'INIT') !== 0) {
                return $this->error('仅 INIT 状态可审核，当前：' . ($row['status'] ?? '-'));
            }

            return $this->error('审核失败，请重试');
        }

        return $this->success('已通过审核', 'javascript:location.reload()');
    }

    /**
     * @auth true
     */
    public function add()
    {
        if ($this->request->isPost()) {
            try {
                $data = $this->buildFormData($this->request->post());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $data['status'] = 'INIT';

            $this->scarbDb()->name('contract_asset_review')->insert($data);

            return $this->success('新增成功');
        }

        $this->assign('vo', ['status' => 'INIT']);
        $this->assign('accounts', $this->loadAccountOptions());
        $this->assign('statuses', Enum::getContractReviewStatuses());

        return $this->fetch('form');
    }

    /**
     * @auth true
     */
    public function edit()
    {
        if ($this->request->isPost()) {
            $id = $this->sanitizeId($this->request->post('id'));
            if (!$id) {
                return $this->error('参数错误');
            }
            if ($this->loadRow($id) === null) {
                return $this->error('记录不存在');
            }

            try {
                $data = $this->buildFormData($this->request->post());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $this->scarbDb()->name('contract_asset_review')->where('id', $id)->update($data);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }
        $vo = $this->loadRow($id);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $this->assign('vo', $vo);
        $this->assign('accounts', $this->loadAccountOptions());
        $this->assign('statuses', Enum::getContractReviewStatuses());

        return $this->fetch('form');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->scarbDb()->name('contract_asset_review');
        $this->applyCommonListFilters($query, $get, 'created_at');

        if (!empty($get['asset'])) {
            $asset = trim((string)$get['asset']);
            if ($asset !== '' && strlen($asset) <= 16) {
                $query->where('asset', $asset);
            }
        }
        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getContractReviewStatuses());
            if ($status) {
                $query->where('status', $status);
            }
        }

        return $query;
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->scarbDb()->name('contract_asset_review')->where('id', $id)->find();
        if (empty($row)) {
            return null;
        }

        return $this->formatRow($row);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildFormData(array $post): array
    {
        $accountId = $this->sanitizeId($post['exchange_account_id'] ?? null);
        if (!$accountId) {
            throw new \InvalidArgumentException('请选择交易所账户');
        }
        $platform = $this->resolveAccountPlatform($accountId);
        if ($platform === null) {
            throw new \InvalidArgumentException('账户不存在');
        }

        $asset = trim((string)($post['asset'] ?? ''));
        if ($asset === '' || strlen($asset) > 16) {
            throw new \InvalidArgumentException('asset 不能为空且不超过 16 字符');
        }

        $qty     = $this->requiredDecimal((string)($post['per_order_leverage_qty'] ?? ''), 'per_order_leverage_qty');
        $qtyUnit = $this->sanitizeQtyAsset((string)($post['per_order_leverage_qty_asset'] ?? ''), $asset);

        $status = $this->sanitizeEnum((string)($post['status'] ?? 'INIT'), Enum::getContractReviewStatuses());
        if ($status === null) {
            throw new \InvalidArgumentException('status 无效');
        }

        return [
            'exchange_account_id' => $accountId,
            'platform'            => $platform,
            'asset'               => $asset,
            'per_order_leverage_qty'       => $qty,
            'per_order_leverage_qty_asset' => $qtyUnit,
            'status'              => $status,
        ];
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['per_order_leverage_qty_fmt'] = $this->trimDecimal($row['per_order_leverage_qty'] ?? 0, 8);
        $unit                              = trim((string)($row['per_order_leverage_qty_asset'] ?? ''));
        $row['per_order_leverage_qty_unit'] = $unit !== '' ? $unit : 'BASE';
        $row['created_at']            = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at']            = $this->formatDatetime($row['updated_at'] ?? null);
        $status                       = strtoupper(trim((string)($row['status'] ?? '')));
        $row['status']                = $status;
        $row['show_approve']          = ($status === 'INIT');

        return $row;
    }
}
