<?php

declare (strict_types=1);

namespace app\mojopay\controller;

use app\mojopay\const\Enum;
use app\mojopay\trait\EmbedViewTrait;
use app\mojopay\trait\LookupTrait;
use app\mojopay\trait\MojopayListTrait;
use think\admin\Controller;
use think\db\Query;

/**
 * 上游通道能力 channel_capability。
 */
class ChannelCapability extends Controller
{
    use MojopayListTrait;
    use LookupTrait;
    use EmbedViewTrait;

    public function index()
    {
        $this->title = '通道路由';
        $get         = $this->request->get();
        $result      = $this->paginateMojopay(
            $this->filteredQuery($get)->order('c.id', 'desc'),
            $get,
            'channel_capability/index',
            30
        );
        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $lockPid = $this->sanitizeId($get['provider_id'] ?? $this->request->get('provider_id'));
        $this->assign([
            'list'        => $list,
            'pagehtml'    => $result->render(),
            'get'         => $get,
            'operations'  => array_combine(Enum::getChannelOperations(), Enum::getChannelOperations()),
            'statuses'    => array_combine(Enum::getChannelCapabilityStatuses(), Enum::getChannelCapabilityStatuses()),
            'accounts'    => $this->providerAccountOptions($lockPid),
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

            if ($this->duplicateCapability($data)) {
                return $this->error('同账号下 capability_key + version 已存在');
            }

            $this->mojopayDb()->name('channel_capability')->insert($data);

            return $this->success('新增成功');
        }

        $this->assign('vo', [
            'version'    => 1,
            'min_amount' => '0',
            'region'     => 'US',
            'status'     => 'INACTIVE',
            'operation'  => 'PAYIN',
        ]);
        $this->assignFormLookups();

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
                $data = $this->buildFormData($this->request->post());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            if ($this->duplicateCapability($data, $id)) {
                return $this->error('同账号下 capability_key + version 已存在');
            }

            $this->mojopayDb()->name('channel_capability')->where('id', $id)->update($data);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        $vo = $id ? $this->loadRow($id) : null;
        if ($vo === null) {
            return $this->error('记录不存在');
        }
        if (!empty($vo['constraints'])) {
            if (is_array($vo['constraints'])) {
                $vo['constraints_text'] = json_encode($vo['constraints'], JSON_UNESCAPED_UNICODE);
            } else {
                $vo['constraints_text'] = (string)$vo['constraints'];
            }
        }
        $this->assignFormLookups();
        $this->assign('vo', $vo);

        return $this->fetch('form');
    }

    public function remove()
    {
        $id = $this->sanitizeId($this->request->post('id'));
        if (!$id || $this->loadRow($id) === null) {
            return $this->error('记录不存在');
        }

        $deleted = $this->mojopayDb()->name('channel_capability')->where('id', $id)->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败');
        }

        return $this->success('删除成功');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->mojopayDb()->name('channel_capability')->alias('c')
            ->leftJoin('provider_account a', 'a.id = c.provider_account_id')
            ->leftJoin('provider p', 'p.id = a.provider_id')
            ->leftJoin('asset ast', 'ast.id = c.asset_id')
            ->field('c.*,a.external_id as account_external_id,a.environment,p.name as provider_name,ast.code as asset_code');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('c.id', $id);
            }
        }

        $pid = $this->sanitizeId($get['provider_id'] ?? $this->request->get('provider_id'));
        if ($pid) {
            $ids = $this->accountIdsForProvider($pid);
            $query->whereIn('c.provider_account_id', $ids ?: [0]);
        }

        if (!empty($get['provider_account_id'])) {
            $aid = $this->sanitizeId($get['provider_account_id']);
            if ($aid) {
                $query->where('c.provider_account_id', $aid);
            }
        }

        if (!empty($get['operation'])) {
            $op = $this->sanitizeEnum(strtoupper((string)$get['operation']), Enum::getChannelOperations());
            if ($op !== null) {
                $query->where('c.operation', $op);
            }
        }

        if (!empty($get['status'])) {
            $st = $this->sanitizeEnum(strtoupper((string)$get['status']), Enum::getChannelCapabilityStatuses());
            if ($st !== null) {
                $query->where('c.status', $st);
            }
        }

        $this->applyOptionalLike($query, $get, 'capability_key', 'c.capability_key', 64);

        return $query;
    }

    private function assignFormLookups(): void
    {
        $this->assign('account_options', $this->providerAccountOptions());
        $this->assign('asset_options', $this->assetOptions());
        $this->assign('operation_options', array_combine(Enum::getChannelOperations(), Enum::getChannelOperations()));
        $this->assign('status_options', array_combine(Enum::getChannelCapabilityStatuses(), Enum::getChannelCapabilityStatuses()));
        $this->assign('region_zh_map', Enum::getRegionNameZhMap());
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->mojopayDb()->name('channel_capability')->where('id', $id)->find();
        if (empty($row)) {
            return null;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildFormData(array $post): array
    {
        $accountId = $this->sanitizeId($post['provider_account_id'] ?? null);
        if (!$accountId || !$this->mojopayDb()->name('provider_account')->where('id', $accountId)->find()) {
            throw new \InvalidArgumentException('请选择有效上游账号');
        }

        $assetId = $this->sanitizeId($post['asset_id'] ?? null);
        if (!$assetId || !$this->mojopayDb()->name('asset')->where('id', $assetId)->find()) {
            throw new \InvalidArgumentException('请选择有效资产');
        }

        $networkId = null;
        if (!empty($post['network_id'])) {
            $networkId = $this->sanitizeId($post['network_id']);
            if (!$networkId) {
                throw new \InvalidArgumentException('network_id 无效');
            }
        }

        $settlementAssetId = null;
        if (!empty($post['settlement_asset_id'])) {
            $settlementAssetId = $this->sanitizeId($post['settlement_asset_id']);
        }

        $operation = $this->sanitizeEnum(strtoupper((string)($post['operation'] ?? '')), Enum::getChannelOperations());
        if ($operation === null) {
            throw new \InvalidArgumentException('operation 无效');
        }

        $method = strtoupper(trim((string)($post['method'] ?? '')));
        if ($method === '' || strlen($method) > 32 || !preg_match('/^[A-Z0-9_]+$/', $method)) {
            throw new \InvalidArgumentException('method 必填，32 字符内，大写字母数字下划线');
        }

        $region = strtoupper(trim((string)($post['region'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $region)) {
            throw new \InvalidArgumentException('region 须为两位国家码');
        }

        $regionName = trim((string)($post['region_name'] ?? ''));
        if ($regionName === '') {
            $regionName = Enum::regionNameZhByCode($region);
        }
        if (strlen($regionName) > 64) {
            throw new \InvalidArgumentException('地区中文不超过 64 字符');
        }
        if ($regionName === '') {
            throw new \InvalidArgumentException('请填写地区中文名称，或选择常用国家码以自动带出');
        }

        $capabilityKey = strtolower(trim((string)($post['capability_key'] ?? '')));
        if ($capabilityKey === '' || strlen($capabilityKey) > 64 || !preg_match('/^[a-z0-9._-]+$/', $capabilityKey)) {
            throw new \InvalidArgumentException('capability_key 无效');
        }

        $version = max(1, (int)($post['version'] ?? 1));

        $minAmount = trim((string)($post['min_amount'] ?? '0'));
        $maxAmount = trim((string)($post['max_amount'] ?? ''));
        if ($maxAmount === '' || !preg_match('/^\d+$/', $minAmount) || !preg_match('/^\d+$/', $maxAmount)) {
            throw new \InvalidArgumentException('金额须为非负整数（最小单位）');
        }
        if ((int)$maxAmount < (int)$minAmount) {
            throw new \InvalidArgumentException('max_amount 不能小于 min_amount');
        }

        $status = $this->sanitizeEnum(strtoupper((string)($post['status'] ?? '')), Enum::getChannelCapabilityStatuses());
        if ($status === null) {
            throw new \InvalidArgumentException('status 无效');
        }

        $constraints = null;
        $constraintsText = trim((string)($post['constraints'] ?? ''));
        if ($constraintsText !== '') {
            try {
                json_decode($constraintsText, true, 512, JSON_THROW_ON_ERROR);
                $constraints = $constraintsText;
            } catch (\JsonException) {
                throw new \InvalidArgumentException('constraints 须为合法 JSON');
            }
        }

        return [
            'provider_account_id' => $accountId,
            'asset_id'            => $assetId,
            'network_id'          => $networkId,
            'settlement_asset_id' => $settlementAssetId,
            'operation'           => $operation,
            'method'              => $method,
            'region'              => $region,
            'region_name'         => $regionName,
            'capability_key'      => $capabilityKey,
            'version'             => $version,
            'min_amount'          => $minAmount,
            'max_amount'          => $maxAmount,
            'constraints'         => $constraints,
            'status'              => $status,
        ];
    }

    /** @param array<string, mixed> $data */
    private function duplicateCapability(array $data, ?int $exceptId = null): bool
    {
        $q = $this->mojopayDb()->name('channel_capability')
            ->where('provider_account_id', $data['provider_account_id'])
            ->where('capability_key', $data['capability_key'])
            ->where('version', $data['version']);
        if ($exceptId) {
            $q->where('id', '<>', $exceptId);
        }

        return !empty($q->find());
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['created_at'] = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at'] = $this->formatDatetime($row['updated_at'] ?? null);
        $st                = (string)($row['status'] ?? '');
        $row['status_text'] = $st === 'ACTIVE' ? '已启用' : '未启用';
        $regionName         = trim((string)($row['region_name'] ?? ''));
        if ($regionName === '') {
            $regionName = Enum::regionNameZhByCode((string)($row['region'] ?? ''));
            $row['region_name'] = $regionName;
        }

        return $row;
    }
}