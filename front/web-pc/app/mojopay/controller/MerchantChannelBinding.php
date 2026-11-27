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
 * 商户通道路由 channel_binding。
 */
class MerchantChannelBinding extends Controller
{
    use MojopayListTrait;
    use LookupTrait;
    use EmbedViewTrait;
    use MerchantScopeTrait;

    public function index()
    {
        $this->title = '通道路由';
        $get         = $this->request->get();
        $result      = $this->paginateMojopay(
            $this->filteredQuery($get)->order('b.id', 'desc'),
            $get,
            'merchant_channel_binding/index',
            30
        );
        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $lockMid = $this->sanitizeId($get['merchant_id'] ?? $this->request->get('merchant_id'));
        $this->assign([
            'list'         => $list,
            'pagehtml'     => $result->render(),
            'get'          => $get,
            'merchants'    => $this->merchantOptions(),
            'accounts'     => $this->merchantAccountOptions($lockMid),
            'statuses'     => array_combine(Enum::getChannelCapabilityStatuses(), Enum::getChannelCapabilityStatuses()),
        ]);

        return $this->fetchPage('index');
    }

    public function add()
    {
        if ($this->request->isPost()) {
            try {
                $data = $this->buildFormData($this->request->post());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            if ($this->duplicateVersion($data)) {
                return $this->error('同账号与能力下版本已存在');
            }

            $data['public_id'] = 'cbind_' . bin2hex(random_bytes(16));
            $this->mojopayDb()->name('channel_binding')->insert($data);

            return $this->success('新增成功');
        }

        $scopeMid = $this->merchantScopeId();
        $this->assign('vo', [
            'version'        => 1,
            'priority'       => 100,
            'weight'         => 1,
            'status'         => 'INACTIVE',
            'effective_from' => date('Y-m-d H:i:s'),
        ]);
        $this->assignFormLookups($scopeMid);

        return $this->fetch('form');
    }

    public function edit()
    {
        if ($this->request->isPost()) {
            $id = $this->sanitizeId($this->request->post('id'));
            if (!$id || $this->loadRow($id) === null) {
                return $this->error('记录不存在');
            }

            try {
                $data = $this->buildFormData($this->request->post(), $id);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            if ($this->duplicateVersion($data, $id)) {
                return $this->error('同账号与能力下版本已存在');
            }

            $this->mojopayDb()->name('channel_binding')->where('id', $id)->update($data);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        $vo = $id ? $this->loadRow($id) : null;
        if ($vo === null) {
            return $this->error('记录不存在');
        }
        $mid = (int)$this->mojopayDb()->name('merchant_account')->where('id', $vo['merchant_account_id'])->value('merchant_id');
        try {
            $this->assertMerchantScope($mid);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
        $this->assignFormLookups($this->merchantScopeId() ?: $mid);
        $vo['effective_from'] = $this->formatDatetime($vo['effective_from'] ?? null);
        $this->assign('vo', $vo);

        return $this->fetch('form');
    }

    public function remove()
    {
        $id = $this->sanitizeId($this->request->post('id'));
        $row = $id ? $this->loadRow($id) : null;
        if ($row === null) {
            return $this->error('记录不存在');
        }
        $mid = (int)$this->mojopayDb()->name('merchant_account')->where('id', $row['merchant_account_id'])->value('merchant_id');
        try {
            $this->assertMerchantScope($mid);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        $deleted = $this->mojopayDb()->name('channel_binding')->where('id', $id)->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败');
        }

        return $this->success('删除成功');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->mojopayDb()->name('channel_binding')->alias('b')
            ->join('merchant_account a', 'a.id = b.merchant_account_id')
            ->join('merchant m', 'm.id = a.merchant_id')
            ->join('channel_capability c', 'c.id = b.capability_id')
            ->field('b.*,a.code as account_code,m.legal_name as merchant_name,c.operation,c.capability_key,c.region');

        $mid = $this->sanitizeId($get['merchant_id'] ?? $this->request->get('merchant_id'));
        if ($mid) {
            $query->where('a.merchant_id', $mid);
        }

        if (!empty($get['merchant_account_id'])) {
            $aid = $this->sanitizeId($get['merchant_account_id']);
            if ($aid) {
                $query->where('b.merchant_account_id', $aid);
            }
        }

        return $query;
    }

    private function assignFormLookups(?int $merchantId = null): void
    {
        $scope = $merchantId ?? $this->merchantScopeId();
        $this->assignMerchantScopeContext($scope);
        $this->assign('account_options', $this->merchantAccountOptions($scope));
        $this->assign('capability_options', $this->channelCapabilityOptions());
        $this->assign('status_options', array_combine(Enum::getChannelCapabilityStatuses(), Enum::getChannelCapabilityStatuses()));
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        return $this->mojopayDb()->name('channel_binding')->where('id', $id)->find() ?: null;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildFormData(array $post, ?int $selfId = null): array
    {
        $accountId = $this->sanitizeId($post['merchant_account_id'] ?? null);
        if (!$accountId) {
            throw new \InvalidArgumentException('请选择商户账号');
        }
        $this->assertMerchantAccountInScope($accountId);

        $capId = $this->sanitizeId($post['capability_id'] ?? null);
        if (!$capId || !$this->mojopayDb()->name('channel_capability')->where('id', $capId)->find()) {
            throw new \InvalidArgumentException('请选择有效通道能力');
        }

        $version = (int)($post['version'] ?? 0);
        if ($version <= 0) {
            throw new \InvalidArgumentException('version 须大于 0');
        }

        $priority = (int)($post['priority'] ?? 100);
        $weight   = max(1, (int)($post['weight'] ?? 1));

        $status = $this->sanitizeEnum(strtoupper((string)($post['status'] ?? '')), Enum::getChannelCapabilityStatuses());
        if ($status === null) {
            throw new \InvalidArgumentException('status 无效');
        }

        $effectiveFrom = trim((string)($post['effective_from'] ?? ''));
        if ($effectiveFrom === '') {
            $effectiveFrom = date('Y-m-d H:i:s');
        }

        return [
            'merchant_account_id' => $accountId,
            'capability_id'     => $capId,
            'version'           => $version,
            'priority'          => $priority,
            'weight'            => $weight,
            'status'            => $status,
            'effective_from'    => $effectiveFrom,
        ];
    }

    /** @param array<string, mixed> $data */
    private function duplicateVersion(array $data, ?int $exceptId = null): bool
    {
        $q = $this->mojopayDb()->name('channel_binding')
            ->where('merchant_account_id', $data['merchant_account_id'])
            ->where('capability_id', $data['capability_id'])
            ->where('version', $data['version']);
        if ($exceptId) {
            $q->where('id', '<>', $exceptId);
        }

        return !empty($q->find());
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['created_at']      = $this->formatDatetime($row['created_at'] ?? null);
        $row['effective_from']  = $this->formatDatetime($row['effective_from'] ?? null);
        $row['capability_label'] = sprintf('%s %s %s', (string)$row['operation'], (string)$row['region'], (string)$row['capability_key']);

        return $row;
    }
}