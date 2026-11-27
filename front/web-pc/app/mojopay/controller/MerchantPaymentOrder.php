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
 * 商户支付订单 payment_order。
 */
class MerchantPaymentOrder extends Controller
{
    use MojopayListTrait;
    use LookupTrait;
    use EmbedViewTrait;
    use MerchantScopeTrait;

    public function index()
    {
        $this->title = '支付订单';
        $get         = $this->request->get();
        $result      = $this->paginateMojopay(
            $this->filteredQuery($get)->order('p.id', 'desc'),
            $get,
            'merchant_payment_order/index',
            30
        );
        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $lockMid = $this->sanitizeId($get['merchant_id'] ?? $this->request->get('merchant_id'));
        $this->assign([
            'list'     => $list,
            'pagehtml' => $result->render(),
            'get'      => $get,
            'accounts' => $this->merchantAccountOptions($lockMid),
        ]);

        return $this->fetchPage('index');
    }

    public function add()
    {
        $scopeMid = $this->merchantScopeId();

        if ($this->request->isPost()) {
            try {
                $this->createOrder($this->request->post());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            } catch (\Throwable $e) {
                return $this->error('创建失败：' . $e->getMessage());
            }

            return $this->success('订单已创建');
        }

        $preAccount = $this->sanitizeId($this->request->get('merchant_account_id'));
        $this->assignMerchantScopeContext($scopeMid);
        $this->assign('account_options', $this->merchantAccountOptions($scopeMid));
        $this->assign('asset_options', $this->assetOptions());
        $this->assign('binding_options', $preAccount ? $this->channelBindingOptions($preAccount) : []);
        $this->assign('vo', [
            'merchant_account_id' => $preAccount,
            'order_asset_id'      => $this->defaultAssetId(),
            'expires_at'          => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);

        return $this->fetch('form');
    }

    public function bindingOptions()
    {
        $accountId = $this->sanitizeId($this->request->get('merchant_account_id'));
        if (!$accountId) {
            return $this->error('缺少 merchant_account_id');
        }
        try {
            $this->assertMerchantAccountInScope($accountId);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('ok', $this->channelBindingOptions($accountId));
    }

    /** @param array<string, mixed> $post */
    private function createOrder(array $post): void
    {
        $accountId = $this->sanitizeId($post['merchant_account_id'] ?? null);
        if (!$accountId) {
            throw new \InvalidArgumentException('请选择商户账号');
        }
        $this->assertMerchantAccountInScope($accountId);

        $account = $this->mojopayDb()->name('merchant_account')->where('id', $accountId)->find();
        if (empty($account)) {
            throw new \InvalidArgumentException('商户账号不存在');
        }
        $merchantId = (int)$account['merchant_id'];

        $bindingId = $this->sanitizeId($post['channel_binding_id'] ?? null);
        if (!$bindingId) {
            throw new \InvalidArgumentException('请选择通道路由');
        }

        $binding = $this->mojopayDb()->name('channel_binding')->alias('b')
            ->join('channel_capability c', 'c.id = b.capability_id')
            ->where('b.id', $bindingId)
            ->where('b.merchant_account_id', $accountId)
            ->where('b.status', 'ACTIVE')
            ->field('b.id,b.merchant_account_id,b.capability_id,c.provider_account_id,c.asset_id as cap_asset_id')
            ->find();
        if (empty($binding)) {
            throw new \InvalidArgumentException('通道路由无效或未启用');
        }

        $assetId = $this->sanitizeId($post['order_asset_id'] ?? null);
        if (!$assetId) {
            throw new \InvalidArgumentException('请选择订单币种');
        }
        $asset = $this->mojopayDb()->name('asset')->where('id', $assetId)->find();
        if (empty($asset)) {
            throw new \InvalidArgumentException('资产不存在');
        }

        $amountMinor = $this->parseAmountToMinor((string)($post['order_amount'] ?? ''), (int)$asset['decimals']);

        $mono = trim((string)($post['merchant_order_no'] ?? ''));
        if ($mono === '') {
            $mono = 'ADM_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        }
        if (strlen($mono) > 128) {
            throw new \InvalidArgumentException('商户单号过长');
        }

        $dup = $this->mojopayDb()->name('payment_order')
            ->where('merchant_account_id', $accountId)
            ->where('merchant_order_no', $mono)
            ->find();
        if (!empty($dup)) {
            throw new \InvalidArgumentException('商户单号已存在');
        }

        $expiresRaw = trim((string)($post['expires_at'] ?? ''));
        if ($expiresRaw === '') {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        } else {
            $ts = strtotime($expiresRaw);
            if ($ts === false) {
                throw new \InvalidArgumentException('过期时间格式无效');
            }
            $expiresAt = date('Y-m-d H:i:s', $ts);
        }

        $publicId    = 'pay_' . bin2hex(random_bytes(16));
        $paymentUrl  = sprintf(
            'https://checkout.mojopay.demo/pay/%s?token=%s',
            $publicId,
            bin2hex(random_bytes(16))
        );

        $db = $this->mojopayDb();
        $db->startTrans();
        try {
            $orderData = [
                'public_id'                     => $publicId,
                'merchant_account_id'           => $accountId,
                'merchant_id'                   => $merchantId,
                'merchant_user_id'              => null,
                'order_asset_id'                => $assetId,
                'requested_payment_asset_id'    => $assetId,
                'settlement_asset_id'           => $assetId,
                'quote_id'                      => null,
                'merchant_order_no'             => $mono,
                'order_amount'                  => $amountMinor,
                'status'                        => 'CREATED',
                'origin'                        => 'MANUAL',
                'metadata'                      => json_encode(['channel_binding_id' => $bindingId], JSON_UNESCAPED_UNICODE),
                'expires_at'                    => $expiresAt,
                'payment_url'                   => $paymentUrl,
            ];
            $orderId = (int)$db->name('payment_order')->insertGetId($orderData);

            $reqNo = 'adm_req_' . $orderId . '_' . bin2hex(random_bytes(4));
            $snapshot = json_encode([
                'channel_binding_id' => $bindingId,
                'capability_id'      => (int)$binding['capability_id'],
                'source'             => 'admin_manual',
            ], JSON_UNESCAPED_UNICODE);

            $db->name('payment_attempt')->insert([
                'payment_order_id'     => $orderId,
                'merchant_account_id'  => $accountId,
                'capability_id'        => (int)$binding['capability_id'],
                'channel_binding_id'   => $bindingId,
                'provider_account_id'  => (int)$binding['provider_account_id'],
                'payment_asset_id'     => $assetId,
                'expected_amount'      => $amountMinor,
                'provider_request_no'  => $reqNo,
                'provider_order_id'    => null,
                'status'               => 'CREATED',
                'route_snapshot'       => $snapshot,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    private function parseAmountToMinor(string $raw, int $decimals): string
    {
        $raw = trim(str_replace(',', '', $raw));
        if ($raw === '' || !is_numeric($raw)) {
            throw new \InvalidArgumentException('请输入有效订单金额');
        }
        if ((float)$raw <= 0) {
            throw new \InvalidArgumentException('订单金额须大于 0');
        }

        $parts = explode('.', $raw, 2);
        $whole = ltrim($parts[0], '0');
        if ($whole === '') {
            $whole = '0';
        }
        $frac = $parts[1] ?? '';
        if (strlen($frac) > $decimals) {
            throw new \InvalidArgumentException('金额小数位超过该币种精度（' . $decimals . ' 位）');
        }
        $frac = str_pad($frac, $decimals, '0');

        if ($decimals === 0) {
            return $whole;
        }

        return ltrim($whole . $frac, '0') ?: '0';
    }

    private function defaultAssetId(): ?int
    {
        $id = $this->mojopayDb()->name('asset')->where('code', 'USD')->value('id');

        return $id ? (int)$id : null;
    }

    /** @return array<int, string> */
    protected function channelBindingOptions(int $merchantAccountId): array
    {
        $rows = $this->mojopayDb()->name('channel_binding')->alias('b')
            ->join('channel_capability c', 'c.id = b.capability_id')
            ->join('asset ast', 'ast.id = c.asset_id')
            ->where('b.merchant_account_id', $merchantAccountId)
            ->where('b.status', 'ACTIVE')
            ->field('b.id,c.operation,c.region,c.capability_key,ast.code as asset_code,b.version,b.priority')
            ->order('b.priority', 'asc')
            ->select()
            ->toArray();
        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[$id] = sprintf(
                '#%d %s %s %s v%s p%s',
                $id,
                (string)($row['operation'] ?? ''),
                (string)($row['asset_code'] ?? ''),
                (string)($row['region'] ?? ''),
                (string)($row['version'] ?? ''),
                (string)($row['priority'] ?? '')
            );
        }

        return $out;
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->mojopayDb()->name('payment_order')->alias('p')
            ->join('merchant_account a', 'a.id = p.merchant_account_id')
            ->join('merchant m', 'm.id = p.merchant_id')
            ->leftJoin('asset oa', 'oa.id = p.order_asset_id')
            ->field('p.*,a.code as account_code,m.legal_name as merchant_name,oa.code as asset_code,
                (SELECT pa.channel_binding_id FROM payment_attempt pa WHERE pa.payment_order_id = p.id ORDER BY pa.id DESC LIMIT 1) AS channel_binding_id');

        $mid = $this->sanitizeId($get['merchant_id'] ?? $this->request->get('merchant_id'));
        if ($mid) {
            $query->where('p.merchant_id', $mid);
        }

        if (!empty($get['merchant_account_id'])) {
            $aid = $this->sanitizeId($get['merchant_account_id']);
            if ($aid) {
                $query->where('p.merchant_account_id', $aid);
            }
        }

        $this->applyOptionalLike($query, $get, 'merchant_order_no', 'p.merchant_order_no', 128);

        if (!empty($get['status'])) {
            $st = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string)$get['status']));
            if ($st !== '') {
                $query->where('p.status', $st);
            }
        }

        if (!empty($get['date_range'])) {
            $this->applyDateRangeFilter($query, $get, 'p.created_at', false);
        }

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['created_at']        = $this->formatDatetime($row['created_at'] ?? null);
        $row['expires_at']        = $this->formatDatetime($row['expires_at'] ?? null);
        $row['status_badge_cls']  = Enum::providerInboxStatusBadgeClass((string)($row['status'] ?? ''));
        $row['status_text']       = Enum::paymentOrderStatusLabel((string)($row['status'] ?? ''));
        $row['payment_url']       = (string)($row['payment_url'] ?? '');
        $row['binding_label']     = !empty($row['channel_binding_id']) ? ('#' . $row['channel_binding_id']) : '-';

        return $row;
    }
}