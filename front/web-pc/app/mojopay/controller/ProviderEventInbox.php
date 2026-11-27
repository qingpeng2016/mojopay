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
 * 上游余额流水（回调收件箱与处理状态）。
 */
class ProviderEventInbox extends Controller
{
    use MojopayListTrait;
    use LookupTrait;
    use EmbedViewTrait;

    public function index()
    {
        $this->title = '余额流水';
        $get         = $this->request->get();
        $result      = $this->paginateMojopay(
            $this->filteredQuery($get)->order('i.id', 'desc'),
            $get,
            'provider_event_inbox/index',
            30
        );
        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $lockPid = $this->sanitizeId($get['provider_id'] ?? $this->request->get('provider_id'));
        $eventTypes = ['' => '全部'] + Enum::getProviderInboxEventTypeLabels();
        $this->assign([
            'list'        => $list,
            'pagehtml'    => $result->render(),
            'get'         => $get,
            'accounts'    => $this->providerAccountOptions($lockPid),
            'event_types' => $eventTypes,
        ]);

        return $this->fetchPage('index');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->mojopayDb()->name('provider_event_inbox')->alias('i')
            ->leftJoin('provider_account a', 'a.id = i.provider_account_id')
            ->leftJoin('provider p', 'p.id = a.provider_id')
            ->field('i.*,a.external_id as account_external_id,a.environment,p.name as provider_name');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('i.id', $id);
            }
        }

        $pid = $this->sanitizeId($get['provider_id'] ?? $this->request->get('provider_id'));
        if ($pid) {
            $query->where('a.provider_id', $pid);
        }

        if (!empty($get['provider_account_id'])) {
            $aid = $this->sanitizeId($get['provider_account_id']);
            if ($aid) {
                $query->where('i.provider_account_id', $aid);
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
    private function formatRow(array $row): array
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