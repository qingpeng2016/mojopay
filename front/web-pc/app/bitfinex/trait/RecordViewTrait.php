<?php

declare (strict_types=1);

namespace app\bitfinex\trait;

use think\db\Query;

trait RecordViewTrait
{
    protected function applyDateRangeFilter(Query $query, array $get, string $column, bool $defaultLast30Days = true): void
    {
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start, $end] = $dates;
            $query->whereBetween($column, ["{$start} 00:00:00", "{$end} 23:59:59"]);
            return;
        }
        if ($defaultLast30Days) {
            $query->where($column, '>=', date('Y-m-d H:i:s', strtotime('-30 days')));
        }
    }

    /**
     * 导出 CSV 时解析日期范围；未选择则默认最近 30 天（与列表默认一致）。
     *
     * @param array<string, mixed> $get
     * @return array{0: string, 1: string, 2: string} [start, end, date_range]
     */
    protected function resolveExportDateRange(array $get): array
    {
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start, $end] = $dates;
            return [$start, $end, "{$start} - {$end}"];
        }

        $end   = date('Y-m-d');
        $start = date('Y-m-d', strtotime('-30 days'));

        return [$start, $end, "{$start} - {$end}"];
    }

    protected function validateExportDateRange(string $start, string $end): ?string
    {
        $startTs = strtotime($start);
        $endTs   = strtotime($end);
        if ($startTs === false || $endTs === false) {
            return '日期范围格式错误';
        }
        if ($startTs > $endTs) {
            return '开始日期不能晚于结束日期';
        }
        $days = (int) floor(($endTs - $startTs) / 86400) + 1;
        if ($days > 31) {
            return '导出日期范围最多 1 个月（31 天）';
        }

        return null;
    }

    protected function buildCsvExportFilename(string $label, string $start, string $end): string
    {
        $safeLabel = preg_replace('/[\\\\\/:*?"<>|]/u', '_', trim($label)) ?: 'export';

        return sprintf(
            '%s_%s_%s.csv',
            $safeLabel,
            str_replace('-', '', $start),
            str_replace('-', '', $end)
        );
    }

    protected function sendCsvDownloadHeaders(string $filename): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        $encoded = rawurlencode($filename);
        header('Content-Disposition: attachment; filename="' . $encoded . '"; filename*=UTF-8\'\'' . $encoded);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    protected function formatDatetime(?string $value): string
    {
        return !empty($value) ? date('Y-m-d H:i:s', strtotime((string)$value)) : '-';
    }

    protected function formatTsMs($ms): string
    {
        $ms = (int)$ms;
        if ($ms <= 0) {
            return '-';
        }
        return date('Y-m-d H:i:s', (int) floor($ms / 1000));
    }

    protected function trimDecimal($value, int $scale = 8): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        $formatted = rtrim(rtrim(number_format((float)$value, $scale, '.', ''), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @param mixed $value
     */
    protected function jsonForDisplay($value): string
    {
        if ($value === null || $value === '') {
            return '[]';
        }
        if (is_array($value)) {
            return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
            return $value;
        }
        return (string)$value;
    }
}
