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
 * 上游凭据与回调（按账号维护 API 密钥、回调地址、出款 IP 等）。
 */
class ProviderCredential extends Controller
{
    use MojopayListTrait;
    use LookupTrait;
    use EmbedViewTrait;

    public function index()
    {
        $this->title = '凭据与回调';
        $get         = $this->request->get();
        $result      = $this->paginateMojopay(
            $this->filteredQuery($get)->order('a.id', 'desc'),
            $get,
            'provider_credential/index',
            30
        );
        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'         => $list,
            'pagehtml'     => $result->render(),
            'get'          => $get,
            'providers'    => $this->providerOptions(),
            'environments' => array_combine(Enum::getProviderEnvironments(), Enum::getProviderEnvironments()),
        ]);

        return $this->fetchPage('index');
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
                $update = $this->buildSavePayload($this->request->post(), $existing);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $this->mojopayDb()->name('provider_account')->where('id', $id)->update($update);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        $vo = $id ? $this->prepareFormVo($this->loadRow($id)) : null;
        if ($vo === null) {
            return $this->error('记录不存在');
        }
        $this->assign('vo', $vo);

        return $this->fetch('form');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->mojopayDb()->name('provider_account')->alias('a')
            ->leftJoin('provider p', 'p.id = a.provider_id')
            ->field('a.id,a.provider_id,a.environment,a.external_id,a.role,a.credential_ref,a.credential_profile,a.status,a.updated_at,p.code as provider_code,p.name as provider_name');

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

        return $query;
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->mojopayDb()->name('provider_account')->alias('a')
            ->leftJoin('provider p', 'p.id = a.provider_id')
            ->field('a.*,p.code as provider_code,p.name as provider_name')
            ->where('a.id', $id)
            ->find();

        return empty($row) ? null : $row;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private function prepareFormVo(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $profile = $this->decodeProfile($row['credential_profile'] ?? null);
        $row['api_key']             = (string)($profile['api_key'] ?? '');
        $row['webhook_url']         = (string)($profile['webhook_url'] ?? '');
        $row['payout_ip_allowlist'] = (string)($profile['payout_ip_allowlist'] ?? '');
        $row['credential_remark']   = (string)($profile['remark'] ?? '');
        $secret                     = (string)($profile['api_secret'] ?? '');
        $row['api_secret']          = '';
        $row['api_secret_masked']   = $this->maskSecret($secret);

        return $row;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function buildSavePayload(array $post, array $existing): array
    {
        $profile = $this->decodeProfile($existing['credential_profile'] ?? null);

        $credentialRef = trim((string)($post['credential_ref'] ?? ''));
        if ($credentialRef === '') {
            $credentialRef = null;
        } elseif (strlen($credentialRef) > 255) {
            throw new \InvalidArgumentException('密钥库路径不超过 255 字符');
        }

        $apiKey = trim((string)($post['api_key'] ?? ''));
        if ($apiKey !== '' && strlen($apiKey) > 256) {
            throw new \InvalidArgumentException('API Key 过长');
        }

        $webhook = trim((string)($post['webhook_url'] ?? ''));
        $payoutIps = $this->normalizeIpAllowlist((string)($post['payout_ip_allowlist'] ?? ''));
        $remark    = trim((string)($post['credential_remark'] ?? ''));
        if (strlen($remark) > 255) {
            throw new \InvalidArgumentException('备注不超过 255 字符');
        }

        if ($apiKey === '') {
            if ($credentialRef === null) {
                throw new \InvalidArgumentException('请填写 API Key，或在高级选项中填写密钥库路径');
            }

            return [
                'credential_ref' => $credentialRef,
            ];
        }

        $apiSecretIn = trim((string)($post['api_secret'] ?? ''));
        $prevSecret  = (string)($profile['api_secret'] ?? '');
        if ($apiSecretIn !== '') {
            if (strlen($apiSecretIn) > 512) {
                throw new \InvalidArgumentException('API Secret 过长');
            }
            $apiSecret = $apiSecretIn;
        } elseif ($prevSecret !== '') {
            $apiSecret = $prevSecret;
        } else {
            throw new \InvalidArgumentException('请填写 API Secret');
        }

        if ($webhook === '' || strlen($webhook) > 512) {
            throw new \InvalidArgumentException('请填写回调地址');
        }
        if (!preg_match('#^https://#i', $webhook)) {
            throw new \InvalidArgumentException('回调地址须以 https:// 开头');
        }

        $profile['api_key']             = $apiKey;
        $profile['api_secret']          = $apiSecret;
        $profile['webhook_url']         = $webhook;
        $profile['payout_ip_allowlist'] = $payoutIps;
        $profile['remark']              = $remark;

        return [
            'credential_ref'     => $credentialRef,
            'credential_profile' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    private function normalizeIpAllowlist(string $raw): string
    {
        $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
        if ($raw === '') {
            return '';
        }
        $parts = preg_split('/[\n,;]+/', $raw) ?: [];
        $out   = [];
        foreach ($parts as $part) {
            $ip = trim($part);
            if ($ip !== '') {
                $out[] = $ip;
            }
        }

        return implode("\n", array_unique($out));
    }

    /** @return array<string, mixed> */
    private function decodeProfile(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function maskSecret(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        $len = strlen($secret);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($secret, 0, 2) . str_repeat('*', max(4, $len - 4)) . substr($secret, -2);
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['updated_at'] = $this->formatDatetime($row['updated_at'] ?? null);
        $profile           = $this->decodeProfile($row['credential_profile'] ?? null);
        $row['webhook_url'] = (string)($profile['webhook_url'] ?? '');
        $row['has_api_key'] = !empty($profile['api_key']) ? '是' : '否';
        $row['has_credential'] = ($row['has_api_key'] === '是' || !empty($row['credential_ref'])) ? '是' : '否';

        return $row;
    }
}