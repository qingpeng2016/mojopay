<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\const\Enum;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 3.5.3 资金分层参数（funding_config_layer）
 */
class LayerConfig extends Controller
{
    use SafeQueryTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '资金分层参数';

        $rows = Db::connect('db_bitfinex')
            ->name('funding_config_layer')
            ->whereIn('symbol', Enum::getAllFundingSymbols())
            ->whereIn('layer_code', Enum::getAllLayerCodes())
            ->orderRaw("symbol ASC, FIELD(layer_code, 'conservative', 'balanced', 'aggressive')")
            ->select()
            ->toArray();

        $labels = Enum::getLayerCodeLabels();
        foreach ($rows as &$row) {
            $row = $this->formatRow($row, $labels);
        }
        unset($row);

        $this->assign([
            'list'          => $rows,
            'layer_labels'  => $labels,
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

            $weightRatio = trim((string)$this->request->post('weight_ratio', ''));
            $rateMultipliersRaw = trim((string)$this->request->post('rate_multipliers', ''));
            $periodDaysRaw = trim((string)$this->request->post('period_days', ''));

            $err = $this->validateWeightRatio($weightRatio);
            if ($err !== null) {
                return $this->error($err);
            }

            [$rateMultipliersJson, $err] = $this->parseRateMultipliers($rateMultipliersRaw);
            if ($err !== null) {
                return $this->error($err);
            }

            [$periodDaysJson, $err] = $this->parsePeriodDays($periodDaysRaw);
            if ($err !== null) {
                return $this->error($err);
            }

            $affected = Db::connect('db_bitfinex')
                ->name('funding_config_layer')
                ->where('id', $id)
                ->where('symbol', $vo['symbol'])
                ->where('layer_code', $vo['layer_code'])
                ->update([
                    'weight_ratio'     => $weightRatio,
                    'rate_multipliers' => $rateMultipliersJson,
                    'period_days'      => $periodDaysJson,
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
     * @return array<string, mixed>|null
     */
    private function loadRow(int $id): ?array
    {
        $row = Db::connect('db_bitfinex')
            ->name('funding_config_layer')
            ->where('id', $id)
            ->whereIn('symbol', Enum::getAllFundingSymbols())
            ->whereIn('layer_code', Enum::getAllLayerCodes())
            ->find();

        if (empty($row)) {
            return null;
        }

        return $this->formatRow($row, Enum::getLayerCodeLabels(), true);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $labels
     * @return array<string, mixed>
     */
    private function formatRow(array $row, array $labels, bool $forForm = false): array
    {
        $code = (string)($row['layer_code'] ?? '');
        $row['layer_code_label'] = $labels[$code] ?? $code;

        $row['updated_at'] = !empty($row['updated_at'])
            ? date('Y-m-d H:i:s', strtotime((string)$row['updated_at'])) : '-';

        $row['weight_ratio_fmt'] = number_format((float)($row['weight_ratio'] ?? 0), 4, '.', '');

        $rateRaw = $row['rate_multipliers'] ?? '[]';
        if (is_array($rateRaw)) {
            $rateRaw = json_encode($rateRaw, JSON_UNESCAPED_UNICODE);
        }
        $row['rate_multipliers_raw'] = (string)$rateRaw;
        $row['rate_multipliers_display'] = $this->prettyJson((string)$rateRaw, $forForm);

        $periodRaw = $row['period_days'] ?? '[]';
        if (is_array($periodRaw)) {
            $periodRaw = json_encode($periodRaw, JSON_UNESCAPED_UNICODE);
        }
        $row['period_days_raw'] = (string)$periodRaw;
        $row['period_days_display'] = $this->prettyJson((string)$periodRaw, $forForm);

        return $row;
    }

    private function prettyJson(string $json, bool $multiline): string
    {
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $json;
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($multiline) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return (string)json_encode($decoded, $flags);
    }

    private function validateWeightRatio(string $value): ?string
    {
        if ($value === '' || !is_numeric($value)) {
            return '占比必须是数字';
        }
        $f = (float)$value;
        if ($f < 0 || $f > 9.9999) {
            return '占比须在 0～9.9999 之间';
        }
        return null;
    }

    /**
     * @return array{0:?string,1:?string} [json, error]
     */
    private function parseRateMultipliers(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [null, 'rate_multipliers 必须是合法 JSON 数组，如 [0.96, 0.98, 1.0]'];
        }
        if (!is_array($decoded) || !$this->isListArray($decoded)) {
            return [null, 'rate_multipliers 必须是 JSON 数组'];
        }
        foreach ($decoded as $v) {
            if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
                return [null, 'rate_multipliers 数组元素必须是数字'];
            }
        }
        return [json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null];
    }

    /**
     * @return array{0:?string,1:?string} [json, error]
     */
    private function parsePeriodDays(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [null, 'period_days 必须是合法 JSON'];
        }
        if (!is_array($decoded)) {
            return [null, 'period_days 格式无效'];
        }

        if (isset($decoded['splits']) && is_array($decoded['splits'])) {
            $items = $decoded['splits'];
            $wrapSplits = true;
        } elseif ($this->isListArray($decoded)) {
            $items = $decoded;
            $wrapSplits = false;
        } else {
            return [null, 'period_days 须为 [{"days":2,"weight":0.5},...] 或 {"splits":[...]}'];
        }

        foreach ($items as $item) {
            if (!is_array($item) || !array_key_exists('days', $item) || !array_key_exists('weight', $item)) {
                return [null, 'period_days 每项须包含 days 与 weight'];
            }
            if (!is_numeric($item['days']) || !is_numeric($item['weight'])) {
                return [null, 'period_days 的 days/weight 必须是数字'];
            }
            if ((int)$item['days'] <= 0) {
                return [null, 'period_days 的 days 须为正整数'];
            }
        }

        $normalized = $wrapSplits ? ['splits' => $items] : $items;
        return [json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null];
    }

    private function isListArray(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
