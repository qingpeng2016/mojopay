<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\const\Enum;
use app\bitfinex\service\FundApiClient;
use app\bitfinex\trait\RecordViewTrait;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\db\Query;
use think\facade\Db;

/**
 * 理财产品列表（fund_products）
 */
class FundProduct extends Controller
{
    use SafeQueryTrait;
    use RecordViewTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '产品列表';
        $get         = $this->request->get();

        $result = $this->filteredQuery($get)
            ->orderRaw("FIELD(product_type, 'GUARANTEED', 'FLOATING'), FIELD(term, 'FLEXIBLE', 'D7', 'D14', 'D30', 'D60', 'D90', 'D120'), id ASC")
            ->paginate([
                'list_rows' => isset($get['pageSize'])
                    ? min(100, max(10, intval($get['pageSize'])))
                    : 20,
                'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
                'query'     => $get,
                'path'      => '/admin.html#/bitfinex/fund_product/index.html',
            ], false);

        $list = $result->items();
        $list = $this->attachProductSummaries($list);
        foreach ($list as &$row) {
            $row = $this->formatProductRow($row);
            $row['can_delete'] = $this->canDeleteProduct((int)($row['id'] ?? 0));
        }
        unset($row);

        $this->assign([
            'list'       => $list,
            'pagehtml'   => $result->render(),
            'get'        => $get,
            'types'      => Enum::getAllFundProductTypes(),
            'terms'      => Enum::getAllFundProductTerms(),
            'statuses'   => Enum::getFundProductStatusLabels(),
            'symbols'    => Enum::getAllFundingSymbols(),
        ]);

