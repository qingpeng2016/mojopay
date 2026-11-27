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
 * 商户 OpenAPI 密钥与 Webhook 回调。
 */
class MerchantOpenApi extends Controller
{
    use MojopayListTrait;
    use LookupTrait;
    use EmbedViewTrait;
    use MerchantScopeTrait;

    public function index()
    {
        $this->title = 'OpenApi';
        $get         = $this->request->get();
        $mid         = $this->sanitizeId($get['merchant_id'] ?? $this->request->get('merchant_id'));

        $result = $this->paginateMojopay(
            $this->filteredKeyQuery($get)->order('k.id', 'desc'),
            $get,
            'merchant_open_api/index',
            30
        );
        $keyList = $result->items();
        foreach ($keyList as &$row) {
            $row = $this->formatKeyRow($row);
        }
        unset($row);

        $webhooks = $this->fetchWebhooks($mid, $get);

        $this->assign([
            'key_list'     => $keyList,
            'webhook_list' => $webhooks,
            'pagehtml'     => $result->render(),
            'get'          => $get,
            'merchants'    => $this->merchantOptions(),
            'accounts'     => $this->merchantAccountOptions($mid),
            'environments' => array_combine(Enum::getProviderEnvironments(), Enum::getProviderEnvironments()),
        ]);

        return $this->fetchPage('index');
    }

