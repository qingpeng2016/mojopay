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
 * 商户支付订单 payment_order。
 */
class MerchantPaymentOrder extends Controller
{
    use MojopayListTrait;
    use LookupTrait;
    use EmbedViewTrait;

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

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->mojopayDb()->name('payment_order')->alias('p')
            ->join('merchant_account a', 'a.id = p.merchant_account_id')
            ->join('merchant m', 'm.id = p.merchant_id')
            ->field('p.*,a.code as account_code,m.legal_name as merchant_name');

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
        $row['created_at']       = $this->formatDatetime($row['created_at'] ?? null);
        $row['expires_at']       = $this->formatDatetime($row['expires_at'] ?? null);
        $row['status_badge_cls'] = Enum::providerInboxStatusBadgeClass((string)($row['status'] ?? ''));

        return $row;
    }
}