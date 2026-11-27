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
 * 3.6.3 申购赎回管理
 */
class FundSubRedeem extends Controller
{
    use SafeQueryTrait;
    use RecordViewTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '申购赎回管理';
        $get         = $this->request->get();
        $tab         = ($get['tab'] ?? '') === 'redemption' ? 'redemption' : 'subscription';

        if ($tab === 'redemption') {
            $result = $this->filteredRedemptionQuery($get)
                ->order('requested_at', 'desc')
                ->order('id', 'desc')
                ->paginate([
                    'list_rows' => isset($get['pageSize'])
                        ? min(100, max(10, intval($get['pageSize'])))
                        : 20,
                    'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
                    'query'     => $get,
                    'path'      => '/admin.html#/bitfinex/fund_sub_redeem/index.html',
                ], false);

            $list = $result->items();
            foreach ($list as &$row) {
                $row = $this->formatRedemptionRow($row);
            }
            unset($row);

            $this->assign([
                'tab'          => $tab,
                'redemption_list'=> $list,
                'pagehtml'     => $result->render(),
                'get'          => $get,
                'sub_statuses' => Enum::getAllFundSubscriptionStatuses(),
                'red_types'    => Enum::getFundRedemptionTypeLabels(),
                'red_statuses' => Enum::getFundRedemptionStatusLabels(),
            ]);
            return $this->fetch('redemption_index');
        }

