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
 * 上游主/子账号 provider_account。
 */
class ProviderAccount extends Controller
{
    use MojopayListTrait;
    use LookupTrait;
    use EmbedViewTrait;

    public function index()
    {
        $this->title = '主/子账号';
        $get         = $this->request->get();
        $result      = $this->paginateMojopay(
            $this->filteredQuery($get)->order('id', 'desc'),
            $get,
            'provider_account/index',
            30
        );
        $list = $result->items();
        $providers = $this->providerOptions();
        foreach ($list as &$row) {
            $row = $this->formatRow($row, $providers);
        }
        unset($row);

        $this->assign([
            'list'          => $list,
            'pagehtml'      => $result->render(),
            'get'           => $get,
            'providers'     => $providers,
            'environments'  => array_combine(Enum::getProviderEnvironments(), Enum::getProviderEnvironments()),
            'roles'         => array_combine(Enum::getProviderAccountRoles(), Enum::getProviderAccountRoles()),
            'statuses'      => array_combine(Enum::getProviderStatuses(), Enum::getProviderStatuses()),
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

            if ($this->duplicateRef($data)) {
                return $this->error('同上游环境下 external_id 已存在');
            }

            $this->mojopayDb()->name('provider_account')->insert($data);

            return $this->success('新增成功');
        }

        $lockPid = $this->sanitizeId($this->request->get('provider_id'));
        $this->assign('vo', [
            'environment' => 'SANDBOX',
            'role'        => 'MASTER',
            'status'      => 'INACTIVE',
            'provider_id' => $lockPid ?: null,
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
                $data = $this->buildFormData($this->request->post(), $id);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            if ($this->duplicateRef($data, $id)) {
                return $this->error('同上游环境下 external_id 已存在');
            }

            $this->mojopayDb()->name('provider_account')->where('id', $id)->update($data);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        $vo = $id ? $this->loadRow($id) : null;
        if ($vo === null) {
            return $this->error('记录不存在');
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

        $child = $this->mojopayDb()->name('provider_account')->where('parent_id', $id)->find();
        if (!empty($child)) {
            return $this->error('存在子账号，无法删除');
        }

        $cap = $this->mojopayDb()->name('channel_capability')->where('provider_account_id', $id)->find();
        if (!empty($cap)) {
            return $this->error('存在通道能力配置，无法删除');
        }

        $deleted = $this->mojopayDb()->name('provider_account')->where('id', $id)->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败');
        }

        return $this->success('删除成功');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->mojopayDb()->name('provider_account')->alias('a')
            ->leftJoin('provider p', 'p.id = a.provider_id')
            ->leftJoin('provider_account pa', 'pa.id = a.parent_id')
            ->field('a.*,p.code as provider_code,p.name as provider_name,pa.external_id as parent_external_id,pa.role as parent_role');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('a.id', $id);
            }
        }

        $pid = $this->sanitizeId($get['provider_id'] ?? $this->request->get('provider_id'));
        if ($pid) {
            $query->where('a.provider_id', $pid);
        }

        if (!empty($get['environment'])) {
            $env = $this->sanitizeEnum(strtoupper((string)$get['environment']), Enum::getProviderEnvironments());
            if ($env !== null) {
                $query->where('a.environment', $env);
            }
        }

        $this->applyOptionalLike($query, $get, 'external_id', 'a.external_id', 128);

        if (!empty($get['status'])) {
            $st = $this->sanitizeEnum(strtoupper((string)$get['status']), Enum::getProviderStatuses());
            if ($st !== null) {
                $query->where('a.status', $st);
            }
        }

        return $query;
    }

    private function assignFormLookups(): void
    {
        $this->assign('provider_options', $this->providerOptions());
        $this->assign('parent_options', $this->providerAccountOptions());
        $this->assign('env_options', array_combine(Enum::getProviderEnvironments(), Enum::getProviderEnvironments()));
        $this->assign('role_options', array_combine(Enum::getProviderAccountRoles(), Enum::getProviderAccountRoles()));
        $this->assign('status_options', array_combine(Enum::getProviderStatuses(), Enum::getProviderStatuses()));
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->mojopayDb()->name('provider_account')->where('id', $id)->find();
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
        $providerId = $this->sanitizeId($post['provider_id'] ?? null);
        if (!$providerId || !$this->mojopayDb()->name('provider')->where('id', $providerId)->find()) {
            throw new \InvalidArgumentException('请选择有效上游');
        }

        $environment = $this->sanitizeEnum(strtoupper((string)($post['environment'] ?? '')), Enum::getProviderEnvironments());
        if ($environment === null) {
            throw new \InvalidArgumentException('environment 无效');
        }

        $externalId = trim((string)($post['external_id'] ?? ''));
        if ($externalId === '' || strlen($externalId) > 128) {
            throw new \InvalidArgumentException('external_id 必填且不超过 128 字符');
        }

        $role = $this->sanitizeEnum(strtoupper((string)($post['role'] ?? '')), Enum::getProviderAccountRoles());
        if ($role === null) {
            throw new \InvalidArgumentException('role 无效');
        }

        $status = $this->sanitizeEnum(strtoupper((string)($post['status'] ?? '')), Enum::getProviderStatuses());
        if ($status === null) {
            throw new \InvalidArgumentException('status 无效');
        }

        $parentId = null;
        if (!empty($post['parent_id'])) {
            $parentId = $this->sanitizeId($post['parent_id']);
            if ($parentId === $selfId) {
                throw new \InvalidArgumentException('父账号不能是自己');
            }
            $parent = $this->mojopayDb()->name('provider_account')->where('id', $parentId)->find();
            if (empty($parent) || (int)$parent['provider_id'] !== $providerId || (string)$parent['environment'] !== $environment) {
                throw new \InvalidArgumentException('父账号须属于同一上游且同一环境');
            }
        }

        if ($role === 'MASTER' && $parentId !== null) {
            throw new \InvalidArgumentException('MASTER 账号不应设置父账号');
        }

        if ($role === 'SUB' && $parentId === null) {
            throw new \InvalidArgumentException('SUB 子账号必须选择父账号（MASTER）');
        }

        $credentialRef = trim((string)($post['credential_ref'] ?? ''));

        $data = [
            'provider_id'  => $providerId,
            'parent_id'    => $parentId,
            'environment'  => $environment,
            'external_id'  => $externalId,
            'role'         => $role,
            'status'       => $status,
            'credential_ref' => $credentialRef === '' ? null : substr($credentialRef, 0, 255),
        ];

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function duplicateRef(array $data, ?int $exceptId = null): bool
    {
        $q = $this->mojopayDb()->name('provider_account')
            ->where('provider_id', $data['provider_id'])
            ->where('environment', $data['environment'])
            ->where('external_id', $data['external_id']);
        if ($exceptId) {
            $q->where('id', '<>', $exceptId);
        }

        return !empty($q->find());
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $providers
     */
    private function formatRow(array $row, array $providers): array
    {
        $row['created_at']    = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at']    = $this->formatDatetime($row['updated_at'] ?? null);
        $pid                  = (int)($row['provider_id'] ?? 0);
        $row['provider_label'] = (string)($row['provider_name'] ?? ($providers[$pid] ?? ''));
        $st                   = (string)($row['status'] ?? '');
        $row['status_text']   = $st === 'ACTIVE' ? '已启用' : '未启用';
        $row['parent_label']  = $this->formatParentLabel($row);

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function formatParentLabel(array $row): string
    {
        if ((string)($row['role'] ?? '') === 'MASTER') {
            return '—';
        }
        $pid = (int)($row['parent_id'] ?? 0);
        if ($pid <= 0) {
            return '未绑定';
        }
        $ext = trim((string)($row['parent_external_id'] ?? ''));
        if ($ext !== '') {
            return $ext . ' (#' . $pid . ')';
        }

        return '#' . $pid;
    }
}