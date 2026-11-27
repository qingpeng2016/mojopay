<?php
declare(strict_types=1);

namespace app\backtest\service;

final class SportsSnapshotPresenter
{
    private const JSON_FIELDS = [
        'in_sources', 'in_sources_reference', 'yes_bids', 'yes_asks', 'no_bids', 'no_asks', 'alerts',
        'input_payload', 'output_payload', 'pricing_runtime_state', 'micro_position_distribution',
        'diag_state_old_sources',
    ];

    public function present(array $row): array
    {
        foreach (self::JSON_FIELDS as $field) {
            $rawValue = $row[$field] ?? '';
            $raw = is_string($rawValue) ? $rawValue : (is_array($rawValue) ? json_encode($rawValue, JSON_UNESCAPED_UNICODE) : (string)$rawValue);
            $row[$field . '_raw'] = $raw;
            [$row[$field], $row[$field . '_invalid']] = $this->decode($rawValue);
        }
        // Older rows may have retained the complete SO response in
        // output_payload while the denormalized yes_*/no_* columns were not
        // populated. Use the response as a read-only fallback for details.
        $this->hydrateBooksFromPayload($row);
        foreach (['yes', 'no'] as $book) {
            $row[$book . '_asks'] = $this->sortLevels($row[$book . '_asks'] ?? [], true);
            $row[$book . '_bids'] = $this->sortLevels($row[$book . '_bids'] ?? [], false);
        }
        foreach (['calc_time', 'created_at', 'updated_at'] as $field) {
            if (!empty($row[$field])) {
                $row[$field] = date('Y-m-d H:i:s', strtotime((string)$row[$field]));
            }
        }
        foreach ($row as $field => $value) {
            if (is_numeric($value) && !str_ends_with($field, '_best') && !in_array($field, ['id', 'sports_config_id', 'sports_market_id', 'input_timestamp_ms', 'output_timestamp_ms', 'calc_seq'], true)) {
                $row[$field] = $this->trimZero($value);
            }
        }
        // 兜底：保证输入/诊断标签对应的键都存在，避免底层表缺列时详情页直接 fatal。
        // 缺失的 JSON 类键补空数组（详情“来源数据”按数组遍历），其余补空串。
        $jsonKeys = ['in_sources', 'pricing_runtime_state', 'micro_position_distribution'];
        foreach (array_keys($this->inputLabels()) as $key) {
            if (!array_key_exists($key, $row)) {
                $row[$key] = in_array($key, $jsonKeys, true) ? [] : '';
            }
        }
        foreach (array_keys($this->diagnosticLabels()) as $key) {
            if (!array_key_exists($key, $row)) {
                $row[$key] = '';
            }
        }
        return $row;
    }

    private function hydrateBooksFromPayload(array &$row): void
    {
        if (!is_array($row['output_payload'] ?? null)) {
            return;
        }
        $payload = $row['output_payload'];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        foreach (['yes', 'no'] as $side) {
            $book = is_array($data[$side] ?? null) ? $data[$side] : [];
            foreach (['bids', 'asks'] as $kind) {
                $column = $side . '_' . $kind;
                if ((!isset($row[$column]) || !is_array($row[$column]) || !$row[$column]) && is_array($book[$kind] ?? null)) {
                    $row[$column] = $book[$kind];
                }
            }
        }
    }

    public function presentList(array $row): array
    {
        foreach (['yes_bids', 'yes_asks', 'no_bids', 'no_asks'] as $field) {
            [$levels] = $this->decode($row[$field] ?? '');
            $levels = $this->sortLevels($levels, str_ends_with($field, 'asks'));
            $row[$field . '_best'] = $this->bestPrice($levels, str_ends_with($field, 'asks'));
        }
        foreach (['calc_time', 'updated_at'] as $field) {
            if (!empty($row[$field])) {
                $row[$field] = date('Y-m-d H:i:s', strtotime((string)$row[$field]));
            }
        }
        foreach ($row as $field => $value) {
            if (is_numeric($value) && !str_ends_with($field, '_best') && !in_array($field, ['id', 'sports_config_id', 'sports_market_id', 'input_timestamp_ms', 'output_timestamp_ms', 'calc_seq'], true)) {
                $row[$field] = $this->trimZero($value);
            }
        }
        return $row;
    }

