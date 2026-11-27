<?php

declare (strict_types=1);

namespace app\mojopay\trait;

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

    protected function formatDatetime(?string $value): string
    {
        return !empty($value) ? date('Y-m-d H:i:s', strtotime((string)$value)) : '-';
    }
}