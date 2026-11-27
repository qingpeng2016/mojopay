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
 * 下游 merchant_event_inbox 列表（余额流水，结构与上游 inbox 对齐）。
 */
abstract class MerchantInboxController extends Controller
{
    use MojopayListTrait;
    use LookupTrait;
    use EmbedViewTrait;

    abstract protected function viewSlug(): string;

    public function index()
    {
        $this->title = '余额流水';
        $get         = $this->request->get();
        $result      = $this->paginateMojopay(
            $this->filteredQuery($get)->order('i.id', 'desc'),
            $get,
            $this->viewSlug() . '/index',
            30
        );
        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $lockMid    = $this->sanitizeId($get['merchant_id'] ?? $this->request->get('merchant_id'));
        $eventTypes = ['' => '全部'] + $this->eventTypeOptions();
        $this->assign([
            'list'        => $list,
            'pagehtml'    => $result->render(),
            'get'         => $get,
            'accounts'    => $this->merchantAccountOptions($lockMid),
            'event_types' => $eventTypes,
        ]);

        return $this->fetchPage('index');
    }

    /** @return array<string, string> */
    protected function eventTypeOptions(): array
    {
        $out = [];
        foreach (Enum::getProviderInboxEventTypeLabels() as $code => $label) {
            if (!Enum::isProviderInboxBalanceEventType($code)) {
                continue;
            }
            $out[$code] = $label;
        }

        return $out;
    }

    /** @param array<string, mixed> $get */
    protected function filteredQuery(array $get): Query
    {
        $query = $this->mojopayDb()->name('merchant_event_inbox')->alias('i')
            ->leftJoin('merchant_account a', 'a.id = i.merchant_account_id')
            ->leftJoin('merchant m', 'm.id = a.merchant_id')
            ->field('i.*,a.code as account_code,a.role as account_role,m.legal_name as merchant_name');

        $query->whereLike('i.event_type', 'balance.%');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('i.id', $id);
            }
        }

        $mid = $this->sanitizeId($get['merchant_id'] ?? $this->request->get('merchant_id'));
        if ($mid) {
            $query->where('a.merchant_id', $mid);
        }

        if (!empty($get['merchant_account_id'])) {
            $aid = $this->sanitizeId($get['merchant_account_id']);
            if ($aid) {
                $query->where('i.merchant_account_id', $aid);
            }
        }

        if (!empty($get['event_type'])) {
            $et = strtolower(trim((string)$get['event_type']));
            if ($et !== '' && strlen($et) <= 48 && preg_match('/^[a-z0-9._-]+$/', $et)) {
                $query->where('i.event_type', $et);
            }
        }
        if (!empty($get['date_range'])) {
            $this->applyDateRangeFilter($query, $get, 'i.received_at', true);
        }

        return $query;
    }

    /** @param array<string, mixed> $row */
    protected function formatRow(array $row): array
    {
        $row['received_at'] = $this->formatDatetime($row['received_at'] ?? null);
        $row['updated_at']    = $this->formatDatetime($row['updated_at'] ?? null);
        $payload              = (string)($row['payload'] ?? '');
        if (strlen($payload) > 120) {
            $row['payload_preview'] = substr($payload, 0, 120) . '…';
        } else {
            $row['payload_preview'] = $payload === '' ? '-' : $payload;
        }
        $st                      = (string)($row['status'] ?? '');
        $row['status_badge_cls'] = Enum::providerInboxStatusBadgeClass($st);
        $row['status_text']      = Enum::providerInboxStatusLabel($st);
        $row['event_type_text']  = Enum::providerInboxEventTypeLabel((string)($row['event_type'] ?? ''));

        return $row;
    }
}