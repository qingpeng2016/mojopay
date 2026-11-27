<?php

declare (strict_types=1);

namespace app\arbopp\controller;

use app\arbopp\const\Enum;
use app\arbopp\trait\ArboppListTrait;
use think\admin\Controller;
use think\db\Query;

class Account extends Controller
{
    use ArboppListTrait;

    private const SECRET_PLACEHOLDER = '******';

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '下单账户';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)->order('id', 'asc');
        $result      = $this->paginateArbopp($query, $get, 'account/index');
        $list        = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'       => $list,
            'pagehtml'   => $result->render(),
            'get'        => $get,
            'platforms'  => array_combine(Enum::getPlatforms(), Enum::getPlatforms()),
            'statuses'   => array_combine(Enum::getAccountStatuses(), Enum::getAccountStatuses()),
            'auth_modes' => array_combine(Enum::getAuthModes(), Enum::getAuthModes()),
        ]);

        return $this->fetch();
    }

    /**
     * @auth true
     */
    public function add()
    {
        if ($this->request->isPost()) {
            try {
                $data = $this->buildFormData($this->request->post(), false);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            if ($this->findDuplicate($data['platform'], $data['name'], null)) {
                return $this->error('同平台下账户别名已存在');
            }

            $this->arboppDb()->name('account')->insert($data);

            return $this->success('新增成功');
        }

        $this->assignFormLookups();
        $this->assign('vo', [
            'auth_mode'         => 'KMS',
            'status'            => 'ACTIVE',
            'use_magic_account' => 0,
            'remark'            => '',
        ]);

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
            if ($this->loadRow($id, false) === null) {
                return $this->error('记录不存在');
            }

            try {
                $data = $this->buildFormData($this->request->post(), true);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            if ($this->findDuplicate($data['platform'], $data['name'], $id)) {
                return $this->error('同平台下账户别名已存在');
            }

            $this->arboppDb()->name('account')->where('id', $id)->update($data);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }
        $vo = $this->loadRow($id, true);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $this->assignFormLookups();
        $this->assign('vo', $vo);

        return $this->fetch('form');
    }

    /**
     * @auth true
     */
    public function remove()
    {
        $ids = $this->sanitizeIdList($this->request->post('id'));
        if ($ids === []) {
            return $this->error('参数错误');
        }

        $deleted = $this->arboppDb()->name('account')->whereIn('id', $ids)->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败');
        }

        return $this->success($deleted > 1 ? sprintf('已删除 %d 条记录', $deleted) : '删除成功');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->arboppDb()->name('account');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }
        if (!empty($get['platform'])) {
            $p = $this->sanitizeEnum(strtoupper(trim((string)$get['platform'])), Enum::getPlatforms());
            if ($p) {
                $query->where('platform', $p);
            }
        }
        if (!empty($get['name'])) {
            $name = trim((string)$get['name']);
            if ($name !== '') {
                $query->whereLike('name', '%' . $this->sanitizeLike($name, 64) . '%');
            }
        }
        $status = $this->sanitizeEnum(strtoupper(trim((string)($get['status'] ?? ''))), Enum::getAccountStatuses());
        if ($status !== null) {
            $query->where('status', $status);
        }
        $authMode = $this->sanitizeEnum(strtoupper(trim((string)($get['auth_mode'] ?? ''))), Enum::getAuthModes());
        if ($authMode !== null) {
            $query->where('auth_mode', $authMode);
        }

        return $query;
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id, bool $forDisplay): ?array
    {
        $row = $this->arboppDb()->name('account')->where('id', $id)->find();
        if (empty($row)) {
            return null;
        }

        return $this->formatRow($row, $forDisplay);
    }

    private function findDuplicate(string $platform, string $name, ?int $excludeId): bool
    {
        $q = $this->arboppDb()->name('account')
            ->where('platform', $platform)
            ->where('name', $name);
        if ($excludeId) {
            $q->where('id', '<>', $excludeId);
        }

        return !empty($q->find());
    }

    private function assignFormLookups(): void
    {
        $this->assign('platforms', Enum::getPlatforms());
        $this->assign('auth_modes', Enum::getAuthModes());
        $this->assign('statuses', Enum::getAccountStatuses());
        $this->assign('foregate_types', Enum::getForegateAccountTypes());
        $this->assign('yes_no', Enum::getYesNo());
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildFormData(array $post, bool $isEdit): array
    {
        $platform = $this->sanitizeEnum(strtoupper(trim((string)($post['platform'] ?? ''))), Enum::getPlatforms());
        if ($platform === null) {
            throw new \InvalidArgumentException('platform 无效');
        }

        $name = trim((string)($post['name'] ?? ''));
        if ($name === '' || strlen($name) > 64) {
            throw new \InvalidArgumentException('name 不能为空且不超过 64 字符');
        }

        $authMode = $this->sanitizeEnum(strtoupper(trim((string)($post['auth_mode'] ?? 'KMS'))), Enum::getAuthModes());
        if ($authMode === null) {
            throw new \InvalidArgumentException('auth_mode 无效');
        }

        $status = $this->sanitizeEnum(strtoupper(trim((string)($post['status'] ?? 'ACTIVE'))), Enum::getAccountStatuses());
        if ($status === null) {
            throw new \InvalidArgumentException('status 无效');
        }

        $useMagic = $this->sanitizeEnum((string)($post['use_magic_account'] ?? '0'), Enum::getYesNo());
        if ($useMagic === null) {
            throw new \InvalidArgumentException('use_magic_account 须为 0 或 1');
        }

        $data = [
            'platform'              => $platform,
            'name'                  => $name,
            'auth_mode'             => $authMode,
            'status'                => $status,
            'use_magic_account'     => (int)$useMagic,
            'api_key'               => $this->nullableString($post['api_key'] ?? '', 256),
            'builder_api_key'       => $this->nullableString($post['builder_api_key'] ?? '', 128),
            'key_id'                => $this->nullableString($post['key_id'] ?? '', 256),
            'kms_sign_address'      => $this->nullableString($post['kms_sign_address'] ?? '', 128),
            'eoa_address'           => $this->nullableString($post['eoa_address'] ?? '', 128),
            'proxy_address'         => $this->nullableString($post['proxy_address'] ?? '', 128),
            'signature_type'        => $this->optionalInt($post['signature_type'] ?? null),
            'foregate_user_id'      => $this->optionalInt($post['foregate_user_id'] ?? null),
            'account_type'          => $this->optionalForegateType($post['account_type'] ?? null),
            'login_name'            => $this->nullableString($post['login_name'] ?? '', 128),
            'order_max_pending_time' => $this->optionalInt($post['order_max_pending_time'] ?? null),
            'config'                => $this->nullableText($post['config'] ?? ''),
            'remark'                => $this->nullableString($post['remark'] ?? '', 500) ?? '',
        ];

        $this->mergeSecret($data, $post, 'api_secret_enc', 512, $isEdit, false);
        $this->mergeSecret($data, $post, 'api_passphrase_enc', 512, $isEdit, false);
        $this->mergeSecret($data, $post, 'builder_api_secret_enc', 512, $isEdit, false);
        $this->mergeSecret($data, $post, 'builder_api_passphrase_enc', 512, $isEdit, false);
        $this->mergeSecret($data, $post, 'private_key_enc', 1024, $isEdit, false);
        $this->mergeSecret($data, $post, 'login_password_enc', 512, $isEdit, false);
        $this->mergeSecret($data, $post, 'fg_token_enc', 512, $isEdit, false);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $post
     */
    private function mergeSecret(array &$data, array $post, string $field, int $max, bool $isEdit, bool $requiredOnCreate): void
    {
        $raw = trim((string)($post[$field] ?? ''));
        if ($raw === '' || $raw === self::SECRET_PLACEHOLDER) {
            if (!$isEdit && $requiredOnCreate) {
                throw new \InvalidArgumentException($field . ' 新增时不能为空');
            }

            return;
        }
        if (strlen($raw) > $max) {
            throw new \InvalidArgumentException($field . ' 过长');
        }
        $data[$field] = $raw;
    }

    private function optionalForegateType($value): ?string
    {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return null;
        }

        return $this->sanitizeEnum($value, Enum::getForegateAccountTypes());
    }

    private function optionalInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('整数字段格式无效');
        }
        $n = (int)$value;

        return $n;
    }

    private function nullableString($value, int $max): ?string
    {
        $s = trim((string)$value);
        if ($s === '') {
            return null;
        }
        if (strlen($s) > $max) {
            throw new \InvalidArgumentException('文本字段过长');
        }

        return $s;
    }

    private function nullableText($value): ?string
    {
        $s = trim((string)$value);

        return $s === '' ? null : $s;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row, bool $maskSecrets = false): array
    {
        if ($maskSecrets) {
            foreach ([
                'api_secret_enc',
                'api_passphrase_enc',
                'builder_api_secret_enc',
                'builder_api_passphrase_enc',
                'private_key_enc',
                'login_password_enc',
                'fg_token_enc',
            ] as $col) {
                if (!empty($row[$col])) {
                    $row[$col . '_display'] = self::SECRET_PLACEHOLDER;
                }
            }
        }
        $row['created_at'] = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at'] = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}