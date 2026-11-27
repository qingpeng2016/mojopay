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
 * 挂单记录（funding_orders，只读）
 */
class FundingOrder extends Controller
{
    use SafeQueryTrait;
    use RecordViewTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '挂单记录';
        $get = $this->request->get();

        $pageQuery = $this->filteredQuery($get)->order('created_at', 'desc')->order('id', 'desc');
        $result = $pageQuery->paginate([
            'list_rows' => isset($get['pageSize'])
                ? min(100, max(10, intval($get['pageSize'])))
                : 20,
            'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/bitfinex/funding_order/index.html',
        ], false);

        $list = $result->items();
        $labels = Enum::getLayerCodeLabels();
        foreach ($list as &$row) {
            $row = $this->formatRow($row, $labels);
        }
        unset($row);

        $this->assign([
            'list'       => $list,
            'pagehtml'   => $result->render(),
            'get'        => $get,
            'symbols'    => array_combine(Enum::getAllFundingSymbols(), Enum::getAllFundingSymbols()),
            'statuses'   => array_combine(Enum::getAllFundingOrderStatuses(), Enum::getAllFundingOrderStatuses()),
            'layer_codes'=> array_combine(Enum::getAllLayerCodes(), Enum::getAllLayerCodes()),
            'layer_labels' => $labels,
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
        $query = Db::connect('db_bitfinex')->name('funding_orders');
        $this->applyDateRangeFilter($query, $get, 'created_at');

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

        if (!empty($get['status'])) {
            $status = $this->sanitizeEnum($get['status'], Enum::getAllFundingOrderStatuses());
            if ($status) {
                $query->where('status', $status);
            }
        }

        if (!empty($get['layer_code'])) {
            $layerCode = $this->sanitizeEnum($get['layer_code'], Enum::getAllLayerCodes());
            if ($layerCode) {
                $query->where('layer_code', $layerCode);
            }
        }

        if (!empty($get['order_id'])) {
            $orderId = $this->sanitizeId($get['order_id']);
            if ($orderId) {
                $query->where('order_id', $orderId);
            }
        }

        if (!empty($get['snapshot_id'])) {
            $snapshotId = $this->sanitizeId($get['snapshot_id']);
            if ($snapshotId) {
                $query->where('snapshot_id', $snapshotId);
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
            ->name('funding_orders')
            ->where('id', $id)
            ->find();

        if (empty($row)) {
            return null;
        }

        return $this->formatRow($row, Enum::getLayerCodeLabels());
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $labels
     * @return array<string, mixed>
     */
    private function formatRow(array $row, array $labels): array
    {
        $code = (string)($row['layer_code'] ?? '');
        $row['layer_code_label'] = $labels[$code] ?? ($code !== '' ? $code : '-');
        $row['amount_usd_fmt'] = $this->trimDecimal($row['amount_usd'] ?? 0, 8);
        $row['rate_fmt'] = $this->trimDecimal($row['rate'] ?? 0, 10);
        $row['origin_rate_fmt'] = $this->trimDecimal($row['origin_rate'] ?? 0, 10);
        $row['decay_min_rate_fmt'] = $this->trimDecimal($row['decay_min_rate'] ?? 0, 10);
        $row['decay_base_at'] = $this->formatDatetime($row['decay_base_at'] ?? null);
        $row['funding_trade_ids_display'] = $this->jsonForDisplay($row['funding_trade_ids'] ?? null);
        $row['section12_json_display'] = $this->jsonForDisplay($row['section12_json'] ?? null);
        $row['section13_json_display'] = $this->jsonForDisplay($row['section13_json'] ?? null);
        $row['section14_json_display'] = $this->jsonForDisplay($row['section14_json'] ?? null);
        $row['request_json_display'] = $this->jsonForDisplay($row['request_json'] ?? null);
        $row['api_response_json_display'] = $this->jsonForDisplay($row['api_response_json'] ?? null);
        $row['created_at'] = $this->formatDatetime($row['created_at'] ?? null);
        $row['updated_at'] = $this->formatDatetime($row['updated_at'] ?? null);

        return $row;
    }
}
