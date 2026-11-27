<?php

declare (strict_types=1);

namespace app\mojopay\controller;

use app\mojopay\const\Enum;
use app\mojopay\trait\EmbedViewTrait;
use app\mojopay\trait\LookupTrait;
use app\mojopay\trait\MojopayListTrait;
use think\admin\Controller;
use think\facade\Db;
use think\Paginator;

/**
 * 本上游商家相关的收款尝试与退款单（运营查看）。
 */
class ProviderOrder extends Controller
{
    use MojopayListTrait;
    use LookupTrait;
    use EmbedViewTrait;

    public function index()
    {
        $this->title = '支付订单';
        $get         = $this->request->get();
        $page        = isset($get['page']) ? max(1, (int)$get['page']) : 1;
        $pageSize    = isset($get['pageSize']) ? min(100, max(10, (int)$get['pageSize'])) : 30;

        $total = $this->countUnion($get);
        $list  = $this->fetchUnionPage($get, $page, $pageSize);
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $result = Paginator::make($list, $pageSize, $page, $total, false, [
            'path'  => $this->embedPaginatorPath($get),
            'query' => $this->embedPaginatorQuery($get),
        ]);

        $this->assign([
            'list'      => $result->items(),
            'pagehtml'  => $result->render(),
            'get'       => $get,
            'kinds'     => ['' => '全部', 'PAYMENT' => '收款', 'REFUND' => '退款'],
            'accounts'  => $this->providerAccountOptions($this->sanitizeId($get['provider_id'] ?? $this->request->get('provider_id'))),
        ]);

        return $this->fetchPage('index');
    }

