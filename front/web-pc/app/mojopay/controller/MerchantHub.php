<?php

declare (strict_types=1);

namespace app\mojopay\controller;

use app\mojopay\trait\LookupTrait;
use app\mojopay\trait\MojopayListTrait;
use think\admin\Controller;

/**
 * 单个下游商户工作台（Tab 聚合）。
 */
class MerchantHub extends Controller
{
    use MojopayListTrait;
    use LookupTrait;

    public function index()
    {
        $merchantId = $this->sanitizeId($this->request->get('merchant_id'));
        if (!$merchantId) {
            return $this->error('缺少 merchant_id');
        }

        $merchant = $this->mojopayDb()->name('merchant')->where('id', $merchantId)->find();
        if (empty($merchant)) {
            return $this->error('商户不存在');
        }

        $db      = $this->mojopayDb();
        $acctIds = $this->merchantAccountIdsForMerchant($merchantId);
        $stats   = [
            'accounts'  => count($acctIds),
            'bindings'  => $acctIds
                ? (int)$db->name('channel_binding')->whereIn('merchant_account_id', $acctIds)->count()
                : 0,
            'outbox_pending' => $acctIds
                ? (int)$db->name('merchant_event_outbox')->whereIn('merchant_account_id', $acctIds)->where('status', 'PENDING')->count()
                : 0,
            'order_count' => (int)$db->name('payment_order')->where('merchant_id', $merchantId)->count(),
        ];

        $this->title = (string)$merchant['legal_name'];
        $baseQuery   = 'merchant_id=' . $merchantId . '&embed=1';

        $this->assign([
            'merchant'   => $merchant,
            'stats'      => $stats,
            'base_query' => $baseQuery,
            'tab_urls'   => [
                'accounts'   => url('merchant_account/index') . '?' . $baseQuery,
                'openapi'    => url('merchant_open_api/index') . '?' . $baseQuery,
                'bindings'   => url('merchant_channel_binding/index') . '?' . $baseQuery,
                'outbox'     => url('merchant_event_outbox/index') . '?' . $baseQuery,
                'orders'     => url('merchant_payment_order/index') . '?' . $baseQuery,
                'edit'       => url('merchant/edit') . '?id=' . $merchantId,
            ],
        ]);

        return $this->fetch();
    }
}