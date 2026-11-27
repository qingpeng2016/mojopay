<?php

declare (strict_types=1);

namespace app\bitfinex\trait;

trait SafeQueryTrait
{
    protected function sanitizeLike(string $value, int $maxLength = 100): string
    {
        if (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }

    protected function sanitizeId($value): ?int
    {
        if (is_numeric($value) && intval($value) > 0) {
            return intval($value);
        }
        return null;
    }

    /**
     * @param mixed $value 单 id、逗号分隔字符串或 id 数组
     * @return list<int>
     */
    protected function sanitizeIdList($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $raw = trim((string)$value);
            if ($raw === '') {
                return [];
            }
            $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        }

        $ids = [];
        foreach ($parts as $part) {
            $id = $this->sanitizeId($part);
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    protected function sanitizeEnum($value, array $allowedValues): ?string
    {
        if (in_array($value, $allowedValues, true)) {
            return $value;
        }
        return null;
    }

    protected function sanitizeDateRange(string $dateRange): ?array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*-\s*(\d{4}-\d{2}-\d{2})$/', $dateRange, $matches)) {
            $start = $matches[1];
            $end   = $matches[2];
            if (strtotime($start) !== false && strtotime($end) !== false) {
                return [$start, $end];
            }
        }
        return null;
    }
}