        $result = $this->filteredSubscriptionQuery($get)
            ->orderRaw("CASE WHEN s.status = 'ACTIVE' THEN 0 ELSE 1 END ASC")
            ->order('s.client_id', 'desc')
            ->order('p.placement_period', 'asc')
            ->order('s.id', 'desc')
            ->paginate([
                'list_rows' => isset($get['pageSize'])
                    ? min(100, max(10, intval($get['pageSize'])))
                    : 20,
                'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
                'query'     => $get,
                'path'      => '/admin.html#/bitfinex/fund_sub_redeem/index.html',
            ], false);

        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatSubscriptionRow($row);
        }
        unset($row);

        $this->assign([
            'tab'          => $tab,
            'list'         => $list,
            'pagehtml'     => $result->render(),
            'get'          => $get,
            'sub_statuses' => Enum::getAllFundSubscriptionStatuses(),
            'red_types'    => Enum::getFundRedemptionTypeLabels(),
            'red_statuses' => Enum::getFundRedemptionStatusLabels(),
        ]);

        return $this->fetch();
    }

    /**
     * 提交申购（调用 fund API）
     *
     * @auth true
     */
    public function subscribe()
    {
        if ($this->request->isPost()) {
            $clientId = $this->sanitizeId($this->request->post('client_id'));
            if (!$clientId) {
                return $this->error('请选择客户');
            }

            $productCode = trim((string)$this->request->post('product_code', ''));
            if ($productCode === '' || !$this->isActiveProductCode($productCode)) {
                return $this->error('请选择有效产品');
            }

            $amount = $this->parsePositiveDecimalString($this->request->post('amount'));
            if ($amount === null) {
                return $this->error('申购金额必须是大于 0 的数字');
            }

            if (!$this->clientExists($clientId)) {
                return $this->error('客户不存在');
            }

            $result = (new FundApiClient())->subscribe($clientId, $productCode, $amount);
            if (!$result['ok']) {
                return $this->error('申购失败：' . $result['message']);
            }

            return $this->success('申购提交成功：' . $result['message']);
        }

        $this->assign([
            'clients'            => $this->loadActiveClients(),
            'products'           => $this->loadActiveProducts(),
            'products_map_json'  => json_encode($this->loadSubscribeProductsMap(), JSON_UNESCAPED_UNICODE),
            'client_rates_json'  => json_encode($this->loadClientGuaranteedRateMap(), JSON_UNESCAPED_UNICODE),
        ]);
        return $this->fetch('subscribe_form');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadSubscribeProductsMap(): array
    {
        $rows = Db::connect('db_bitfinex')
            ->name('fund_products')
            ->where('status', 'active')
            ->field('product_code, product_type, nav_per_share, term_days, guaranteed_rate')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $code = (string)($row['product_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $map[$code] = [
                'product_type'    => (string)($row['product_type'] ?? ''),
                'nav_per_share'   => (float)($row['nav_per_share'] ?? 0),
                'term_days'       => (int)($row['term_days'] ?? 0),
                'guaranteed_rate' => (float)($row['guaranteed_rate'] ?? 0),
            ];
        }

        return $map;
    }

    /**
     * @return array<int, array<string, float>>
     */
    private function loadClientGuaranteedRateMap(): array
    {
        $rows = Db::connect('db_bitfinex')
            ->name('fund_client_guaranteed_rate')
            ->field('client_id, product_code, guaranteed_rate')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $clientId = (int)($row['client_id'] ?? 0);
            $code     = (string)($row['product_code'] ?? '');
            if ($clientId <= 0 || $code === '') {
                continue;
            }
            $map[$clientId][$code] = (float)($row['guaranteed_rate'] ?? 0);
        }

        return $map;
    }

    /**
     * 提交赎回（调用 fund API）
     *
     * @auth true
     */
    public function redeem()
    {
        if ($this->request->isPost()) {
            $subscriptionId = $this->sanitizeId($this->request->post('subscription_id'));
            if (!$subscriptionId) {
                return $this->error('参数错误');
            }

            $subscription = $this->loadSubscription($subscriptionId);
            if ($subscription === null) {
                return $this->error('申购记录不存在');
            }
            if ((string)($subscription['status'] ?? '') !== 'ACTIVE') {
                return $this->error('仅 ACTIVE 申购可赎回');
            }

            $redemptionType = $this->sanitizeEnum(
                $this->request->post('redemption_type', ''),
                Enum::getAllFundRedemptionTypes()
            );
            if (!$redemptionType) {
                return $this->error('请选择赎回类型');
            }

            $amount = null;
            $shares = null;
            if ($redemptionType === 'PARTIAL') {
                $productType = (string)($subscription['product_type'] ?? '');
                $amountRaw   = trim((string)$this->request->post('amount', ''));
                $sharesRaw   = trim((string)$this->request->post('shares', ''));

                if ($productType === 'FLOATING') {
                    if ($sharesRaw !== '') {
                        $shares = $this->parsePositiveDecimalString($sharesRaw);
                        if ($shares === null) {
                            return $this->error('赎回份额必须是大于 0 的数字');
                        }
                    } elseif ($amountRaw !== '') {
                        $amount = $this->parsePositiveDecimalString($amountRaw);
                        if ($amount === null) {
                            return $this->error('赎回金额必须是大于 0 的数字');
                        }
                    } else {
                        return $this->error('浮动型部分赎回请填写 shares 或 amount');
                    }
                } else {
                    if ($amountRaw === '') {
                        return $this->error('保底型部分赎回请填写 amount');
                    }
                    $amount = $this->parsePositiveDecimalString($amountRaw);
                    if ($amount === null) {
                        return $this->error('赎回金额必须是大于 0 的数字');
                    }
                }
            }

            $result = (new FundApiClient())->redeem($subscriptionId, $redemptionType, $amount, $shares);
            if (!$result['ok']) {
                return $this->error('赎回失败：' . $result['message']);
            }

            return $this->success('赎回提交成功：' . $result['message']);
        }

        $subscriptionId = $this->sanitizeId($this->request->get('subscription_id'));
        if (!$subscriptionId) {
            return $this->error('参数错误');
        }

        $subscription = $this->loadSubscription($subscriptionId);
        if ($subscription === null) {
            return $this->error('申购记录不存在');
        }
        if ((string)($subscription['status'] ?? '') !== 'ACTIVE') {
            return $this->error('仅 ACTIVE 申购可赎回');
        }

        $this->assign([
            'vo' => $this->formatSubscriptionRow($subscription),
        ]);
        return $this->fetch('redeem_form');
    }

    /**
     * @auth true
     */
    public function subscription_detail()
    {
        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $vo = $this->loadSubscription($id);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $this->assign('vo', $this->formatSubscriptionRow($vo));
        return $this->fetch('subscription_detail');
    }

    /**
     * 修改申购日期字段（运营纠错）
     *
     * @auth true
     */
    public function edit_subscription()
    {
        if ($this->request->isPost()) {
            $id = $this->sanitizeId($this->request->post('id'));
            if (!$id) {
                return $this->error('参数错误');
            }

            $existing = $this->loadSubscription($id);
            if ($existing === null) {
                return $this->error('申购记录不存在');
            }

            [$update, $err] = $this->parseSubscriptionDateUpdate($this->request->post());
            if ($err !== null) {
                return $this->error($err);
            }

            $update['updated_at'] = date('Y-m-d H:i:s');

            $affected = Db::connect('db_bitfinex')
                ->name('fund_client_product_subscription')
                ->where('id', $id)
                ->update($update);
            if ($affected < 1) {
                return $this->error('未更新任何记录');
            }

            return $this->success('申购日期已更新');
        }

        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $vo = $this->loadSubscription($id);
        if ($vo === null) {
            return $this->error('申购记录不存在');
        }

        $vo = $this->formatSubscriptionRow($vo);
        $vo = $this->attachSubscriptionDateFormValues($vo);

        $this->assign('vo', $vo);
        return $this->fetch('subscription_edit_form');
    }

    /**
     * @auth true
     */
    public function redemption_detail()
    {
        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $vo = $this->loadRedemption($id);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $this->assign('vo', $this->formatRedemptionRow($vo));
        return $this->fetch('redemption_detail');
    }

    /**
     * 删除赎回记录（仅 scheduled/pending/failed/cancelled，且无钱包流水）
     *
     * @auth true
     */
    public function remove_redemption()
    {
        $id = $this->sanitizeId($this->request->post('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $row = $this->loadRedemption($id);
        if ($row === null) {
            return $this->error('记录不存在');
        }

        if (!$this->isRedemptionDeletable($row)) {
            $status = (string)($row['status'] ?? '');
            if (!empty($row['wallet_flow_id'])) {
                return $this->error('已关联钱包流水，不可删除');
            }
            return $this->error("状态为「{$status}」的赎回记录不可删除");
        }

        $deleted = Db::connect('db_bitfinex')
            ->name('fund_client_product_redemption')
            ->where('id', $id)
            ->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败');
        }

        return $this->success('删除成功');
    }

    /**
     * @param array<string, mixed> $get
     */
    private function filteredSubscriptionQuery(array $get): Query
    {
        /** @var Query $query */
        $query = Db::connect('db_bitfinex')
            ->name('fund_client_product_subscription')
            ->alias('s')
            ->leftJoin('fund_client c', 'c.id = s.client_id')
            ->leftJoin('fund_products p', 'p.id = s.product_id')
            ->field('s.*, c.name AS client_name, p.product_name, p.product_type AS product_type');

        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween('s.subscribe_date', [$start, $end]);
        }

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('s.id', $id);
            }
        }

        if (!empty($get['client_id'])) {
            $clientId = $this->sanitizeId($get['client_id']);
            if ($clientId) {
                $query->where('s.client_id', $clientId);
            }
        }

        if (!empty($get['product_code'])) {
            $code = trim((string)$get['product_code']);
            if ($code !== '') {
                $query->whereLike('s.product_code', '%' . $this->sanitizeLike($code, 64) . '%');
            }
        }

        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getAllFundSubscriptionStatuses());
            if ($status) {
                $query->where('s.status', $status);
            }
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $get
     */
    private function filteredRedemptionQuery(array $get): Query
    {
        /** @var Query $query */
        $query = Db::connect('db_bitfinex')
            ->name('fund_client_product_redemption')
            ->alias('r')
            ->leftJoin('fund_client c', 'c.id = r.client_id')
            ->leftJoin('fund_products p', 'p.id = r.product_id')
            ->field('r.*, c.name AS client_name, p.product_name, p.product_type AS product_type');

        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween('r.requested_at', ["{$start} 00:00:00", "{$end} 23:59:59"]);
        }

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('r.id', $id);
            }
        }

        if (!empty($get['subscription_id'])) {
            $subscriptionId = $this->sanitizeId($get['subscription_id']);
            if ($subscriptionId) {
                $query->where('r.subscription_id', $subscriptionId);
            }
        }

        if (!empty($get['client_id'])) {
            $clientId = $this->sanitizeId($get['client_id']);
            if ($clientId) {
                $query->where('r.client_id', $clientId);
            }
        }

        if (!empty($get['redemption_type'])) {
            $type = $this->sanitizeEnum($get['redemption_type'], Enum::getAllFundRedemptionTypes());
            if ($type) {
                $query->where('r.redemption_type', $type);
            }
        }

        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getAllFundRedemptionStatuses());
            if ($status) {
                $query->where('r.status', $status);
            }
        }

        return $query;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSubscription(int $id): ?array
    {
        $row = Db::connect('db_bitfinex')
            ->name('fund_client_product_subscription')
            ->alias('s')
            ->leftJoin('fund_client c', 'c.id = s.client_id')
            ->leftJoin('fund_products p', 'p.id = s.product_id')
            ->field('s.*, c.name AS client_name, p.product_name, p.product_type AS product_type')
            ->where('s.id', $id)
            ->find();

        return empty($row) ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRedemption(int $id): ?array
    {
        $row = Db::connect('db_bitfinex')
            ->name('fund_client_product_redemption')
            ->alias('r')
            ->leftJoin('fund_client c', 'c.id = r.client_id')
            ->leftJoin('fund_products p', 'p.id = r.product_id')
            ->field('r.*, c.name AS client_name, p.product_name, p.product_type AS product_type')
            ->where('r.id', $id)
            ->find();

        return empty($row) ? null : $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatSubscriptionRow(array $row): array
    {
        $row['subscribe_amount_fmt']   = $this->trimDecimal($row['subscribe_amount'] ?? 0, 8);
        $row['floating_shares_fmt']    = $this->trimDecimal($row['floating_shares'] ?? 0, 10);
        $row['redeemable_amount_fmt']  = $this->trimDecimal($row['redeemable_amount'] ?? 0, 8);
        $row['current_interest_fmt']   = $this->trimDecimal($row['current_interest'] ?? 0, 8);
        $row['guaranteed_rate_fmt']    = $this->trimDecimal($row['guaranteed_rate_snapshot'] ?? 0, 10);
        $row['created_at']             = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at']             = $this->formatDatetime($row['updated_at'] ?? null);
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRedemptionRow(array $row): array
    {
        $typeLabels   = Enum::getFundRedemptionTypeLabels();
        $statusLabels = Enum::getFundRedemptionStatusLabels();

        $type   = (string)($row['redemption_type'] ?? '');
        $status = (string)($row['status'] ?? '');

        $row['redemption_type_label'] = $typeLabels[$type] ?? ($type !== '' ? $type : '-');
        $row['status_label']          = $statusLabels[$status] ?? ($status !== '' ? $status : '-');
        $row['redemption_amount_fmt'] = $this->trimDecimal($row['redemption_amount'] ?? 0, 8);
        $row['floating_request_shares_fmt'] = $this->trimDecimal($row['floating_request_shares'] ?? 0, 10);
        $row['requested_at']          = $this->formatDatetime($row['requested_at'] ?? null);
        $row['settled_at']            = $this->formatDatetime($row['settled_at'] ?? null);
        $row['created_at']            = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at']            = $this->formatDatetime($row['updated_at'] ?? null);
        $row['can_delete']            = $this->isRedemptionDeletable($row);
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isRedemptionDeletable(array $row): bool
    {
        $status = (string)($row['status'] ?? '');
        if (!in_array($status, Enum::getDeletableFundRedemptionStatuses(), true)) {
            return false;
        }
        $walletFlowId = (int)($row['wallet_flow_id'] ?? 0);
        return $walletFlowId <= 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadActiveClients(): array
    {
        return Db::connect('db_bitfinex')
            ->name('fund_client')
            ->where('status', 'active')
            ->order('id', 'asc')
            ->field('id, name')
            ->select()
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadActiveProducts(): array
    {
        return Db::connect('db_bitfinex')
            ->name('fund_products')
            ->where('status', 'active')
            ->orderRaw("FIELD(product_type, 'GUARANTEED', 'FLOATING'), product_code ASC")
            ->field('id, product_code, product_name, product_type, currency')
            ->select()
            ->toArray();
    }

    private function isActiveProductCode(string $productCode): bool
    {
        $cnt = (int)Db::connect('db_bitfinex')
            ->name('fund_products')
            ->where('product_code', $productCode)
            ->where('status', 'active')
            ->count();
        return $cnt > 0;
    }

    private function clientExists(int $clientId): bool
    {
        return Db::connect('db_bitfinex')->name('fund_client')->where('id', $clientId)->count() > 0;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function parseSubscriptionDateUpdate(array $post): array
    {
        $subscribeDate = $this->parseRequiredDateField($post['subscribe_date'] ?? '', '申购日期');
        if ($subscribeDate === false) {
            return [[], '申购日期格式无效，请使用 YYYY-MM-DD'];
        }
        if ($subscribeDate === null) {
            return [[], '申购日期必填'];
        }

        $startInterestDate = $this->parseRequiredDateField($post['start_interest_date'] ?? '', '计息开始日');
        if ($startInterestDate === false) {
            return [[], '计息开始日格式无效，请使用 YYYY-MM-DD'];
        }
        if ($startInterestDate === null) {
            return [[], '计息开始日必填'];
        }

        $endInterestDate = $this->parseOptionalDateField($post['end_interest_date'] ?? '');
        if ($endInterestDate === false) {
            return [[], '计息结束日格式无效，请使用 YYYY-MM-DD 或留空'];
        }

        $remainingDate = $this->parseOptionalDateField($post['remaining_date'] ?? '');
        if ($remainingDate === false) {
            return [[], '可赎回日格式无效，请使用 YYYY-MM-DD 或留空'];
        }

        $compoundDate = $this->parseOptionalDateField($post['compound_date'] ?? '');
        if ($compoundDate === false) {
            return [[], '复利续存日起息日格式无效，请使用 YYYY-MM-DD 或留空'];
        }

        return [[
            'subscribe_date'       => $subscribeDate,
            'start_interest_date'  => $startInterestDate,
            'end_interest_date'    => $endInterestDate,
            'remaining_date'       => $remainingDate,
            'compound_date'        => $compoundDate,
        ], null];
    }

    /**
     * @return string|null|false null=empty, false=invalid, string=Y-m-d
     */
    private function parseOptionalDateField($value)
    {
        $raw = trim((string)$value);
        if ($raw === '' || strcasecmp($raw, 'null') === 0 || $raw === '-') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return false;
        }
        return date('Y-m-d', $ts);
    }

    /**
     * @return string|null|false
     */
    private function parseRequiredDateField($value, string $label)
    {
        unset($label);
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return false;
        }
        return date('Y-m-d', $ts);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function attachSubscriptionDateFormValues(array $row): array
    {
        foreach (['subscribe_date', 'start_interest_date', 'end_interest_date', 'remaining_date', 'compound_date'] as $field) {
            $row[$field . '_edit'] = $this->formatDateFieldForInput($row[$field] ?? null);
        }
        $row['is_flexible_term'] = (int)($row['term_days'] ?? 0) === 0;
        return $row;
    }

    /**
     * @param mixed $value
     */
    private function formatDateFieldForInput($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $raw = trim((string)$value);
        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return '';
        }
        return strlen($raw) >= 10 ? substr($raw, 0, 10) : $raw;
    }

    private function parsePositiveDecimalString($value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $num = (float)$raw;
        if ($num <= 0) {
            return null;
        }
        return rtrim(rtrim(number_format($num, 8, '.', ''), '0'), '.') ?: '0';
    }
}
