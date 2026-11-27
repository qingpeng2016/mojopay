<?php

declare (strict_types=1);

namespace app\mojopay\controller;

use app\mojopay\const\Enum;
use app\mojopay\trait\EmbedViewTrait;
use app\mojopay\trait\LookupTrait;
use app\mojopay\trait\MerchantScopeTrait;
use app\mojopay\trait\MojopayListTrait;
use think\admin\Controller;
use think\db\Query;

/**
 * 下游主/子账号 merchant_account。
 */
class MerchantAccount extends Controller
{
    use MojopayListTrait;
    use LookupTrait;
    use EmbedViewTrait;
    use MerchantScopeTrait;

    public function index()
    {
        $this->title = '主/子账号';
        $get         = $this->request->get();
        $result      = $this->paginateMojopay(
            $this->filteredQuery($get)->order('a.id', 'desc'),
            $get,
            'merchant_account/index',
            30
        );
        $list = $result->items();
        $merchants = $this->merchantOptions();
        foreach ($list as &$row) {
            $row = $this->formatRow($row, $merchants);
        }
        unset($row);

        $this->assign([
            'list'      => $list,
            'pagehtml'  => $result->render(),
            'get'       => $get,
            'merchants' => $merchants,
            'statuses'  => array_combine(Enum::getProviderStatuses(), Enum::getProviderStatuses()),
        ]);

        return $this->fetchPage('index');
    }