    /** @param array<string, mixed> $get */
    private function buildUnionSql(array $get, bool $forCount): string
    {
        $pid = $this->sanitizeId($get['provider_id'] ?? $this->request->get('provider_id'));
        if (!$pid) {
            throw new \InvalidArgumentException('缺少 provider_id');
        }

        $kind = strtoupper(trim((string)($get['order_kind'] ?? '')));
        $wPay = ["a.provider_id = {$pid}"];
        $wRef = ["a.provider_id = {$pid}"];

        if (!empty($get['provider_account_id'])) {
            $aid = $this->sanitizeId($get['provider_account_id']);
            if ($aid) {
                $wPay[] = "pa.provider_account_id = {$aid}";
                $wRef[] = "ro.provider_account_id = {$aid}";
            }
        }

        if (!empty($get['status'])) {
            $st = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string)$get['status']));
            if ($st !== '') {
                $wPay[] = "pa.status = " . $this->quote($st);
                $wRef[] = "ro.status = " . $this->quote($st);
            }
        }

        if (!empty($get['biz_no'])) {
            $like = '%' . $this->sanitizeLike((string)$get['biz_no'], 128) . '%';
            $wPay[] = 'po.merchant_order_no LIKE ' . $this->quote($like);
            $wRef[] = 'ro.merchant_refund_no LIKE ' . $this->quote($like);
        }

        if (!empty($get['provider_biz_no'])) {
            $like = '%' . $this->sanitizeLike((string)$get['provider_biz_no'], 128) . '%';
            $wPay[] = '(pa.provider_order_id LIKE ' . $this->quote($like) . ' OR pa.provider_request_no LIKE ' . $this->quote($like) . ')';
            $wRef[] = '(ro.provider_refund_id LIKE ' . $this->quote($like) . ' OR ro.provider_request_no LIKE ' . $this->quote($like) . ')';
        }

        $datePay = 'pa.created_at';
        $dateRef = 'ro.created_at';
        $this->appendDateRange($get, $wPay, $datePay);
        $this->appendDateRange($get, $wRef, $dateRef);

        $paySql = 'SELECT ' . $this->selectPaymentColumns() . '
            FROM payment_attempt pa
            INNER JOIN provider_account a ON a.id = pa.provider_account_id
            INNER JOIN payment_order po ON po.id = pa.payment_order_id AND po.merchant_account_id = pa.merchant_account_id
            INNER JOIN merchant m ON m.id = po.merchant_id
            LEFT JOIN asset ast ON ast.id = pa.payment_asset_id
            WHERE ' . implode(' AND ', $wPay);

        $refSql = 'SELECT ' . $this->selectRefundColumns() . '
            FROM refund_order ro
            INNER JOIN provider_account a ON a.id = ro.provider_account_id
            INNER JOIN merchant_account ma ON ma.id = ro.merchant_account_id
            INNER JOIN merchant m ON m.id = ma.merchant_id
            LEFT JOIN asset ast ON ast.id = ro.asset_id
            WHERE ' . implode(' AND ', $wRef);

        if ($kind === 'PAYMENT') {
            return $forCount ? "SELECT COUNT(*) AS c FROM ({$paySql}) t" : $paySql;
        }
        if ($kind === 'REFUND') {
            return $forCount ? "SELECT COUNT(*) AS c FROM ({$refSql}) t" : $refSql;
        }

        $union = "({$paySql}) UNION ALL ({$refSql})";

        return $forCount ? "SELECT COUNT(*) AS c FROM ({$union}) t" : $union;
    }

    private function selectPaymentColumns(): string
    {
        return "'PAYMENT' AS order_kind, pa.id AS row_id, po.merchant_order_no AS biz_no,
            pa.provider_order_id AS provider_biz_no, pa.provider_request_no AS provider_request_no,
            pa.expected_amount AS amount, ast.code AS asset_code, pa.status AS status,
            a.external_id AS account_external_id, a.environment AS environment, m.legal_name AS merchant_name,
            pa.created_at AS created_at";
    }

    private function selectRefundColumns(): string
    {
        return "'REFUND' AS order_kind, ro.id AS row_id, ro.merchant_refund_no AS biz_no,
            ro.provider_refund_id AS provider_biz_no, ro.provider_request_no AS provider_request_no,
            ro.amount AS amount, ast.code AS asset_code, ro.status AS status,
            a.external_id AS account_external_id, a.environment AS environment, m.legal_name AS merchant_name,
            ro.created_at AS created_at";
    }

    /** @param array<string, mixed> $get */
    private function appendDateRange(array $get, array &$where, string $column): void
    {
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start, $end] = $dates;
            $where[]      = "{$column} BETWEEN " . $this->quote("{$start} 00:00:00") . ' AND ' . $this->quote("{$end} 23:59:59");

            return;
        }
        $where[] = "{$column} >= " . $this->quote(date('Y-m-d H:i:s', strtotime('-90 days')));
    }

    /** @param array<string, mixed> $get */
    private function countUnion(array $get): int
    {
        try {
            $sql = $this->buildUnionSql($get, true);
        } catch (\InvalidArgumentException) {
            return 0;
        }
        $row = Db::connect('db_mojopay')->query($sql);

        return (int)($row[0]['c'] ?? 0);
    }

    /**
     * @param array<string, mixed> $get
     * @return list<array<string, mixed>>
     */
    private function fetchUnionPage(array $get, int $page, int $pageSize): array
    {
        try {
            $sql = $this->buildUnionSql($get, false);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $offset = ($page - 1) * $pageSize;
        $sql    .= " ORDER BY created_at DESC LIMIT {$offset}, {$pageSize}";

        return Db::connect('db_mojopay')->query($sql) ?: [];
    }

    /** @param array<string, mixed> $get */
    private function embedPaginatorPath(array $get): string
    {
        $embed = (string)($get['embed'] ?? $this->request->get('embed', '')) === '1';

        return $embed
            ? preg_replace('/\?.*$/', '', (string)$this->request->url())
            : '/admin.html#/mojopay/provider_order/index.html';
    }

    /** @param array<string, mixed> $get */
    private function embedPaginatorQuery(array $get): array
    {
        if ((string)($get['embed'] ?? '') === '1') {
            $get['embed'] = '1';
            $lockPid      = $this->sanitizeId($get['provider_id'] ?? $this->request->get('provider_id'));
            if ($lockPid) {
                $get['provider_id'] = $lockPid;
            }
        }

        return $get;
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['created_at']    = $this->formatDatetime($row['created_at'] ?? null);
        $row['kind_label']    = ($row['order_kind'] ?? '') === 'REFUND' ? '退款' : '收款';
        $row['status_badge_cls'] = Enum::providerInboxStatusBadgeClass((string)($row['status'] ?? ''));

        return $row;
    }
}