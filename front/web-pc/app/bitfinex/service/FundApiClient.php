<?php

declare (strict_types=1);

namespace app\bitfinex\service;

/**
 * 3.6.3 理财引擎 fund API（申购 / 赎回 / 取消挂单）
 */
final class FundApiClient
{
    /**
     * @return array{ok:bool,message:string,http_code:int,response:?array,raw:string}
     */
    public function subscribe(int $clientId, string $productCode, string $amount): array
    {
        return $this->post('/fund/client/subscribe', [
            'client_id'    => $clientId,
            'product_code' => $productCode,
            'amount'       => $amount,
        ]);
    }

    /**
     * @return array{ok:bool,message:string,http_code:int,response:?array,raw:string}
     */
    public function redeem(int $subscriptionId, string $redemptionType, ?string $amount = null, ?string $shares = null): array
    {
        $payload = [
            'subscription_id' => $subscriptionId,
            'redemption_type' => $redemptionType,
        ];
        if ($amount !== null && $amount !== '') {
            $payload['amount'] = $amount;
        }
        if ($shares !== null && $shares !== '') {
            $payload['shares'] = $shares;
        }

        return $this->post('/fund/client/redeem', $payload);
    }

    /**
     * 取消子账号全部挂单
     *
     * @return array{ok:bool,message:string,http_code:int,response:?array,raw:string}
     */
    public function cancelAllOffers(int $subAccountId): array
    {
        return $this->post('/fund/sub-account/cancel-all-offers', [
            'sub_account_id' => $subAccountId,
        ]);
    }

    /**
     * 主/子账号资金划转
     *
     * @return array{ok:bool,message:string,http_code:int,response:?array,raw:string}
     */
    public function accountTransfer(
        string $fromAccountType,
        int $fromAccountId,
        string $toAccountType,
        int $toAccountId,
        string $fromWallet,
        string $toWallet,
        string $amount,
        string $currency
    ): array {
        return $this->post('/fund/sub-account/account-transfer', [
            'from_account_type' => $fromAccountType,
            'from_account_id'   => $fromAccountId,
            'to_account_type'   => $toAccountType,
            'to_account_id'     => $toAccountId,
            'from_wallet'       => $fromWallet,
            'to_wallet'         => $toWallet,
            'amount'            => $amount,
            'currency'          => $currency,
        ]);
    }

    /**
     * 主/子账号 USD ↔ USDT 兑换
     *
     * @return array{ok:bool,message:string,http_code:int,response:?array,raw:string}
     */
    public function exchange(string $accountType, int $accountId, string $direction, string $amount): array
    {
        return $this->post('/fund/sub-account/exchange', [
            'account_type' => $accountType,
            'account_id'   => $accountId,
            'direction'    => $direction,
            'amount'       => $amount,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok:bool,message:string,http_code:int,response:?array,raw:string}
     */
    private function post(string $path, array $payload): array
    {
        $config  = config('database.fund_api') ?? [];
        $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
        $authKey = (string)($config['auth_key'] ?? '');
        $timeout = max(5, (int)($config['timeout_seconds'] ?? 30));

        if ($baseUrl === '') {
            return [
                'ok'        => false,
                'message'   => 'fund_api.base_url 未配置',
                'http_code' => 0,
                'response'  => null,
                'raw'       => '',
            ];
        }
        if ($authKey === '') {
            return [
                'ok'        => false,
                'message'   => 'fund_api.auth_key 未配置',
                'http_code' => 0,
                'response'  => null,
                'raw'       => '',
            ];
        }

        $url  = $baseUrl . $path;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return [
                'ok'        => false,
                'message'   => '请求体 JSON 编码失败',
                'http_code' => 0,
                'response'  => null,
                'raw'       => '',
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'auth_key: ' . $authKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok'        => false,
                'message'   => '请求失败：' . ($curlErr !== '' ? $curlErr : 'unknown'),
                'http_code' => $httpCode,
                'response'  => null,
                'raw'       => '',
            ];
        }

        $decoded = json_decode((string)$raw, true);
        $decoded = is_array($decoded) ? $decoded : null;
        $ok      = $this->isSuccess($httpCode, $decoded);
        $message = $ok
            ? $this->extractMessage($decoded, '操作成功')
            : $this->extractMessage($decoded, "HTTP {$httpCode}");

        if (!$ok && $message === "HTTP {$httpCode}" && $raw !== '') {
            $message = mb_substr((string)$raw, 0, 300);
        }

        return [
            'ok'        => $ok,
            'message'   => $message,
            'http_code' => $httpCode,
            'response'  => $decoded,
            'raw'       => (string)$raw,
        ];
    }

    /**
     * @param array<string, mixed>|null $decoded
     */
    private function isSuccess(int $httpCode, ?array $decoded): bool
    {
        if ($httpCode < 200 || $httpCode >= 300) {
            return false;
        }
        if ($decoded === null) {
            return true;
        }
        if (array_key_exists('success', $decoded)) {
            return (bool)$decoded['success'];
        }
        if (array_key_exists('code', $decoded)) {
            $code = $decoded['code'];
            if (is_numeric($code)) {
                return in_array((int)$code, [0, 1, 200], true);
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed>|null $decoded
     */
    private function extractMessage(?array $decoded, string $fallback): string
    {
        if ($decoded === null) {
            return $fallback;
        }
        foreach (['msg', 'message', 'error', 'detail'] as $key) {
            if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                return $decoded[$key];
            }
        }
        return $fallback;
    }
}
