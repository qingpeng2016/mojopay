<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\const\Enum;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * 3.5.4 递减规则参数（funding_config_layer：decay_idle_minutes / decay_rate_step）
 */
class DecayConfig extends Controller
{
    use SafeQueryTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '递减规则参数';

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

        $this->assign('list', $rows);
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

            $decayIdleMinutes = trim((string)$this->request->post('decay_idle_minutes', ''));
            $decayRateStep = trim((string)$this->request->post('decay_rate_step', ''));

            $err = $this->validateDecayIdleMinutes($decayIdleMinutes);
            if ($err !== null) {
                return $this->error($err);
            }

            $err = $this->validateDecayRateStep($decayRateStep);
            if ($err !== null) {
                return $this->error($err);
            }

            $affected = Db::connect('db_bitfinex')
                ->name('funding_config_layer')
                ->where('id', $id)
                ->where('symbol', $vo['symbol'])
                ->where('layer_code', $vo['layer_code'])
                ->update([
                    'decay_idle_minutes' => (int)$decayIdleMinutes,
                    'decay_rate_step'    => $decayRateStep,
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
        $row['layer_code_label'] = $labels[$code] ?? $code;
        $row['updated_at'] = !empty($row['updated_at'])
            ? date('Y-m-d H:i:s', strtotime((string)$row['updated_at'])) : '-';
        $row['decay_rate_step_fmt'] = rtrim(
            rtrim(number_format((float)($row['decay_rate_step'] ?? 0), 10, '.', ''), '0'),
            '.'
        );
        return $row;
    }

    private function validateDecayIdleMinutes(string $value): ?string
    {
        if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 0) {
            return '动态等待时间须为非负整数（分钟）';
        }
        return null;
    }

    private function validateDecayRateStep(string $value): ?string
    {
        if ($value === '' || !is_numeric($value) || (float)$value < 0) {
            return '递减步长须为非负数字';
        }
        return null;
    }
}
