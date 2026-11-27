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
 * 客户申购日计提 fund_client_subscription_accrual_daily
 */
class SubscriptionAccrualDaily extends Controller
{
    use SafeQueryTrait;
    use RecordViewTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '客户日计提明细';
        $get         = $this->request->get();

        $result = $this->filteredQuery($get)
            ->order('a.accrual_date', 'desc')
            ->order('a.id', 'desc')
            ->paginate([
                'list_rows' => isset($get['pageSize'])
                    ? min(100, max(10, intval($get['pageSize'])))
                    : 20,
                'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
                'query'     => $get,
                'path'      => '/admin.html#/bitfinex/subscription_accrual_daily/index.html',
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
            'statuses' => Enum::getAllFundDailyCalcStatuses(),
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

        $row = $this->loadRow($id);
        if ($row === null) {
            return $this->error('记录不存在');
        }

        $this->assign('vo', $this->formatRow($row));
        return $this->fetch('detail');
    }

    /**
     * 删除计提记录（支持单条 id 或逗号分隔批量 id）
     *
     * @auth true
     */
    public function remove()
    {
        $ids = $this->sanitizeIdList($this->request->post('id'));
        if ($ids === []) {
            return $this->error('参数错误');
        }

        $deleted = Db::connect('db_bitfinex')
            ->name('fund_client_subscription_accrual_daily')
            ->whereIn('id', $ids)
            ->delete();
        if ($deleted <= 0) {
            return $this->error('删除失败');
        }

        return $this->success($deleted > 1 ? sprintf('已删除 %d 条记录', $deleted) : '删除成功');
    }

    /**
     * @param array<string, mixed> $get
     */
    private function filteredQuery(array $get): Query
    {
        /** @var Query $query */
        $query = Db::connect('db_bitfinex')
            ->name('fund_client_subscription_accrual_daily')
            ->alias('a')
            ->leftJoin('fund_client c', 'c.id = a.client_id')
            ->leftJoin('fund_products p', 'p.id = a.product_id')
            ->field('a.*, c.name AS client_name, p.product_name');

        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween('a.accrual_date', [$start, $end]);
        }

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('a.id', $id);
            }
        }

        if (!empty($get['subscription_id'])) {
            $subscriptionId = $this->sanitizeId($get['subscription_id']);
            if ($subscriptionId) {
                $query->where('a.subscription_id', $subscriptionId);
            }
        }

        if (!empty($get['client_id'])) {
            $clientId = $this->sanitizeId($get['client_id']);
            if ($clientId) {
                $query->where('a.client_id', $clientId);
            }
        }

        if (!empty($get['product_id'])) {
            $productId = $this->sanitizeId($get['product_id']);
            if ($productId) {
                $query->where('a.product_id', $productId);
            }
        }

        if (!empty($get['product_code'])) {
            $code = trim((string)$get['product_code']);
            if ($code !== '') {
                $query->whereLike('a.product_code', '%' . $this->sanitizeLike($code, 64) . '%');
            }
        }

        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getAllFundDailyCalcStatuses());
            if ($status) {
                $query->where('a.status', $status);
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
            ->name('fund_client_subscription_accrual_daily')
            ->alias('a')
            ->leftJoin('fund_client c', 'c.id = a.client_id')
            ->leftJoin('fund_products p', 'p.id = a.product_id')
            ->field('a.*, c.name AS client_name, p.product_name')
            ->where('a.id', $id)
            ->find();

        return empty($row) ? null : $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRow(array $row): array
    {
        $row['interest_day_amount_fmt']      = $this->trimDecimal($row['interest_day_amount'] ?? 0, 10);
        $row['guaranteed_principal_base_fmt']= $this->trimDecimal($row['guaranteed_principal_base'] ?? 0, 8);
        $row['guaranteed_annual_rate_fmt']   = $this->trimDecimal($row['guaranteed_annual_rate'] ?? 0, 10);
        $row['floating_shares_base_fmt']     = $this->trimDecimal($row['floating_shares_base'] ?? 0, 10);
        $row['floating_nav_per_share_fmt']   = $this->trimDecimal($row['floating_nav_per_share'] ?? 0, 10);
        $row['floating_market_value_fmt']    = $this->trimDecimal($row['floating_market_value'] ?? 0, 8);
        $row['calculated_at']                = $this->formatDatetime($row['calculated_at'] ?? null);
        $row['created_at']                   = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at']                   = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}
