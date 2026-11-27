<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\trait\RecordViewTrait;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\db\Query;
use think\facade\Db;

/**
 * 保底利率配置（market_trade_top_rates_avg）
 */
class FloorRateConfig extends Controller
{
    use SafeQueryTrait;
    use RecordViewTrait;

    /** 列表/表单字段（不含 id 展示、calc_data_json、created_at、updated_at、rates_total、rates_used） */
    private const LIST_FIELDS = [
        'id', // 仅供修改入口
        'product_code',
        'symbol',
        'target_period_days',
        'period_days',
        'lookback_hours',
        'top_n',
        'avg_rate',
        'placement_rate_floor',
    ];

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '保底利率配置';
        $get         = $this->request->get();

        $result = $this->filteredQuery($get)
            ->order('symbol', 'asc')
            ->order('target_period_days', 'asc')
            ->paginate([
                'list_rows' => isset($get['pageSize'])
                    ? min(100, max(10, intval($get['pageSize'])))
                    : 50,
                'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
                'query'     => $get,
                'path'      => '/admin.html#/bitfinex/floor_rate_config/index.html',
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
            'symbols'  => $this->loadSymbolOptions(),
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

            $periodDays = $this->parsePositiveIntJsonArray(
                trim((string)$this->request->post('period_days', '')),
                'period_days'
            );
            if (is_string($periodDays)) {
                return $this->error($periodDays);
            }

            $lookbackHours = $this->parsePositiveIntJsonArray(
                trim((string)$this->request->post('lookback_hours', '')),
                'lookback_hours'
            );
            if (is_string($lookbackHours)) {
                return $this->error($lookbackHours);
            }

            $topN = $this->parsePositiveIntJsonArray(
                trim((string)$this->request->post('top_n', '')),
                'top_n'
            );
            if (is_string($topN)) {
                return $this->error($topN);
            }

            if (count($lookbackHours) !== count($topN)) {
                return $this->error('lookback_hours 与 top_n 长度必须一致');
            }

            $floor = trim((string)$this->request->post('placement_rate_floor', ''));
            $err   = $this->validateFloorRate($floor);
            if ($err !== null) {
                return $this->error($err);
            }

            $affected = Db::connect('db_bitfinex')
                ->name('market_trade_top_rates_avg')
                ->where('id', $id)
                ->update([
                    'period_days'          => json_encode(array_values($periodDays), JSON_UNESCAPED_UNICODE),
                    'lookback_hours'       => json_encode(array_values($lookbackHours), JSON_UNESCAPED_UNICODE),
                    'top_n'                => json_encode(array_values($topN), JSON_UNESCAPED_UNICODE),
                    'placement_rate_floor' => $this->normalizeFloatValue($floor),
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
            ->name('market_trade_top_rates_avg')
            ->field(implode(',', self::LIST_FIELDS));

        if (!empty($get['symbol'])) {
            $symbol = $this->sanitizeLike((string)$get['symbol'], 32);
            if ($symbol !== '') {
                $query->whereLike('symbol', "%{$symbol}%");
            }
        }

        if (!empty($get['product_code'])) {
            $code = $this->sanitizeLike((string)$get['product_code'], 64);
            if ($code !== '') {
                $query->whereLike('product_code', "%{$code}%");
            }
        }

        if (isset($get['target_period_days']) && $get['target_period_days'] !== '') {
            $period = (int)$get['target_period_days'];
            if ($period > 0) {
                $query->where('target_period_days', $period);
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
            ->name('market_trade_top_rates_avg')
            ->where('id', $id)
            ->field(implode(',', self::LIST_FIELDS))
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
        $floor = (string)($row['placement_rate_floor'] ?? '0');

        $row['placement_rate_floor_fmt']     = $this->trimDecimal($floor, 10);
        $row['placement_rate_floor_display'] = $this->trimDecimal($floor, 10);
        $row['annual_rate_fmt']              = $this->formatAnnualFromDaily($floor);
        $row['period_days_fmt']              = $this->formatJsonCell($row['period_days'] ?? null);
        $row['lookback_hours_fmt']           = $this->formatJsonCell($row['lookback_hours'] ?? null);
        $row['top_n_fmt']                    = $this->formatJsonCell($row['top_n'] ?? null);
        $row['avg_rate_fmt']                 = $this->formatAvgRateCell($row['avg_rate'] ?? null);

        // 表单编辑框用紧凑 JSON 文本
        $row['period_days_edit']    = $this->formatJsonCell($row['period_days'] ?? null);
        $row['lookback_hours_edit'] = $this->formatJsonCell($row['lookback_hours'] ?? null);
        $row['top_n_edit']          = $this->formatJsonCell($row['top_n'] ?? null);

        return $row;
    }

    /**
     * @return list<string>
     */
    private function loadSymbolOptions(): array
    {
        $rows = Db::connect('db_bitfinex')
            ->name('market_trade_top_rates_avg')
            ->distinct(true)
            ->order('symbol', 'asc')
            ->column('symbol');

        $out = [];
        foreach ($rows as $symbol) {
            $symbol = trim((string)$symbol);
            if ($symbol !== '') {
                $out[] = $symbol;
            }
        }

        return $out;
    }

    private function validateFloorRate(string $value): ?string
    {
        if ($value === '' || !is_numeric($value)) {
            return 'placement_rate_floor 必须是数字';
        }
        $val = (float)$value;
        if ($val < 0) {
            return 'placement_rate_floor 不能为负数';
        }
        if ($val > 1) {
            return 'placement_rate_floor 过大（日利率通常远小于 1，例如 0.0003001）';
        }

        return null;
    }

    /**
     * 解析正整数 JSON 数组；失败返回错误文案
     *
     * @return list<int>|string
     */
    private function parsePositiveIntJsonArray(string $raw, string $label)
    {
        if ($raw === '') {
            return "{$label} 不能为空";
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            return "{$label} 必须是非空 JSON 数组，例如 [2,4,6]";
        }

        $out = [];
        foreach ($decoded as $item) {
            if (is_bool($item) || is_array($item) || (!is_int($item) && !is_float($item) && !is_string($item))) {
                return "{$label} 元素必须是正整数";
            }
            if (!is_numeric($item) || (float)$item != (int)$item || (int)$item <= 0) {
                return "{$label} 元素必须是正整数";
            }
            $out[] = (int)$item;
        }

        return $out;
    }

    private function normalizeFloatValue(string $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.10F', (float)$value), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function formatAnnualFromDaily(string $daily): string
    {
        if (!is_numeric($daily)) {
            return '-';
        }

        return $this->trimDecimal((float)$daily * 365 * 100, 4) . '%';
    }

    private function formatJsonCell($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        if (is_array($value)) {
            $encoded = json_encode(array_values($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded !== false ? $encoded : '-';
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return '-';
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $encoded = json_encode(array_values($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded !== false ? $encoded : $raw;
        }

        return $raw;
    }

    /**
     * avg_rate 展示：每个数值保留 7 位小数
     */
    private function formatAvgRateCell($value): string
    {
        $items = null;
        if (is_array($value)) {
            $items = $value;
        } else {
            $raw = trim((string)($value ?? ''));
            if ($raw === '') {
                return '-';
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return $raw;
            }
            $items = $decoded;
        }

        if ($items === []) {
            return '[]';
        }

        $formatted = [];
        foreach ($items as $item) {
            if ($item === null || $item === '') {
                $formatted[] = '0.0000000';
                continue;
            }
            if (!is_numeric($item)) {
                $formatted[] = (string)$item;
                continue;
            }
            $formatted[] = number_format((float)$item, 7, '.', '');
        }

        $encoded = json_encode($formatted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : '-';
    }
}