    public function add()
    {
        $scopeMid = $this->merchantScopeId();

        if ($this->request->isPost()) {
            try {
                $data = $this->buildKeyFormData($this->request->post(), $scopeMid);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            if ($this->mojopayDb()->name('merchant_api_key')->where('key_id', $data['key_id'])->find()) {
                return $this->error('key_id 已存在');
            }

            $this->mojopayDb()->name('merchant_api_key')->insert($data);

            return $this->success('新增成功');
        }

        $preAccount = $this->sanitizeId($this->request->get('merchant_account_id'));
        $this->assignFormLookups($scopeMid);
        $this->assign('vo', [
            'merchant_account_id' => $preAccount ?: null,
            'environment'         => 'SANDBOX',
            'status'              => 'INACTIVE',
            'requests_per_minute' => 120,
            'scopes'              => '["payments:write","payments:read"]',
            'signature_version'   => 'v1',
        ]);

        return $this->fetch('form_add');
    }

    public function edit()
    {
        if ($this->request->isPost()) {
            $id = $this->sanitizeId($this->request->post('id'));
            $row = $id ? $this->mojopayDb()->name('merchant_api_key')->where('id', $id)->find() : null;
            if (empty($row)) {
                return $this->error('记录不存在');
            }
            try {
                $this->assertMerchantAccountInScope((int)$row['merchant_account_id']);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $status = $this->sanitizeEnum(strtoupper((string)($this->request->post('status') ?? '')), Enum::getProviderStatuses());
            if ($status === null) {
                return $this->error('status 无效');
            }

            $rpm = (int)($this->request->post('requests_per_minute') ?? 0);
            if ($rpm <= 0) {
                return $this->error('requests_per_minute 须大于 0');
            }

            try {
                $scopes = $this->parseScopesJson((string)($this->request->post('scopes') ?? ''));
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $secretRef = trim((string)($this->request->post('secret_ref') ?? ''));
            if ($secretRef === '') {
                return $this->error('secret_ref 不能为空');
            }

            $update = [
                'status'              => $status,
                'requests_per_minute' => $rpm,
                'scopes'              => json_encode($scopes, JSON_UNESCAPED_UNICODE),
                'secret_ref'          => substr($secretRef, 0, 255),
            ];

            $expires = trim((string)($this->request->post('expires_at') ?? ''));
            $update['expires_at'] = $expires === '' ? null : $expires;

            $this->mojopayDb()->name('merchant_api_key')->where('id', $id)->update($update);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        $vo = $id ? $this->mojopayDb()->name('merchant_api_key')->where('id', $id)->find() : null;
        if (empty($vo)) {
            return $this->error('记录不存在');
        }
        try {
            $this->assertMerchantAccountInScope((int)$vo['merchant_account_id']);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
        $vo['scopes'] = $this->scopesToDisplay($vo['scopes'] ?? '');
        $mid          = (int)$this->mojopayDb()->name('merchant_account')->where('id', $vo['merchant_account_id'])->value('merchant_id');
        $this->assignMerchantScopeContext($this->merchantScopeId() ?: $mid);
        $this->assign('status_options', array_combine(Enum::getProviderStatuses(), Enum::getProviderStatuses()));
        $this->assign('vo', $vo);

        return $this->fetch('form');
    }

    public function remove()
    {
        $id = $this->sanitizeId($this->request->post('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $row = $this->mojopayDb()->name('merchant_api_key')->where('id', $id)->find();
        if (!empty($row)) {
            try {
                $this->assertMerchantAccountInScope((int)$row['merchant_account_id']);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }
        }

        $deleted = $this->mojopayDb()->name('merchant_api_key')->where('id', $id)->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败');
        }

        return $this->success('删除成功');
    }

    public function webhook_add()
    {
        $scopeMid = $this->merchantScopeId();

        if ($this->request->isPost()) {
            try {
                $data = $this->buildWebhookFormData($this->request->post(), $scopeMid);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $data['public_id'] = 'wh_' . bin2hex(random_bytes(16));
            $this->mojopayDb()->name('merchant_webhook_endpoint')->insert($data);

            return $this->success('新增成功');
        }

        $preAccount = $this->sanitizeId($this->request->get('merchant_account_id'));
        $this->assignFormLookups($scopeMid);
        $this->assign('vo', [
            'merchant_account_id' => $preAccount ?: null,
            'status'              => 'INACTIVE',
            'event_types'         => '["payment.paid","payment.failed","payout.completed"]',
        ]);

        return $this->fetch('webhook_form');
    }

    public function webhook_edit()
    {
        if ($this->request->isPost()) {
            $id = $this->sanitizeId($this->request->post('id'));
            $row = $id ? $this->mojopayDb()->name('merchant_webhook_endpoint')->where('id', $id)->find() : null;
            if (empty($row)) {
                return $this->error('记录不存在');
            }

            try {
                $data = $this->buildWebhookFormData($this->request->post(), null, $id);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $this->mojopayDb()->name('merchant_webhook_endpoint')->where('id', $id)->update($data);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        $vo = $id ? $this->mojopayDb()->name('merchant_webhook_endpoint')->where('id', $id)->find() : null;
        if (empty($vo)) {
            return $this->error('记录不存在');
        }
        $vo['event_types'] = $this->scopesToDisplay($vo['event_types'] ?? '');
        $accountId         = (int)($vo['merchant_account_id'] ?? 0);
        $mid               = $accountId
            ? (int)$this->mojopayDb()->name('merchant_account')->where('id', $accountId)->value('merchant_id')
            : 0;
        try {
            $this->assertMerchantAccountInScope($accountId);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
        $this->assignFormLookups($this->merchantScopeId() ?: ($mid > 0 ? $mid : null));
        $this->assign('vo', $vo);
        $this->assign('is_edit', true);

        return $this->fetch('webhook_form');
    }

    public function webhook_remove()
    {
        $id = $this->sanitizeId($this->request->post('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $outbox = $this->mojopayDb()->name('merchant_event_outbox')->where('endpoint_id', $id)->find();
        if (!empty($outbox)) {
            return $this->error('存在回调通知投递记录，无法删除');
        }

        $deleted = $this->mojopayDb()->name('merchant_webhook_endpoint')->where('id', $id)->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败');
        }

        return $this->success('删除成功');
    }

    private function assignFormLookups(?int $merchantId = null): void
    {
        $scope = $merchantId ?? $this->merchantScopeId();
        $this->assignMerchantScopeContext($scope);
        $this->assign('account_options', $this->merchantAccountOptions($scope));
        $this->assign('env_options', array_combine(Enum::getProviderEnvironments(), Enum::getProviderEnvironments()));
        $this->assign('status_options', array_combine(Enum::getProviderStatuses(), Enum::getProviderStatuses()));
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildKeyFormData(array $post, ?int $lockMerchantId): array
    {
        $accountId = $this->sanitizeId($post['merchant_account_id'] ?? null);
        if (!$accountId) {
            throw new \InvalidArgumentException('请选择商户账号');
        }

        $account = $this->mojopayDb()->name('merchant_account')->where('id', $accountId)->find();
        if (empty($account)) {
            throw new \InvalidArgumentException('商户账号不存在');
        }
        if ($lockMerchantId && (int)$account['merchant_id'] !== $lockMerchantId) {
            throw new \InvalidArgumentException('账号不属于当前商户');
        }
        $this->assertMerchantAccountInScope($accountId);

        $keyId = trim((string)($post['key_id'] ?? ''));
        if ($keyId === '' || strlen($keyId) > 64 || !preg_match('/^[A-Za-z0-9._-]+$/', $keyId)) {
            throw new \InvalidArgumentException('key_id 必填，64 字符内，仅允许字母数字 . _ -');
        }

        $secretRef = trim((string)($post['secret_ref'] ?? ''));
        if ($secretRef === '') {
            throw new \InvalidArgumentException('secret_ref 必填（密钥存储引用，如 vault://...）');
        }

        $environment = $this->sanitizeEnum(strtoupper((string)($post['environment'] ?? '')), Enum::getProviderEnvironments());
        if ($environment === null) {
            throw new \InvalidArgumentException('environment 无效');
        }

        $status = $this->sanitizeEnum(strtoupper((string)($post['status'] ?? '')), Enum::getProviderStatuses());
        if ($status === null) {
            throw new \InvalidArgumentException('status 无效');
        }

        $rpm = (int)($post['requests_per_minute'] ?? 0);
        if ($rpm <= 0) {
            throw new \InvalidArgumentException('requests_per_minute 须大于 0');
        }

        $scopes = $this->parseScopesJson((string)($post['scopes'] ?? ''));

        $sigVer = trim((string)($post['signature_version'] ?? 'v1'));
        if ($sigVer === '' || strlen($sigVer) > 12) {
            $sigVer = 'v1';
        }

        $data = [
            'merchant_account_id' => $accountId,
            'key_id'              => $keyId,
            'secret_ref'          => substr($secretRef, 0, 255),
            'scopes'              => json_encode($scopes, JSON_UNESCAPED_UNICODE),
            'environment'         => $environment,
            'signature_version'   => $sigVer,
            'requests_per_minute' => $rpm,
            'status'              => $status,
        ];

        $expires = trim((string)($post['expires_at'] ?? ''));
        $data['expires_at'] = $expires === '' ? null : $expires;

        return $data;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildWebhookFormData(array $post, ?int $lockMerchantId, ?int $selfId = null): array
    {
        $accountId = $this->sanitizeId($post['merchant_account_id'] ?? null);
        if (!$accountId) {
            throw new \InvalidArgumentException('请选择商户账号');
        }

        $account = $this->mojopayDb()->name('merchant_account')->where('id', $accountId)->find();
        if (empty($account)) {
            throw new \InvalidArgumentException('商户账号不存在');
        }
        if ($lockMerchantId && (int)$account['merchant_id'] !== $lockMerchantId) {
            throw new \InvalidArgumentException('账号不属于当前商户');
        }
        $this->assertMerchantAccountInScope($accountId);

        $url = trim((string)($post['endpoint_url'] ?? ''));
        if ($url === '' || strlen($url) > 512) {
            throw new \InvalidArgumentException('endpoint_url 必填且不超过 512 字符');
        }
        if (!preg_match('#^https://#i', $url)) {
            throw new \InvalidArgumentException('endpoint_url 须以 https:// 开头');
        }

        $secretRef = trim((string)($post['secret_ref'] ?? ''));
        if ($secretRef === '') {
            throw new \InvalidArgumentException('secret_ref 必填');
        }

        $status = $this->sanitizeEnum(strtoupper((string)($post['status'] ?? '')), Enum::getProviderStatuses());
        if ($status === null) {
            throw new \InvalidArgumentException('status 无效');
        }

        $events = $this->parseScopesJson((string)($post['event_types'] ?? ''));

        return [
            'merchant_account_id' => $accountId,
            'endpoint_url'        => $url,
            'secret_ref'          => substr($secretRef, 0, 255),
            'event_types'         => json_encode($events, JSON_UNESCAPED_UNICODE),
            'status'              => $status,
        ];
    }

    /** @return list<string> */
    private function parseScopesJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new \InvalidArgumentException('scopes / event_types 须为 JSON 数组');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            throw new \InvalidArgumentException('须为非空 JSON 数组');
        }
        $out = [];
        foreach ($decoded as $item) {
            if (!is_string($item) || $item === '') {
                throw new \InvalidArgumentException('数组元素须为非空字符串');
            }
            $out[] = $item;
        }

        return $out;
    }

    private function scopesToDisplay(mixed $scopes): string
    {
        if (is_string($scopes)) {
            return $scopes;
        }
        if (is_array($scopes)) {
            return json_encode($scopes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return '[]';
    }

    /** @param array<string, mixed> $get */
    private function filteredKeyQuery(array $get): Query
    {
        $query = $this->mojopayDb()->name('merchant_api_key')->alias('k')
            ->join('merchant_account a', 'a.id = k.merchant_account_id')
            ->join('merchant m', 'm.id = a.merchant_id')
            ->field('k.*,a.code as account_code,m.legal_name as merchant_name');

        $mid = $this->sanitizeId($get['merchant_id'] ?? $this->request->get('merchant_id'));
        if ($mid) {
            $query->where('a.merchant_id', $mid);
        }

        if (!empty($get['merchant_account_id'])) {
            $aid = $this->sanitizeId($get['merchant_account_id']);
            if ($aid) {
                $query->where('k.merchant_account_id', $aid);
            }
        }

        return $query;
    }

    /** @param array<string, mixed> $get */
    private function fetchWebhooks(?int $merchantId, array $get): array
    {
        $query = $this->mojopayDb()->name('merchant_webhook_endpoint')->alias('w')
            ->join('merchant_account a', 'a.id = w.merchant_account_id')
            ->join('merchant m', 'm.id = a.merchant_id')
            ->field('w.*,a.code as account_code,m.legal_name as merchant_name');

        if ($merchantId) {
            $query->where('a.merchant_id', $merchantId);
        }
        if (!empty($get['merchant_account_id'])) {
            $aid = $this->sanitizeId($get['merchant_account_id']);
            if ($aid) {
                $query->where('w.merchant_account_id', $aid);
            }
        }

        $rows = $query->order('w.id', 'desc')->limit(50)->select()->toArray();
        foreach ($rows as &$row) {
            $row['created_at'] = $this->formatDatetime($row['created_at'] ?? null);
        }
        unset($row);

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function formatKeyRow(array $row): array
    {
        $row['created_at'] = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at'] = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}