    public function add()
    {
        $scopeMid = $this->merchantScopeId();

        if ($this->request->isPost()) {
            try {
                $data = $this->buildFormData($this->request->post());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            if ($this->duplicateCode($data)) {
                return $this->error('同商户下账号编码已存在');
            }

            $data['public_id'] = 'macc_' . bin2hex(random_bytes(16));
            $this->mojopayDb()->name('merchant_account')->insert($data);

            return $this->success('新增成功');
        }

        $defaultParentId = $scopeMid ? $this->resolveDefaultParentId($scopeMid) : null;
        $this->assignMerchantScopeContext($scopeMid);
        $this->assignParentDefaults($scopeMid, $defaultParentId);
        $this->assign('vo', [
            'status'      => 'INACTIVE',
            'merchant_id' => $scopeMid ?: null,
        ]);
        $this->assign('is_add_form', true);
        $this->assignFormLookups($scopeMid);

        return $this->fetch('form');
    }

    public function edit()
    {
        if ($this->request->isPost()) {
            $id = $this->sanitizeId($this->request->post('id'));
            $existing = $id ? $this->loadRow($id) : null;
            if ($existing === null) {
                return $this->error('记录不存在');
            }
            try {
                $this->assertMerchantScope((int)$existing['merchant_id']);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            try {
                $data = $this->buildFormData($this->request->post(), $id);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            if ($this->duplicateCode($data, $id)) {
                return $this->error('同商户下账号编码已存在');
            }

            $this->mojopayDb()->name('merchant_account')->where('id', $id)->update($data);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        $vo = $id ? $this->loadRow($id) : null;
        if ($vo === null) {
            return $this->error('记录不存在');
        }
        $mid = (int)($vo['merchant_id'] ?? 0);
        try {
            $this->assertMerchantScope($mid);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
        $this->assignMerchantScopeContext($this->merchantScopeId() ?: $mid);
        $this->assignParentDefaults($mid, !empty($vo['parent_id']) ? (int)$vo['parent_id'] : null);
        $this->assignFormLookups($mid);
        $this->assign('vo', $vo);
        $this->assign('is_add_form', false);

        return $this->fetch('form');
    }

    public function remove()
    {
        $id = $this->sanitizeId($this->request->post('id'));
        $row = $id ? $this->loadRow($id) : null;
        if ($row === null) {
            return $this->error('记录不存在');
        }
        try {
            $this->assertMerchantScope((int)$row['merchant_id']);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        $child = $this->mojopayDb()->name('merchant_account')->where('parent_id', $id)->find();
        if (!empty($child)) {
            return $this->error('存在子账号，无法删除');
        }

        $bind = $this->mojopayDb()->name('channel_binding')->where('merchant_account_id', $id)->find();
        if (!empty($bind)) {
            return $this->error('存在通道路由，无法删除');
        }

        $deleted = $this->mojopayDb()->name('merchant_account')->where('id', $id)->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败');
        }

        return $this->success('删除成功');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->mojopayDb()->name('merchant_account')->alias('a')
            ->leftJoin('merchant m', 'm.id = a.merchant_id')
            ->leftJoin('merchant_account pa', 'pa.id = a.parent_id')
            ->field('a.*,m.code as merchant_code,m.legal_name as merchant_name,pa.code as parent_code');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('a.id', $id);
            }
        }

        $mid = $this->sanitizeId($get['merchant_id'] ?? $this->request->get('merchant_id'));
        if ($mid) {
            $query->where('a.merchant_id', $mid);
        }

        $this->applyOptionalLike($query, $get, 'code', 'a.code', 64);

        if (!empty($get['status'])) {
            $st = $this->sanitizeEnum(strtoupper((string)$get['status']), Enum::getProviderStatuses());
            if ($st !== null) {
                $query->where('a.status', $st);
            }
        }

        return $query;
    }

    private function assignFormLookups(?int $merchantId = null): void
    {
        $scope = $merchantId ?? $this->merchantScopeId();
        $this->assign('merchant_options', $this->scopedMerchantOptions($scope));
        $this->assign('status_options', array_combine(Enum::getProviderStatuses(), Enum::getProviderStatuses()));
    }

    private function assignParentDefaults(?int $merchantId, ?int $parentId): void
    {
        if (!$parentId) {
            $this->assign([
                'parent_role_hint' => $merchantId ? '将创建主账号（本商户首个或未指定父账号时）' : '',
                'parent_code_hint' => '',
            ]);

            return;
        }

        $code = (string)$this->mojopayDb()->name('merchant_account')->where('id', $parentId)->value('code');
        $this->assign([
            'parent_role_hint' => '子账号',
            'parent_code_hint' => $code !== '' ? $code : ('#' . $parentId),
            'default_parent_id'  => $parentId,
        ]);
    }

    private function resolveDefaultParentId(int $merchantId): ?int
    {
        $mainId = $this->mojopayDb()->name('merchant_account')
            ->where('merchant_id', $merchantId)
            ->whereNull('parent_id')
            ->where('code', 'main')
            ->value('id');
        if ($mainId) {
            return (int)$mainId;
        }

        $anyMaster = $this->mojopayDb()->name('merchant_account')
            ->where('merchant_id', $merchantId)
            ->whereNull('parent_id')
            ->order('id', 'asc')
            ->value('id');

        return $anyMaster ? (int)$anyMaster : null;
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->mojopayDb()->name('merchant_account')->where('id', $id)->find();
        if (empty($row)) {
            return null;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildFormData(array $post, ?int $selfId = null): array
    {
        if ($selfId !== null) {
            $existing = $this->loadRow($selfId);
            if ($existing === null) {
                throw new \InvalidArgumentException('记录不存在');
            }
            $merchantId = (int)$existing['merchant_id'];
        } else {
            $merchantId = $this->merchantScopeId() ?: $this->sanitizeId($post['merchant_id'] ?? null);
        }
        if (!$merchantId || !$this->mojopayDb()->name('merchant')->where('id', $merchantId)->find()) {
            throw new \InvalidArgumentException('请选择有效商户');
        }
        $this->assertMerchantScope($merchantId);

        $code = trim((string)($post['code'] ?? ''));
        if ($code === '' || strlen($code) > 64 || !preg_match('/^[A-Za-z0-9._-]+$/', $code)) {
            throw new \InvalidArgumentException('code 必填，64 字符内，仅允许字母数字 . _ -');
        }

        $status = $this->sanitizeEnum(strtoupper((string)($post['status'] ?? '')), Enum::getProviderStatuses());
        if ($status === null) {
            throw new \InvalidArgumentException('status 无效');
        }

        if ($selfId !== null) {
            $parentId = !empty($existing['parent_id']) ? (int)$existing['parent_id'] : null;
        } else {
            $parentId = $this->resolveDefaultParentId($merchantId);
        }

        return [
            'merchant_id' => $merchantId,
            'parent_id'   => $parentId,
            'code'        => $code,
            'status'      => $status,
        ];
    }

    /** @param array<string, mixed> $data */
    private function duplicateCode(array $data, ?int $exceptId = null): bool
    {
        $q = $this->mojopayDb()->name('merchant_account')
            ->where('merchant_id', $data['merchant_id'])
            ->where('code', $data['code']);
        if ($exceptId) {
            $q->where('id', '<>', $exceptId);
        }

        return !empty($q->find());
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $merchants
     */
    private function formatRow(array $row, array $merchants): array
    {
        $row['created_at']     = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at']     = $this->formatDatetime($row['updated_at'] ?? null);
        $mid                   = (int)($row['merchant_id'] ?? 0);
        $row['merchant_label'] = (string)($row['merchant_name'] ?? ($merchants[$mid] ?? ''));
        $row['role_label']     = empty($row['parent_id']) ? 'MASTER' : 'SUB';
        $row['parent_label']   = empty($row['parent_id']) ? '—' : ((string)($row['parent_code'] ?? '') ?: '#' . (int)$row['parent_id']);

        return $row;
    }
}