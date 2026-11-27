<?php

declare (strict_types=1);

namespace app\mojopay\controller;

use app\mojopay\trait\LookupTrait;
use app\mojopay\trait\MojopayListTrait;
use think\admin\Controller;

/**
 * 单个上游商家工作台（Tab 聚合）。
 */
class ProviderHub extends Controller
{
    use MojopayListTrait;
    use LookupTrait;

    public function index()
    {
        $providerId = $this->sanitizeId($this->request->get('provider_id'));
        if (!$providerId) {
            return $this->error('缺少 provider_id');
        }

        $provider = $this->mojopayDb()->name('provider')->where('id', $providerId)->find();
        if (empty($provider)) {
            return $this->error('上游商家不存在');
        }

        $db = $this->mojopayDb();
        $stats = [
            'accounts'     => (int)$db->name('provider_account')->where('provider_id', $providerId)->count(),
            'capabilities' => (int)$db->name('channel_capability')->alias('c')
                ->join('provider_account a', 'a.id = c.provider_account_id')
                ->where('a.provider_id', $providerId)
                ->count(),
            'callback_pending' => (int)$db->name('provider_event_inbox')->alias('i')
                ->join('provider_account a', 'a.id = i.provider_account_id')
                ->where('a.provider_id', $providerId)
                ->where('i.status', 'PENDING')
                ->whereNotLike('i.event_type', 'balance.%')
                ->count(),
            'inbox_pending' => (int)$db->name('provider_event_inbox')->alias('i')
                ->join('provider_account a', 'a.id = i.provider_account_id')
                ->where('a.provider_id', $providerId)
                ->where('i.status', 'PENDING')
                ->whereLike('i.event_type', 'balance.%')
                ->count(),
            'order_count'   => (int)$db->name('payment_attempt')->alias('pa')
                ->join('provider_account a', 'a.id = pa.provider_account_id')
                ->where('a.provider_id', $providerId)
                ->count()
                + (int)$db->name('refund_order')->alias('ro')
                ->join('provider_account a', 'a.id = ro.provider_account_id')
                ->where('a.provider_id', $providerId)
                ->count(),
        ];

        $this->title = (string)$provider['name'];
        $baseQuery   = 'provider_id=' . $providerId . '&embed=1';

        $this->assign([
            'provider'    => $provider,
            'stats'       => $stats,
            'base_query'  => $baseQuery,
            'tab_urls'    => [
                'accounts'     => url('provider_account/index') . '?' . $baseQuery,
                'credentials'  => url('provider_credential/index') . '?' . $baseQuery,
                'capabilities' => url('channel_capability/index') . '?' . $baseQuery,
                'callbacks'    => url('provider_callback_inbox/index') . '?' . $baseQuery,
                'inbox'        => url('provider_event_inbox/index') . '?' . $baseQuery,
                'orders'       => url('provider_order/index') . '?' . $baseQuery,
                'edit'         => url('provider/edit') . '?id=' . $providerId,
            ],
        ]);

        return $this->fetch();
    }
}