    public function inputLabels(): array
    {
        return [
            'match_total_sec' => '比赛总时长（秒）', 'match_remaining_sec' => '比赛剩余秒数',
            'match_score_difference' => '比分差', 'event_state' => '比赛事件',
            'match_home_score' => '主队比分', 'match_away_score' => '客队比分',
            'match_status' => '比赛状态', 'match_progress' => '比赛进度',
            'in_sources' => '来源快照',
            'micro_v_bid_5s_ago' => '5 秒前买量', 'micro_v_bid_now' => '当前买量',
            'micro_v_ask_5s_ago' => '5 秒前卖量', 'micro_v_ask_now' => '当前卖量',
            'micro_v_buy' => '成交买量', 'micro_v_sell' => '成交卖量',
            'micro_v_aggressive' => '主动成交量', 'micro_v_passive' => '被动成交量',
            'micro_position_distribution' => '仓位分布', 'slippage_estimate' => '滑点估计',
            'inventory_q' => '库存 Q', 'inventory_q_cap' => '库存 Q 上限',
            'pricing_runtime_state' => '定价续算状态',
        ];
    }

    public function diagnosticLabels(): array
    {
        return [
            'diag_source_count' => '来源类别数', 'diag_built_at' => '构建时间',
            'diag_data_confidence' => '数据置信度', 'diag_state_sample_at_ms' => '出参状态采样时间(ms)', 'diag_reference_spread' => '参考点差',
            'diag_fair_probability' => '公平概率', 'diag_base_spread' => '基础点差',
            'diag_divergence_spread' => '分歧点差', 'diag_decay_spread' => '衰减点差',
            'diag_spread_before_limits' => '限幅前点差', 'diag_dynamic_spread_floor' => '动态点差下限',
            'diag_dynamic_spread_cap' => '动态点差上限', 'diag_spread1' => '基础动态点差',
            'diag_apr_signal' => 'APR 信号', 'diag_micro_price_shift' => '微观价格偏移',
            'diag_vpin_multiplier' => 'VPIN 乘数', 'diag_hhi_multiplier' => 'HHI 乘数',
            'diag_apr_multiplier' => 'APR 乘数', 'diag_micro_multiplier' => '微观乘数',
            'diag_target_probability' => '目标概率', 'diag_spread2' => '微观调整后点差',
            'diag_volatility' => '波动率', 'diag_inventory_q_cap' => '库存 Q 上限',
            'diag_inventory_usage' => '库存使用率', 'diag_inventory_offset' => '库存偏移',
            'diag_inventory_kappa' => '库存 Kappa', 'diag_inventory_factor' => '库存因子',
            'diag_risk_damping' => '风险阻尼', 'diag_event_factor' => '事件因子',
            'diag_asymmetry' => '不对称度', 'diag_order_size_factor' => '下单量因子',
            'diag_hedge_required' => '是否需要对冲', 'diag_slippage_estimate' => '滑点估计',
            'diag_one_side_spread_floor' => '单边点差下限',
        ];
    }

    private function decode($raw): array
    {
        if (is_array($raw)) return [$raw, false];
        if ($raw === '') {
            return [[], false];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? [$decoded, false] : [[], true];
    }

    private function sortLevels(array $levels, bool $asks): array
    {
        $levels = array_values(array_filter($levels, static fn($v) => is_array($v)));
        usort($levels, static function ($a, $b) use ($asks) {
            $cmp = (float)($b['price'] ?? 0) <=> (float)($a['price'] ?? 0);
            return $cmp;
        });
        return $levels;
    }

    private function bestPrice(array $levels, bool $asks): string
    {
        if (!$levels) {
            return '-';
        }
        $best = $levels[0];
        foreach ($levels as $level) {
            if ($asks && (float)$level['price'] < (float)$best['price']) {
                $best = $level;
            } elseif (!$asks && (float)$level['price'] > (float)$best['price']) {
                $best = $level;
            }
        }
        return (string)($best['price'] ?? '-');
    }

    private function trimZero($value): string
    {
        $text = (string)$value;
        if (!str_contains($text, '.')) {
            return $text;
        }
        $text = rtrim(rtrim($text, '0'), '.');
        return in_array($text, ['', '-', '-0'], true) ? '0' : $text;
    }
}
