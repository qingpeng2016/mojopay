<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\trait\RecordViewTrait;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\db\Query;
use think\facade\Db;

/**
 * 产品流动性曲线 fund_product_liquidity_daily（O(d)/G(d)/shortfall）
 */
class ProductLiquidityDaily extends Controller
{
    use SafeQueryTrait;
    use RecordViewTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '产品流动性曲线';
        $get         = $this->request->get();

        $result = $this->filteredQuery($get)
            ->order('a.calc_date', 'desc')
            ->order('a.liquidity_date', 'asc')
            ->order('a.id', 'desc')
            ->paginate([
                'list_rows' => isset($get['pageSize'])
                    ? min(100, max(10, intval($get['pageSize'])))
                    : 20,
                'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
                'query'     => $get,
                'path'      => '/admin.html#/bitfinex/product_liquidity_daily/index.html',
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

        $vo = $this->formatRow($row);
        $vo['calc_data_json_pretty'] = $this->prettyJson($row['calc_data_json'] ?? null);

        $this->assign('vo', $vo);
        return $this->fetch('detail');
    }

    /**
     * @auth true
     */
    public function remove()
    {
        $ids = $this->sanitizeIdList($this->request->post('id'));
        if ($ids === []) {
            return $this->error('参数错误');
        }

        $deleted = Db::connect('db_bitfinex')
            ->name('fund_product_liquidity_daily')
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
            ->name('fund_product_liquidity_daily')
            ->alias('a')
            ->leftJoin('fund_products p', 'p.id = a.product_id')
            ->field('a.*, p.product_name');

        if (!empty($get['calc_date_range']) && ($dates = $this->sanitizeDateRange((string)$get['calc_date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween('a.calc_date', [$start, $end]);
        }

        if (!empty($get['liquidity_date_range']) && ($dates = $this->sanitizeDateRange((string)$get['liquidity_date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween('a.liquidity_date', [$start, $end]);
        }

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('a.id', $id);
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

        if (isset($get['shortfall_only']) && (string)$get['shortfall_only'] === '1') {
            $query->where('a.shortfall', '>', 0);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRow(int $id): ?array
    {
        $row = Db::connect('db_bitfinex')
            ->name('fund_product_liquidity_daily')
            ->alias('a')
            ->leftJoin('fund_products p', 'p.id = a.product_id')
            ->field('a.*, p.product_name')
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
        $row['obligation_o_fmt']  = $this->trimDecimal($row['obligation_o'] ?? 0, 8);
        $row['return_g_fmt']      = $this->trimDecimal($row['return_g'] ?? 0, 8);
        $row['shortfall_fmt']     = $this->trimDecimal($row['shortfall'] ?? 0, 8);
        $row['calculated_at']      = $this->formatDatetime($row['calculated_at'] ?? null);
        $row['created_at']         = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at']         = $this->formatDatetime($row['updated_at'] ?? null);
        $row['has_shortfall']     = (float)($row['shortfall'] ?? 0) > 0;

        return $row;
    }

    /**
     * @param mixed $raw
     */
    private function prettyJson($raw): string
    {
        if ($raw === null || $raw === '') {
            return '{}';
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: $raw;
            }
            return $raw;
        }
        if (is_array($raw)) {
            return json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
        }
        return (string)$raw;
    }
}
