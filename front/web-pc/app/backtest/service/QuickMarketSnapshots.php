<?php
declare(strict_types=1);

namespace app\backtest\service;

use think\facade\Db;

/** Maps the V2 payloads to the column names used by the existing quick-market views and exports. */
final class QuickMarketSnapshots
{
    public const SOURCES = [
        'in_pricing_source_platform', 'in_pricing_source_type',
        'in_settlement_source_platform', 'in_settlement_source_type',
    ];

    private const BUSINESS_FIELDS = [
        'id', 'f_config_id', 'f_market_id', 'market_no', 'symbol', 'machine_name',
        'open_time', 'expiry_time', 'settle_result', 'settle_price',
        'calc_seq', 'calc_time', 'calc_code', 'calc_msg', 'calc_cost_us', 'created_at', 'updated_at',
    ];

    // JSON keys with uppercase letters in the SO contract.
    private const COMMON_DATA_KEYS = [
        's_adj' => 'S_adj', 's_eff' => 'S_eff', 'p_up' => 'P_up',
        'p_eff_up' => 'P_eff_up', 'p_eff_down' => 'P_eff_down',
        'p0_ask' => 'P0_ask', 'p0_bid' => 'P0_bid',
        'p0_down_ask' => 'P0_down_ask', 'p0_down_bid' => 'P0_down_bid',
        'q_top' => 'Q_top', 'q_wait' => 'Q_wait', 'q' => 'Q',
    ];

    public static function marketQuery()
    {
        return Db::name('f_market_v2')->alias('m')
            ->leftJoin('f_placement_v2 success', 'success.f_market_id = m.id AND success.calc_seq = m.last_success_calc_seq');
    }

