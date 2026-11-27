<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\const\Enum;
use app\bitfinex\trait\RecordViewTrait;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\db\Query;
use think\facade\Db;

/**
 * 借贷记录（funding_lending_position，只读）
 */
class LendingPosition extends Controller
{
    use SafeQueryTrait;
    use RecordViewTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '借贷记录';
        $get = $this->request->get();

        $pageQuery = $this->filteredQuery($get)
            ->orderRaw("FIELD(status, 'ACTIVE', 'CLOSED (EXPIRED)', 'CLOSED (REDUCED)', 'CLOSED') ASC")
            ->order('updated_at', 'desc')
            ->order('id', 'desc');
        $result = $pageQuery->paginate([
            'list_rows' => isset($get['pageSize'])
                ? min(100, max(10, intval($get['pageSize'])))
                : 20,
            'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/bitfinex/lending_position/index.html',
        ], false);

        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'       => $list,
            'pagehtml'   => $result->render(),
            'get'        => $get,
            'symbols'    => array_combine(Enum::getAllFundingSymbols(), Enum::getAllFundingSymbols()),
            'statuses'   => array_combine(Enum::getAllLendingPositionStatuses(), Enum::getAllLendingPositionStatuses()),
        ]);

        return $this->fetch();
    }

    /**
     * @auth true
     */
    public function detail()
    {
        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $vo = $this->loadRow($id);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $this->assign('vo', $vo);
        return $this->fetch();
    }

    /**
     * @param array<string, mixed> $get
     */
    private function filteredQuery(array $get): Query
    {
        /** @var Query $query */
        $query = Db::connect('db_bitfinex')->name('funding_lending_position');
        $this->applyDateRangeFilter($query, $get, 'updated_at');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }

        if (!empty($get['symbol'])) {
            $symbol = $this->sanitizeEnum($get['symbol'], Enum::getAllFundingSymbols());
            if ($symbol) {
                $query->where('symbol', $symbol);
            }
        }

        if (!empty($get['position_id'])) {
            $positionId = $this->sanitizeId($get['position_id']);
            if ($positionId) {
                $query->where('position_id', $positionId);
            }
        }

        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum((string)$get['status'], Enum::getAllLendingPositionStatuses());
            if ($status) {
                $query->where('status', $status);
            }
        }

        return $query;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRow(int $id): ?array
    {
        $row = Db::connect('db_bitfinex')
            ->name('funding_lending_position')
            ->where('id', $id)
            ->find();

        if (empty($row)) {
            return null;
        }

        return $this->formatRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRow(array $row): array
    {
        $row['amount_fmt'] = $this->trimDecimal($row['amount'] ?? 0, 8);
        $row['rate_fmt'] = $this->trimDecimal($row['rate'] ?? 0, 10);
        $row['interest_accrued_fmt'] = $this->trimDecimal($row['interest_accrued'] ?? 0, 10);
        $row['monthly_yield_rate_fmt'] = $this->trimDecimal($row['monthly_yield_rate'] ?? 0, 10);
        $row['annual_yield_rate_fmt'] = number_format((float)($row['annual_yield_rate'] ?? 0) * 100, 2, '.', '') . '%';
        if (isset($row['fee'])) {
            $row['fee_fmt'] = $this->trimDecimal($row['fee'], 10);
        }
        $row['profit_fmt'] = isset($row['profit'])
            ? $this->trimDecimal($row['profit'], 10)
            : '-';
        $row['status_badge_class'] = $this->statusBadgeClass((string)($row['status'] ?? ''));
        $row['mts_create_fmt'] = $this->formatTsMs($row['mts_create'] ?? 0);
        $row['mts_update_fmt'] = $this->formatTsMs($row['mts_update'] ?? 0);
        $row['mts_opening_fmt'] = $this->formatTsMs($row['mts_opening'] ?? 0);
        $row['mts_last_payout_fmt'] = $this->formatTsMs($row['mts_last_payout'] ?? 0);
        $row['raw_row_json_display'] = $this->jsonForDisplay($row['raw_row_json'] ?? null);
        if (isset($row['interest_ledger_json'])) {
            $row['interest_ledger_json_display'] = $this->jsonForDisplay($row['interest_ledger_json']);
        }
        $row['created_at'] = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at'] = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }

    private function statusBadgeClass(string $status): string
    {
        switch (strtoupper(trim($status))) {
            case 'ACTIVE':
                return 'layui-bg-green';
            case 'CLOSED (EXPIRED)':
            case 'CLOSED (REDUCED)':
                return 'layui-bg-blue';
            case 'CLOSED':
                return 'lp-status-closed';
            default:
                return 'layui-bg-gray';
        }
    }
}