        return $this->fetch();
    }

    /**
     * 用户持仓：当前 ACTIVE 申购记录
     *
     * @auth true
     */
    public function holdings()
    {
        $productId = $this->sanitizeId($this->request->get('id'));
        if (!$productId) {
            return $this->error('参数错误');
        }

        $product = Db::connect('db_bitfinex')
            ->name('fund_products')
            ->where('id', $productId)
            ->find();
        if (empty($product)) {
            return $this->error('产品不存在');
        }

        $rows = Db::connect('db_bitfinex')
            ->name('fund_client_product_subscription')
            ->alias('s')
            ->leftJoin('fund_client c', 'c.id = s.client_id')
            ->where('s.product_id', $productId)
            ->where('s.status', 'ACTIVE')
            ->field('s.*, c.name AS client_name')
            ->order('s.id', 'desc')
            ->limit(500)
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row = $this->formatHoldingRow($row, (string)($product['product_type'] ?? ''));
        }
        unset($row);

        $this->assign([
            'product'  => $this->formatProductRow($product),
            'holdings' => $rows,
            'count'    => count($rows),
        ]);

        return $this->fetch();
    }

    /**
     * 查看挂单：当前 submitted 的 funding offer
     *
     * @auth true
     */
    public function offers()
    {
        $productId = $this->sanitizeId($this->request->get('id'));
        if (!$productId) {
            return $this->error('参数错误');
        }

        $product = Db::connect('db_bitfinex')
            ->name('fund_products')
            ->where('id', $productId)
            ->find();
        if (empty($product)) {
            return $this->error('产品不存在');
        }

        $rows = Db::connect('db_bitfinex')
            ->name('fund_bitfinex_funding_orders')
            ->where('product_id', $productId)
            ->where('status', 'submitted')
            ->order('created_at', 'desc')
            ->order('id', 'desc')
            ->limit(500)
            ->select()
            ->toArray();

        $layerLabels = Enum::getLayerCodeLabels();
        foreach ($rows as &$row) {
            $row = $this->formatOfferRow($row, $layerLabels);
        }
        unset($row);

        $this->assign([
            'product' => $this->formatProductRow($product),
            'offers'  => $rows,
            'count'   => count($rows),
        ]);

        return $this->fetch();
    }

    /**
     * 取消该产品子账号全部挂单
     *
     * @auth true
     */
    public function cancel_offers()
    {
        $productId = $this->sanitizeId($this->request->post('id'));
        if (!$productId) {
            return $this->error('参数错误');
        }

        $product = Db::connect('db_bitfinex')
            ->name('fund_products')
            ->where('id', $productId)
            ->find();
        if (empty($product)) {
            return $this->error('产品不存在');
        }

        $subAccount = Db::connect('db_bitfinex')
            ->name('fund_bitfinex_sub_accounts')
            ->where('product_id', $productId)
            ->order('id', 'asc')
            ->find();
        if (empty($subAccount)) {
            return $this->error('未找到该产品对应的 Bitfinex 子账号');
        }

        $subAccountId = (int)($subAccount['id'] ?? 0);
        if ($subAccountId <= 0) {
            return $this->error('子账号 ID 无效');
        }

        $result = (new FundApiClient())->cancelAllOffers($subAccountId);
        if (!$result['ok']) {
            return $this->error('取消挂单失败：' . $result['message']);
        }

        $productName = (string)($product['product_name'] ?? '');
        return $this->success(sprintf(
            '已提交取消挂单：%s（sub_account_id=%d）：%s',
            $productName !== '' ? $productName : ('产品#' . $productId),
            $subAccountId,
            $result['message']
        ));
    }

    /**
     * 修改可投总金额（fund_products.cash_available）
     *
     * @auth true
     */
    public function edit()
    {
        if ($this->request->isPost()) {
            $productId = $this->sanitizeId($this->request->post('id'));
            if (!$productId) {
                return $this->error('参数错误');
            }

            $cashAvailable = $this->parseNonNegativeDecimalString($this->request->post('cash_available'));
            if ($cashAvailable === null) {
                return $this->error('可投总金额须为不小于 0 的数字');
            }

            $product = Db::connect('db_bitfinex')
                ->name('fund_products')
                ->where('id', $productId)
                ->find();
            if (empty($product)) {
                return $this->error('产品不存在');
            }

            $pending = (float)($product['cash_pending'] ?? 0);
            $lending = (float)($product['cash_lending'] ?? 0);
            $avail   = (float)$cashAvailable;
            $free    = $avail - $pending - $lending;

            $affected = Db::connect('db_bitfinex')
                ->name('fund_products')
                ->where('id', $productId)
                ->update([
                    'cash_available'      => $cashAvailable,
                    'cash_available_free' => $this->trimDecimal($free, 8),
                    'updated_at'          => date('Y-m-d H:i:s'),
                ]);
            if ($affected < 1) {
                return $this->error('未更新任何记录');
            }

            return $this->success('可投总金额已更新');
        }

        $productId = $this->sanitizeId($this->request->get('id'));
        if (!$productId) {
            return $this->error('参数错误');
        }

        $product = Db::connect('db_bitfinex')
            ->name('fund_products')
            ->where('id', $productId)
            ->find();
        if (empty($product)) {
            return $this->error('产品不存在');
        }

        $vo = $this->formatProductRow($product);
        $vo['cash_pending_raw']  = (string)($product['cash_pending'] ?? '0');
        $vo['cash_lending_raw']  = (string)($product['cash_lending'] ?? '0');

        $this->assign('vo', $vo);
        return $this->fetch('edit');
    }

    /**
     * 新增理财产品（fund_products）
     *
     * @auth true
     */
    public function add()
    {
        if ($this->request->isPost()) {
            [$row, $err] = $this->parseProductCreateData($this->request->post());
            if ($err !== null) {
                return $this->error($err);
            }

            $db = Db::connect('db_bitfinex');
            $exists = $db->name('fund_products')->where('product_code', $row['product_code'])->find();
            if (!empty($exists)) {
                return $this->error('产品编码已存在：' . $row['product_code']);
            }

            try {
                $db->name('fund_products')->insert($row);
            } catch (\Throwable $e) {
                return $this->error('创建失败：' . $e->getMessage());
            }

            return $this->success('产品已创建');
        }

        $this->assign([
            'types'    => Enum::getAllFundProductTypes(),
            'terms'    => Enum::getAllFundProductTerms(),
            'statuses' => Enum::getFundProductStatusLabels(),
            'symbols'  => Enum::getAllFundingSymbols(),
            'vo'       => [
                'product_type'          => 'GUARANTEED',
                'term'                  => 'D30',
                'funding_symbol'        => 'fUSD',
                'currency'              => 'USD',
                'guaranteed_rate_pct'   => '8',
                'cash_available'        => '0',
                'min_funding_offer_usd' => '150',
                'placement_period'      => '2',
                'status'                => 'active',
                'launch_date'           => date('Y-m-d'),
            ],
        ]);

        return $this->fetch('add');
    }

    /**
     * 删除产品（仅无申购/赎回/挂单/在借记录时可删；并清理子账号与用户利率行）
     *
     * @auth true
     */
    public function remove()
    {
        $productId = $this->sanitizeId($this->request->post('id'));
        if (!$productId) {
            return $this->error('参数错误');
        }

        $db = Db::connect('db_bitfinex');
        $product = $db->name('fund_products')->where('id', $productId)->find();
        if (empty($product)) {
            return $this->error('产品不存在');
        }

        $blockReason = $this->productDeleteBlockReason($productId);
        if ($blockReason !== null) {
            return $this->error($blockReason);
        }

        try {
            $db->transaction(function () use ($db, $productId) {
                $db->name('fund_bitfinex_sub_accounts')->where('product_id', $productId)->delete();
                $db->name('fund_client_guaranteed_rate')->where('product_id', $productId)->delete();
                $deleted = $db->name('fund_products')->where('id', $productId)->delete();
                if ($deleted < 1) {
                    throw new \RuntimeException('未删除任何产品记录');
                }
            });
        } catch (\Throwable $e) {
            return $this->error('删除失败：' . $e->getMessage());
        }

        $name = (string)($product['product_name'] ?? '');
        $code = (string)($product['product_code'] ?? '');
        return $this->success(sprintf(
            '已删除产品：%s（%s）',
            $name !== '' ? $name : ('#' . $productId),
            $code !== '' ? $code : '-'
        ));
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function parseProductCreateData(array $post): array
    {
        $productCode = strtoupper(trim((string)($post['product_code'] ?? '')));
        if ($productCode === '' || strlen($productCode) > 64) {
            return [[], '产品编码必填且不超过 64 字符'];
        }
        if (!preg_match('/^[A-Z0-9_]+$/', $productCode)) {
            return [[], '产品编码仅允许大写字母、数字与下划线'];
        }

        $productName = trim((string)($post['product_name'] ?? ''));
        if ($productName === '' || mb_strlen($productName) > 128) {
            return [[], '产品名称必填且不超过 128 字符'];
        }

        $productType = $this->sanitizeEnum($post['product_type'] ?? '', Enum::getAllFundProductTypes());
        if (!$productType) {
            return [[], '产品类型无效'];
        }

        $term = $this->sanitizeEnum($post['term'] ?? '', Enum::getAllFundProductTerms());
        if (!$term) {
            return [[], '期限无效'];
        }

        $fundingSymbol = $this->sanitizeEnum($post['funding_symbol'] ?? '', Enum::getAllFundingSymbols());
        if (!$fundingSymbol) {
            return [[], 'Funding Symbol 无效'];
        }

        $currency = strtoupper(trim((string)($post['currency'] ?? 'USD')));
        if ($currency === '' || strlen($currency) > 16) {
            return [[], '币种无效'];
        }

        $status = $this->sanitizeEnum($post['status'] ?? 'active', Enum::getAllFundProductStatuses());
        if (!$status) {
            return [[], '状态无效'];
        }

        $cashAvailable = $this->parseNonNegativeDecimalString($post['cash_available'] ?? '0');
        if ($cashAvailable === null) {
            return [[], '可投总金额须为不小于 0 的数字'];
        }

        $minOffer = $this->parseNonNegativeDecimalString($post['min_funding_offer_usd'] ?? '150');
        if ($minOffer === null || (float)$minOffer <= 0) {
            return [[], '最小挂单金额须为大于 0 的数字'];
        }

        $placementPeriod = (int)($post['placement_period'] ?? 2);
        if ($placementPeriod < 1 || $placementPeriod > 365) {
            return [[], '铺单期限须为 1–365 的整数'];
        }

        $guaranteedRate = '0';
        if ($productType === 'GUARANTEED') {
            $ratePct = trim((string)($post['guaranteed_rate_pct'] ?? ''));
            if ($ratePct === '' || !is_numeric($ratePct)) {
                return [[], '保底型产品请填写保底年化（%）'];
            }
            $pct = (float)$ratePct;
            if ($pct < 0 || $pct > 100) {
                return [[], '保底年化须在 0–100 之间'];
            }
            $guaranteedRate = rtrim(rtrim(number_format($pct / 100, 10, '.', ''), '0'), '.') ?: '0';
        }

        $launchRaw = trim((string)($post['launch_date'] ?? ''));
        if ($launchRaw === '') {
            return [[], '产品上线日必填（fund_products.launch_date NOT NULL）'];
        }
        $ts = strtotime($launchRaw);
        if ($ts === false) {
            return [[], '产品上线日格式无效，请使用 YYYY-MM-DD'];
        }
        $launchDate = date('Y-m-d', $ts);

        $remark = trim((string)($post['remark'] ?? ''));
        if (mb_strlen($remark) > 500) {
            return [[], '备注不超过 500 字'];
        }

        $now = date('Y-m-d H:i:s');

        return [[
            'product_code'          => $productCode,
            'product_name'          => $productName,
            'product_type'          => $productType,
            'term'                  => $term,
            'term_days'             => Enum::getFundProductTermDays($term),
            'funding_symbol'        => $fundingSymbol,
            'currency'              => $currency,
            'launch_date'           => $launchDate,
            'total_shares'          => 0,
            'nav_per_share'         => 1,
            'cash_total'            => $cashAvailable,
            'cash_available'        => $cashAvailable,
            'cash_available_free'   => $cashAvailable,
            'cash_pending'          => 0,
            'cash_lending'          => 0,
            'guaranteed_rate'       => $guaranteedRate,
            'min_funding_offer_usd' => $minOffer,
            'placement_period'      => $placementPeriod,
            'status'                => $status,
            'remark'                => $remark,
            'created_at'            => $now,
            'updated_at'            => $now,
        ], null];
    }

    /**
     * @param array<string, mixed> $get
     */
    private function filteredQuery(array $get): Query
    {
        /** @var Query $query */
        $query = Db::connect('db_bitfinex')->name('fund_products');

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }

        if (!empty($get['product_name'])) {
            $name = trim((string)$get['product_name']);
            if ($name !== '') {
                $query->whereLike('product_name', '%' . $this->sanitizeLike($name, 128) . '%');
            }
        }

        if (!empty($get['product_type'])) {
            $type = $this->sanitizeEnum($get['product_type'], Enum::getAllFundProductTypes());
            if ($type) {
                $query->where('product_type', $type);
            }
        }

        if (!empty($get['term'])) {
            $term = $this->sanitizeEnum($get['term'], Enum::getAllFundProductTerms());
            if ($term) {
                $query->where('term', $term);
            }
        }

        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getAllFundProductStatuses());
            if ($status) {
                $query->where('status', $status);
            }
        }

        if (!empty($get['funding_symbol'])) {
            $symbol = $this->sanitizeEnum($get['funding_symbol'], Enum::getAllFundingSymbols());
            if ($symbol) {
                $query->where('funding_symbol', $symbol);
            }
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatProductRow(array $row): array
    {
        $statusLabels = Enum::getFundProductStatusLabels();
        $status       = (string)($row['status'] ?? '');

        $row['status_label']           = $statusLabels[$status] ?? ($status !== '' ? $status : '-');
        $row['total_shares_fmt']       = $this->trimDecimal($row['total_shares'] ?? 0, 10);
        $row['nav_per_share_fmt']      = $this->trimDecimal($row['nav_per_share'] ?? 0, 10);
        $row['cash_total_fmt']         = $this->trimDecimal($row['cash_total'] ?? 0, 8);
        $row['cash_available_fmt']     = $this->trimDecimal($row['cash_available'] ?? 0, 8);
        $row['cash_available_free_fmt']= $this->trimDecimal($row['cash_available_free'] ?? 0, 8);
        $row['cash_pending_fmt']       = $this->trimDecimal($row['cash_pending'] ?? 0, 8);
        $row['cash_lending_fmt']       = $this->trimDecimal($row['cash_lending'] ?? 0, 8);
        $row['guaranteed_rate_fmt']        = $this->formatRatePercent($row['guaranteed_rate'] ?? 0);
        $row['product_total_profit_fmt']   = $this->trimDecimal($row['product_total_profit'] ?? 0, 8);
        $row['product_yield_annual_fmt']   = $this->formatRatePercent($row['product_yield_annual'] ?? 0);
        $row['mj_total_profit_fmt']        = $this->trimDecimal($row['mj_total_profit'] ?? 0, 8);
        $row['mj_yield_annual_fmt']        = $this->formatRatePercent($row['mj_yield_annual'] ?? 0);

        return $row;
    }

    /**
     * @param array<int, array<string, mixed>> $list
     * @return array<int, array<string, mixed>>
     */
    private function attachProductSummaries(array $list): array
    {
        if ($list === []) {
            return $list;
        }

        $ids = [];
        foreach ($list as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return $list;
        }

        $summaries = Db::connect('db_bitfinex')
            ->name('fund_product_data_summary')
            ->whereIn('product_id', array_values(array_unique($ids)))
            ->column(
                'product_total_profit,product_yield_annual,mj_total_profit,mj_yield_annual',
                'product_id'
            );

        foreach ($list as &$row) {
            $id = (int)($row['id'] ?? 0);
            $summary = $summaries[$id] ?? null;
            if (is_array($summary)) {
                $row['product_total_profit'] = $summary['product_total_profit'] ?? 0;
                $row['product_yield_annual'] = $summary['product_yield_annual'] ?? 0;
                $row['mj_total_profit']      = $summary['mj_total_profit'] ?? 0;
                $row['mj_yield_annual']      = $summary['mj_yield_annual'] ?? 0;
            } else {
                $row['product_total_profit'] = 0;
                $row['product_yield_annual'] = 0;
                $row['mj_total_profit']      = 0;
                $row['mj_yield_annual']      = 0;
            }
        }
        unset($row);

        return $list;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatHoldingRow(array $row, string $productType): array
    {
        $row['subscribe_amount_fmt']  = $this->trimDecimal($row['subscribe_amount'] ?? 0, 8);
        $row['floating_shares_fmt']   = $this->trimDecimal($row['floating_shares'] ?? 0, 10);
        $row['redeemable_amount_fmt'] = $this->trimDecimal($row['redeemable_amount'] ?? 0, 8);
        $row['current_interest_fmt']  = $this->trimDecimal($row['current_interest'] ?? 0, 8);
        $row['position_display']      = $productType === 'FLOATING'
            ? ($row['floating_shares_fmt'] . ' 份')
            : $row['subscribe_amount_fmt'];
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $layerLabels
     * @return array<string, mixed>
     */
    private function formatOfferRow(array $row, array $layerLabels): array
    {
        $layerCode = (string)($row['layer_code'] ?? '');
        $row['layer_code_label']  = $layerLabels[$layerCode] ?? ($layerCode !== '' ? $layerCode : '-');
        $row['amount_usd_fmt']    = $this->trimDecimal($row['amount_usd'] ?? 0, 8);
        $row['rate_fmt']          = $this->trimDecimal($row['rate'] ?? 0, 10);
        $row['origin_rate_fmt']   = $this->trimDecimal($row['origin_rate'] ?? 0, 10);
        $row['rate_annual_fmt']   = $this->formatRatePercent((float)($row['rate'] ?? 0) * 365);
        $row['created_at']        = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at']        = $this->formatDatetime($row['updated_at'] ?? null);
        $sceneType                = (string)($row['scene_type'] ?? '');
        $sceneSubType             = (string)($row['scene_sub_type'] ?? '');
        $row['scene_display']     = $sceneType !== ''
            ? ($sceneSubType !== '' ? ($sceneType . '/' . $sceneSubType) : $sceneType)
            : '-';

        return $row;
    }

    /**
     * @param mixed $rate Decimal annual rate e.g. 0.05
     */
    private function formatRatePercent($rate): string
    {
        if ($rate === null || $rate === '') {
            return '0%';
        }
        return $this->trimDecimal((float)$rate * 100, 4) . '%';
    }

    private function parseNonNegativeDecimalString($value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $num = (float)$raw;
        if ($num < 0) {
            return null;
        }
        return rtrim(rtrim(number_format($num, 8, '.', ''), '0'), '.') ?: '0';
    }

    private function canDeleteProduct(int $productId): bool
    {
        return $productId > 0 && $this->productDeleteBlockReason($productId) === null;
    }

    private function productDeleteBlockReason(int $productId): ?string
    {
        if ($productId <= 0) {
            return '参数错误';
        }

        $db = Db::connect('db_bitfinex');
        if ($db->name('fund_client_product_subscription')->where('product_id', $productId)->count() > 0) {
            return '该产品已有申购记录，不可删除';
        }
        if ($db->name('fund_client_product_redemption')->where('product_id', $productId)->count() > 0) {
            return '该产品已有赎回记录，不可删除';
        }
        if ($db->name('fund_bitfinex_funding_orders')->where('product_id', $productId)->count() > 0) {
            return '该产品已有挂单记录，不可删除';
        }
        if ($db->name('fund_bitfinex_product_loan')->where('product_id', $productId)->count() > 0) {
            return '该产品已有在借/借贷记录，不可删除';
        }

        return null;
    }
}
