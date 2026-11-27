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
 * 商户回调通知投递队列 merchant_event_outbox。
 */
class MerchantEventOutbox extends Controller
{
    use MojopayListTrait;
    use LookupTrait;
    use EmbedViewTrait;

    public function index()
    {
        $this->title = '回调通知';
        $get         = $this->request->get();
        $result      = $this->paginateMojopay(
            $this->filteredQuery($get)->order('o.id', 'desc'),
            $get,
            'merchant_event_outbox/index',
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

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->mojopayDb()->name('merchant_event_outbox')->alias('o')
            ->join('merchant_account a', 'a.id = o.merchant_account_id')
            ->join('merchant m', 'm.id = a.merchant_id')
            ->leftJoin('merchant_webhook_endpoint w', 'w.id = o.endpoint_id')
            ->field('o.*,a.code as account_code,m.legal_name as merchant_name,w.endpoint_url');

        $query->whereNotLike('o.event_type', 'balance.%');

        $mid = $this->sanitizeId($get['merchant_id'] ?? $this->request->get('merchant_id'));
        if ($mid) {
            $query->where('a.merchant_id', $mid);
        }

        if (!empty($get['merchant_account_id'])) {
            $aid = $this->sanitizeId($get['merchant_account_id']);
            if ($aid) {
                $query->where('o.merchant_account_id', $aid);
            }
        }

        if (!empty($get['status'])) {
            $st = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string)$get['status']));
            if ($st !== '') {
                $query->where('o.status', $st);
            }
        }

        $this->applyOptionalLike($query, $get, 'event_type', 'o.event_type', 48);

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['created_at']       = $this->formatDatetime($row['created_at'] ?? null);
        $row['next_attempt_at']  = $this->formatDatetime($row['next_attempt_at'] ?? null);
        $row['status_badge_cls'] = Enum::providerInboxStatusBadgeClass((string)($row['status'] ?? ''));
        $row['status_text']      = Enum::providerInboxStatusLabel((string)($row['status'] ?? ''));
        $row['event_type_text']  = Enum::providerInboxEventTypeLabel((string)($row['event_type'] ?? ''));

        return $row;
    }
}