    public static function placementQuery()
    {
        // For a failed frame, use the preceding successful frame from the same market.
        return Db::name('f_placement_v2')->alias('m')
            ->leftJoin('f_placement_v2 success', 'success.f_market_id = m.f_market_id AND success.calc_seq = (
                SELECT prev.calc_seq
                FROM f_placement_v2 AS prev
                WHERE prev.f_market_id = m.f_market_id AND prev.calc_seq < m.calc_seq
                  AND prev.calc_code = 0
                  AND (JSON_TYPE(JSON_EXTRACT(prev.output_payload, \'$.data\')) <=> \'OBJECT\')
                ORDER BY prev.calc_seq DESC LIMIT 1
            ) AND NOT ' . self::hasQuoteOutput('m'));
    }

    public static function selectFields($query, array $fields, bool $placement): void
    {
        $projections = [];
        foreach (array_unique($fields) as $field) {
            $projections[] = self::expression($field, $placement) . ' AS `' . $field . '`';
        }
        $query->fieldRaw(implode(', ', $projections));
    }

    public static function expression(string $field, bool $placement): string
    {
        if (in_array($field, self::BUSINESS_FIELDS, true)) {
            return 'm.' . $field;
        }
        if ($field === 'status') {
            return 'm.market_status';
        }
        if ($field === 'in_duration') {
            return 'm.duration';
        }
        if (in_array($field, self::SOURCES, true)) {
            return 'm.' . substr($field, 3);
        }
        if (!$placement) {
            $controls = ['in_k' => 'locked_open_price', 'in_q' => 'next_q', 'in_q_cap' => 'next_q_cap'];
            if (isset($controls[$field])) {
                return 'm.' . $controls[$field];
            }
        }
        if ($field === 'in_d') {
            return 'TIMESTAMPDIFF(SECOND, m.open_time, m.expiry_time)';
        }
        if (str_starts_with($field, 'in_')) {
            $value = self::inputValue($field);
            if ($placement) {
                return $value;
            }
            $fallback = in_array($field, ['in_s', 'in_ds_s', 'in_l', 'in_tau', 'in_iv_strike_lo',
                'in_iv_strike_hi', 'in_iv_lo', 'in_iv_hi'], true) ? '0' : 'NULL';
            return "CASE WHEN m.input_payload IS NULL THEN $fallback ELSE $value END";
        }

        $path = self::outputPath($field);
        $default = self::outputDefault($field);
        $fallback = $default ?? 'NULL';
        $success = self::outputValue('success.output_payload', $path, $field);
        if (!$placement) {
            return "CASE WHEN success.id IS NULL THEN $fallback ELSE $success END";
        }
        $current = self::outputValue('m.output_payload', $path, $field);
        $hasOutput = self::hasQuoteOutput('m');
        return "CASE WHEN $hasOutput THEN $current WHEN success.id IS NOT NULL THEN $success ELSE $fallback END";
    }

    private static function outputPath(string $field): string
    {
        if (str_starts_with($field, 'cd_')) {
            $key = substr($field, 3);
            return 'data.commonData.' . (self::COMMON_DATA_KEYS[$key] ?? $key);
        }
        if (str_starts_with($field, 'st_')) {
            return 'data.state.' . substr($field, 3);
        }
        if (in_array($field, ['up_bids', 'up_asks', 'down_bids', 'down_asks'], true)) {
            return 'data.' . str_replace('_', '.', $field);
        }
        throw new \InvalidArgumentException('Unknown quick-market column: ' . $field);
    }

    private static function hasQuoteOutput(string $alias): string
    {
        return "($alias.calc_code = 0 AND (JSON_TYPE(JSON_EXTRACT($alias.output_payload, '$.data')) <=> 'OBJECT'))";
    }

    // The existing table and CSV views use V1 numeric precision. The raw JSON blocks
    // read the payload columns directly and keep the complete values.
    private static function inputValue(string $field): string
    {
        $path = substr($field, 3);
        if (in_array($field, ['in_s_history', 'in_basis_samples'], true)) {
            return "JSON_UNQUOTE(JSON_EXTRACT(m.input_payload, '$.$path'))";
        }
        $value = self::jsonValue('m.input_payload', $path);
        $type = match ($field) {
            'in_d', 'in_tau' => 'DECIMAL(18,6)',
            'in_l' => 'DECIMAL(18,8)',
            'in_s', 'in_ds_s', 'in_k', 'in_q', 'in_q_cap', 'in_iv_strike_lo', 'in_iv_strike_hi' => 'DECIMAL(28,8)',
            'in_iv_lo', 'in_iv_hi', 'in_sigma_realized_old', 'in_basis_ema_old', 'in_sigma_realized_fast_old',
            'in_sigma_realized_60_old', 'in_sigma_realized_900_old' => 'DECIMAL(28,18)',
            default => null,
        };
        return $type === null ? $value : "CAST($value AS $type)";
    }

    private static function outputDefault(string $field): ?string
    {
        return match ($field) {
            'cd_trace_id', 'cd_quote_mode', 'cd_sigma_blend_mode', 'cd_pm_dense_n_all' => "''",
            'up_asks', 'up_bids', 'down_asks', 'down_bids', 'cd_epsilon_samples', 'cd_pm_q_add_thresholds',
            'st_sigma_realized_60', 'st_sigma_realized_900', 'st_edge_mode', 'st_edge_since', 'st_away_since' => null,
            default => '0',
        };
    }

    private static function outputValue(string $column, string $path, string $field): string
    {
        if (in_array($field, ['up_asks', 'up_bids', 'down_asks', 'down_bids', 'cd_epsilon_samples',
            'cd_pm_q_add_thresholds'], true)) {
            return "COALESCE(JSON_UNQUOTE(JSON_EXTRACT($column, '$.$path')), 'null')";
        }
        $value = self::jsonValue($column, $path);
        if (in_array($field, ['cd_edge_triggered', 'cd_over_cap', 'cd_sigma_fallback_used'], true)) {
            return "CASE WHEN $value = 'true' THEN 1 ELSE 0 END";
        }
        $default = self::outputDefault($field);
        if ($default !== null) {
            $value = "COALESCE($value, $default)";
        }
        if ($field === 'cd_trace_id') {
            return "CONVERT($value USING utf8mb4) COLLATE utf8mb4_unicode_ci";
        }
        if (($default === '0' && !in_array($field, ['cd_q_top', 'cd_edge_now_ms', 'cd_computed_at_unix_ms',
            'cd_compute_duration_us', 'cd_pm_sigma_return_lag_sec', 'cd_pm_sigma_history_size',
            'cd_pm_sigma_warmup_min_samples'], true)) || in_array($field, ['st_sigma_realized_60', 'st_sigma_realized_900'], true)) {
            return "CAST($value AS DECIMAL(28,18))";
        }
        return $value;
    }

    private static function jsonValue(string $column, string $path): string
    {
        $value = "JSON_EXTRACT($column, '$.$path')";
        return "JSON_UNQUOTE(NULLIF($value, CAST('null' AS JSON)))";
    }

    public static function includePayloads($query): void
    {
        $query->fieldRaw('m.input_payload, m.output_payload');
    }

    public static function stream($query, string $idColumn, string $direction, int $batchSize, callable $consume): void
    {
        $cursor = null;
        do {
            $batch = clone $query;
            if ($cursor !== null) {
                $batch->where($idColumn, $direction === 'asc' ? '>' : '<', $cursor);
            }
            $rows = $batch->order($idColumn, $direction)->limit($batchSize)->select()->toArray();
            if ($rows) {
                $consume($rows);
                $cursor = $rows[count($rows) - 1]['id'];
            }
        } while (count($rows) === $batchSize);
    }

    public static function presentPayloads(array $row): array
    {
        foreach (['input_payload', 'output_payload'] as $field) {
            $row[$field . '_raw'] = $row[$field] ?? '未采集';
        }
        return $row;
    }
}
