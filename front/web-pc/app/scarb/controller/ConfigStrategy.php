<?php

declare (strict_types=1);

namespace app\scarb\controller;

use app\scarb\trait\ScarbAccountTrait;
use think\admin\Controller;
use think\db\Query;

class ConfigStrategy extends Controller
{
    use ScarbAccountTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '策略配置';
        $get         = $this->request->get();
        $query       = $this->filteredQuery($get)->order('exchange_account_id', 'asc')->order('asset', 'asc');
        $result      = $this->paginateScarb($query, $get, 'config_strategy/index', 50);
        $list        = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'     => $list,
            'pagehtml' => $result->render(),
            'get'      => $get,
        ]);

        return $this->fetch();
    }

    /**
     * @auth true
     */
    public function add()
    {
        if ($this->request->isPost()) {
            try {
                $data = $this->buildFormData($this->request->post());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $dup = $this->scarbDb()->name('config_strategy')
                ->where('exchange_account_id', $data['exchange_account_id'])
                ->where('asset', $data['asset'])
                ->find();
            if (!empty($dup)) {
                return $this->error('同账户下该标的已存在');
            }

            $this->scarbDb()->name('config_strategy')->insert($data);

            return $this->success('新增成功');
        }

        $this->assign('vo', [
            'asset'                          => 'ETH',
            'contract_leverage'              => 1,
            'limit_to_market_after_sec'      => 30,
            'limit_to_market_adverse_price_pct' => '0.003',
            'maker_long_book_level'          => 'QUEUE',
            'maker_short_book_level'         => 'QUEUE',
            'rebalance_min_price_change_pct' => '0.1',
        ]);
        $this->assign('accounts', $this->loadAccountOptions());

        return $this->fetch('form');
    }

    /**
     * @auth true
     */
    public function edit()
    {
        if ($this->request->isPost()) {
            $id = $this->sanitizeId($this->request->post('id'));
            if (!$id) {
                return $this->error('参数错误');
            }
            if ($this->loadRow($id) === null) {
                return $this->error('记录不存在');
            }

            try {
                $data = $this->buildFormData($this->request->post());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage());
            }

            $dup = $this->scarbDb()->name('config_strategy')
                ->where('exchange_account_id', $data['exchange_account_id'])
                ->where('asset', $data['asset'])
                ->where('id', '<>', $id)
                ->find();
            if (!empty($dup)) {
                return $this->error('同账户下该标的已存在');
            }

            $this->scarbDb()->name('config_strategy')->where('id', $id)->update($data);

            return $this->success('保存成功');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }
        $vo = $this->loadRow($id);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $this->assign('vo', $vo);
        $this->assign('accounts', $this->loadAccountOptions());

        return $this->fetch('form');
    }

    /** @param array<string, mixed> $get */
    private function filteredQuery(array $get): Query
    {
        $query = $this->scarbDb()->name('config_strategy');
        $this->applyCommonListFilters($query, $get, 'updated_at');

        if (!empty($get['asset'])) {
            $asset = trim((string)$get['asset']);
            if ($asset !== '' && strlen($asset) <= 32) {
                $query->where('asset', $asset);
            }
        }

        return $query;
    }

    /** @return array<string, mixed>|null */
    private function loadRow(int $id): ?array
    {
        $row = $this->scarbDb()->name('config_strategy')->where('id', $id)->find();
        if (empty($row)) {
            return null;
        }

        return $this->formatRow($row);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function buildFormData(array $post): array
    {
        $accountId = $this->sanitizeId($post['exchange_account_id'] ?? null);
        if (!$accountId) {
            throw new \InvalidArgumentException('请选择交易所账户');
        }
        $platform = $this->resolveAccountPlatform($accountId);
        if ($platform === null) {
            throw new \InvalidArgumentException('账户不存在');
        }

        $asset = trim((string)($post['asset'] ?? ''));
        if ($asset === '' || strlen($asset) > 32) {
            throw new \InvalidArgumentException('asset 无效');
        }

        $leverage = trim((string)($post['contract_leverage'] ?? ''));
        if (!ctype_digit($leverage) || (int)$leverage < 1) {
            throw new \InvalidArgumentException('contract_leverage 须为正整数');
        }

        $perOrder     = $this->optionalDecimal((string)($post['per_order_leverage_qty'] ?? ''));
        $perOrderUnit = $this->sanitizeQtyAsset((string)($post['per_order_leverage_qty_asset'] ?? ''), $asset);

        $limitSec = trim((string)($post['limit_to_market_after_sec'] ?? ''));
        if (!ctype_digit($limitSec)) {
            throw new \InvalidArgumentException('limit_to_market_after_sec 须为非负整数');
        }

        $adversePct = $this->requiredDecimal((string)($post['limit_to_market_adverse_price_pct'] ?? ''), 'limit_to_market_adverse_price_pct');

        $longLvl = strtoupper(trim((string)($post['maker_long_book_level'] ?? '')));
        $shortLvl = strtoupper(trim((string)($post['maker_short_book_level'] ?? '')));
        $priceMatchAllowed = ['QUEUE' => true, 'QUEUE_5' => true, 'QUEUE_10' => true, 'QUEUE_20' => true];
        if (!isset($priceMatchAllowed[$longLvl]) || !isset($priceMatchAllowed[$shortLvl])) {
            throw new \InvalidArgumentException('maker book level 须为 QUEUE、QUEUE_5、QUEUE_10、QUEUE_20');
        }

        $rebalPct = $this->requiredDecimal((string)($post['rebalance_min_price_change_pct'] ?? ''), 'rebalance_min_price_change_pct');
        $qtyTol   = $this->requiredDecimal((string)($post['qty_completion_tolerance_pct'] ?? ''), 'qty_completion_tolerance_pct');

        return [
            'exchange_account_id'            => $accountId,
            'platform'                         => $platform,
            'asset'                            => $asset,
            'contract_leverage'                => (int)$leverage,
            'per_order_leverage_qty'           => $perOrder,
            'per_order_leverage_qty_asset'     => $perOrderUnit,
            'limit_to_market_after_sec'        => (int)$limitSec,
            'limit_to_market_adverse_price_pct'=> $adversePct,
            'maker_long_book_level'            => $longLvl,
            'maker_short_book_level'           => $shortLvl,
            'rebalance_min_price_change_pct'   => $rebalPct,
            'qty_completion_tolerance_pct'     => $qtyTol,
        ];
    }

    /** @param array<string, mixed> $row */
    private function formatRow(array $row): array
    {
        $row['per_order_leverage_qty_fmt']              = $this->trimDecimal($row['per_order_leverage_qty'] ?? null, 8);
        $unit                                           = trim((string)($row['per_order_leverage_qty_asset'] ?? ''));
        $row['per_order_leverage_qty_unit']             = $unit !== '' ? $unit : 'BASE';
        $row['rebalance_min_price_change_pct_fmt']         = $this->formatRatioPercent($row['rebalance_min_price_change_pct'] ?? null);
        $row['qty_completion_tolerance_pct_fmt']           = $this->formatRatioPercent($row['qty_completion_tolerance_pct'] ?? null);
        $row['limit_to_market_adverse_price_pct_fmt']      = $this->formatRatioPercent($row['limit_to_market_adverse_price_pct'] ?? null);
        $row['updated_at']                              = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }

    /** 库内为小数比例，展示为百分数（×100）。 */
    private function formatRatioPercent($value, int $scale = 2): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return $this->trimDecimal((float)$value * 100, $scale) . '%';
    }
}
