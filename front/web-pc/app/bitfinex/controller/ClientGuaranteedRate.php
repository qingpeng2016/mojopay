<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\trait\RecordViewTrait;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\db\Query;
use think\facade\Db;

/**
 * 用户利率（fund_client_guaranteed_rate）
 */
class ClientGuaranteedRate extends Controller
{
    use SafeQueryTrait;
    use RecordViewTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '用户利率';
        $get         = $this->request->get();

        $result = $this->filteredQuery($get)
            ->order('r.id', 'desc')
            ->paginate([
                'list_rows' => isset($get['pageSize'])
                    ? min(100, max(10, intval($get['pageSize'])))
                    : 20,
                'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
                'query'     => $get,
                'path'      => '/admin.html#/bitfinex/client_guaranteed_rate/index.html',
            ], false);

        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'     => $list,
            'pagehtml' => $result->render(),
            'get'      => $get,
            'products' => $this->loadProductOptions(),
        ]);

        return $this->fetch();
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

            $vo = $this->loadRow($id);
            if ($vo === null) {
                return $this->error('记录不存在');
            }

            $ratePct  = trim((string)$this->request->post('guaranteed_rate_pct', ''));
            $bonusPct = trim((string)$this->request->post('bonus_pct', ''));
            $err      = $this->validateRatePercent($ratePct, '保底年化');
            if ($err !== null) {
                return $this->error($err);
            }
            $err = $this->validateRatePercent($bonusPct, 'Bonus');
            if ($err !== null) {
                return $this->error($err);
            }

            $rate     = (float)$ratePct / 100;
            $bonus    = (float)$bonusPct / 100;
            $affected = Db::connect('db_bitfinex')
                ->name('fund_client_guaranteed_rate')
                ->where('id', $id)
                ->update([
                    'guaranteed_rate' => $rate,
                    'bonus'           => $bonus,
                ]);

            if ($affected < 1) {
                return $this->error('未更新任何记录');
            }

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
        return $this->fetch('form');
    }

    /**
     * @param array<string, mixed> $get
     */
    private function filteredQuery(array $get): Query
    {
        /** @var Query $query */
        $query = Db::connect('db_bitfinex')
            ->name('fund_client_guaranteed_rate')
            ->alias('r')
            ->leftJoin('fund_client c', 'c.id = r.client_id')
            ->leftJoin('fund_products p', 'p.id = r.product_id')
            ->field('r.*, c.name AS client_name, p.product_name, p.guaranteed_rate AS product_guaranteed_rate');

        if (!empty($get['client_id'])) {
            $clientId = $this->sanitizeId($get['client_id']);
            if ($clientId) {
                $query->where('r.client_id', $clientId);
            }
        }

        if (!empty($get['product_id'])) {
            $productId = $this->sanitizeId($get['product_id']);
            if ($productId) {
                $query->where('r.product_id', $productId);
            }
        }

        if (!empty($get['product_code'])) {
            $code = $this->sanitizeLike((string)$get['product_code'], 64);
            if ($code !== '') {
                $query->whereLike('r.product_code', "%{$code}%");
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
            ->name('fund_client_guaranteed_rate')
            ->alias('r')
            ->leftJoin('fund_client c', 'c.id = r.client_id')
            ->leftJoin('fund_products p', 'p.id = r.product_id')
            ->field('r.*, c.name AS client_name, p.product_name, p.guaranteed_rate AS product_guaranteed_rate')
            ->where('r.id', $id)
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
        $rate  = (float)($row['guaranteed_rate'] ?? 0);
        $bonus = (float)($row['bonus'] ?? 0);
        $row['guaranteed_rate_fmt']         = $this->formatRatePercent($rate);
        $row['guaranteed_rate_pct_display'] = $this->rateToPercentInput($rate);
        $row['bonus_fmt']                   = $this->formatRatePercent($bonus);
        $row['bonus_pct_display']           = $this->rateToPercentInput($bonus);
        $row['product_guaranteed_rate_fmt'] = $this->formatRatePercent($row['product_guaranteed_rate'] ?? 0);
        $row['created_at']                  = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at']                  = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadProductOptions(): array
    {
        return Db::connect('db_bitfinex')
            ->name('fund_products')
            ->where('product_type', 'GUARANTEED')
            ->order('product_code', 'asc')
            ->field('id, product_code, product_name')
            ->select()
            ->toArray();
    }

    private function rateToPercentInput($rate): string
    {
        if ($rate === null || $rate === '') {
            return '0';
        }

        return rtrim(rtrim(number_format((float)$rate * 100, 4, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @param mixed $rate
     */
    private function formatRatePercent($rate): string
    {
        if ($rate === null || $rate === '') {
            return '0%';
        }

        return $this->trimDecimal((float)$rate * 100, 4) . '%';
    }

    private function validateRatePercent(string $pct, string $label = '保底年化'): ?string
    {
        if ($pct === '' || !is_numeric($pct)) {
            return $label . '必须是数字（单位：%）';
        }
        $val = (float)$pct;
        if ($val < 0) {
            return $label . '不能为负数';
        }
        if ($val > 100) {
            return $label . '不能超过 100%';
        }

        return null;
    }
}
