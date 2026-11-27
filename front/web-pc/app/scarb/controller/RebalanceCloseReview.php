<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\const\Enum;
use app\scarb\trait\ScarbListTrait;
use think\admin\Controller;
use think\db\Query;

class RebalanceCloseReview extends Controller
{
    use ScarbListTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '换仓审核';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)->order('created_at', 'desc')->order('id', 'desc');
        $result      = $this->paginateScarb($query, $get, 'rebalance_close_review/index');
        $list        = $result->items();
        $livePnlMap  = $this->loadLivePnlRatioMap($list);
        foreach ($list as &$row) {
            $row = $this->formatRow($row, $livePnlMap);
        }
        unset($row);

        $this->assign([
            'list'       => $list,
            'pagehtml'   => $result->render(),
            'get'        => $get,
            'platforms'  => array_combine(Enum::getAllPlatforms(), Enum::getAllPlatforms()),
            'statuses'   => array_combine(Enum::getRebalanceReviewStatuses(), Enum::getRebalanceReviewStatuses()),
            'pos_sides'  => array_combine(Enum::getPositionSides(), Enum::getPositionSides()),
        ]);

        return $this->fetch();
    }

    /**
     * 后台确认：INIT → PENDING，策略脚本方可执行换仓。
     *
     * @auth true
     */
    public function approve()
    {
        if (!$this->request->isPost()) {
            return $this->error('请求方式错误');
        }

        $id = $this->sanitizeId($this->request->post('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $affected = $this->scarbDb()->name('rebalance_close_review')
            ->where('id', $id)
            ->where('status', 'INIT')
            ->update(['status' => 'PENDING']);

        if ($affected < 1) {
            $row = $this->scarbDb()->name('rebalance_close_review')->where('id', $id)->find();
            if (empty($row)) {
                return $this->error('记录不存在');
            }
            if (strcasecmp((string)($row['status'] ?? ''), 'INIT') !== 0) {
                return $this->error('仅 INIT 状态可审核，当前：' . ($row['status'] ?? '-'));
            }

            return $this->error('审核失败，请重试');
        }

        return $this->success('已确认开启换仓', 'javascript:location.reload()');
    }

    /**
     * 后台取消：INIT → CANCELLED。
     *
     * @auth true
     */
    public function cancel()
    {
        if (!$this->request->isPost()) {
            return $this->error('请求方式错误');
        }

        $id = $this->sanitizeId($this->request->post('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $affected = $this->scarbDb()->name('rebalance_close_review')
            ->where('id', $id)
            ->where('status', 'INIT')
            ->update(['status' => 'CANCELLED']);

        if ($affected < 1) {
            $row = $this->scarbDb()->name('rebalance_close_review')->where('id', $id)->find();
            if (empty($row)) {
                return $this->error('记录不存在');
            }
            if (strcasecmp((string)($row['status'] ?? ''), 'INIT') !== 0) {
                return $this->error('仅 INIT 状态可取消，当前：' . ($row['status'] ?? '-'));
            }

            return $this->error('取消失败，请重试');
        }

        return $this->success('已取消换仓', 'javascript:location.reload()');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->scarbDb()->name('rebalance_close_review');
        $this->applyCommonListFilters($query, $get, 'created_at');

        if (!empty($get['asset'])) {
            $asset = trim((string)$get['asset']);
            if ($asset !== '' && strlen($asset) <= 32) {
                $query->where('asset', $asset);
            }
        }
        if (!empty($get['symbol'])) {
            $symbol = trim((string)$get['symbol']);
            if ($symbol !== '' && strlen($symbol) <= 32) {
                $query->where('symbol', $symbol);
            }
        }
        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getRebalanceReviewStatuses());
            if ($status) {
                $query->where('status', $status);
            }
        }

        return $query;
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return array<string, mixed> key=accountId|symbol|side → pnl_ratio
     */
    private function loadLivePnlRatioMap(array $list): array
    {
        $keys = [];
        foreach ($list as $row) {
            if (strcasecmp((string)($row['status'] ?? ''), 'INIT') !== 0) {
                continue;
            }
            $accountId = (int)($row['exchange_account_id'] ?? 0);
            $symbol    = trim((string)($row['symbol'] ?? ''));
            $side      = strtoupper(trim((string)($row['win_position_side'] ?? '')));
            if ($accountId < 1 || $symbol === '' || ($side !== 'LONG' && $side !== 'SHORT')) {
                continue;
            }
            $keys[$accountId . '|' . $symbol . '|' . $side] = [
                'exchange_account_id' => $accountId,
                'symbol'              => $symbol,
                'position_side'       => $side,
            ];
        }
        if ($keys === []) {
            return [];
        }

        $accountIds = [];
        $symbols    = [];
        $sides      = [];
        foreach ($keys as $item) {
            $accountIds[$item['exchange_account_id']] = $item['exchange_account_id'];
            $symbols[$item['symbol']]                 = $item['symbol'];
            $sides[$item['position_side']]            = $item['position_side'];
        }

        $rows = $this->scarbDb()
            ->name('positions')
            ->where('status', 'OPEN')
            ->whereIn('exchange_account_id', array_values($accountIds))
            ->whereIn('symbol', array_values($symbols))
            ->whereIn('position_side', array_values($sides))
            ->field('exchange_account_id, symbol, position_side, pnl_ratio')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $pos) {
            $k = (int)($pos['exchange_account_id'] ?? 0)
                . '|' . trim((string)($pos['symbol'] ?? ''))
                . '|' . strtoupper(trim((string)($pos['position_side'] ?? '')));
            if (!isset($keys[$k])) {
                continue;
            }
            $map[$k] = $pos['pnl_ratio'] ?? null;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $livePnlMap
     */
    private function formatRow(array $row, array $livePnlMap = []): array
    {
        $row['side_pnl_ratio_fmt']   = $this->formatRatioPercent($row['side_pnl_ratio'] ?? null);
        $row['min_pnl_ratio_fmt']    = $this->formatRatioPercent($row['min_pnl_ratio'] ?? null);
        $row['current_pnl_ratio_fmt'] = '--';
        if (strcasecmp((string)($row['status'] ?? ''), 'INIT') === 0) {
            $k = (int)($row['exchange_account_id'] ?? 0)
                . '|' . trim((string)($row['symbol'] ?? ''))
                . '|' . strtoupper(trim((string)($row['win_position_side'] ?? '')));
            if (array_key_exists($k, $livePnlMap)) {
                $row['current_pnl_ratio_fmt'] = $this->formatRatioPercent($livePnlMap[$k]);
            }
        }
        $row['target_close_qty_fmt'] = $this->trimDecimal($row['target_close_qty'] ?? 0, 8);
        $row['closed_qty_fmt']       = $this->trimDecimal($row['closed_qty'] ?? 0, 8);
        $row['created_at']            = $this->formatDatetime($row['created_at'] ?? null);
        $row['finished_at']           = $this->formatDatetime($row['finished_at'] ?? null);

        return $row;
    }

    /** 库内为小数收益率，展示为百分数（×100）。 */
    private function formatRatioPercent($value, int $scale = 2): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return $this->trimDecimal((float)$value * 100, $scale) . '%';
    }
}
