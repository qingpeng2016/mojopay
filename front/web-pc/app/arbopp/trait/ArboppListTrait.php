<?php

declare (strict_types=1);

namespace app\arbopp\trait;

use think\db\Query;
use think\facade\Db;
use think\Paginator;

trait ArboppListTrait
{
    use SafeQueryTrait;
    use RecordViewTrait;

    protected function arboppDb()
    {
        return Db::connect('db_arbopp');
    }

    /**
     * @param array<string, mixed> $get
     */
    protected function paginateArbopp(Query $query, array $get, string $controllerSlug, int $defaultSize = 20): Paginator
    {
        $slug = trim($controllerSlug, '/');
        if (str_ends_with($slug, '/index')) {
            $slug = substr($slug, 0, -strlen('/index'));
        }

        return $query->paginate([
            'list_rows' => isset($get['pageSize'])
                ? min(100, max(10, intval($get['pageSize'])))
                : $defaultSize,
            'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/arbopp/' . $slug . '/index.html',
        ], false);
    }

    /** @param array<string, mixed> $get */
    protected function applyOptionalId(Query $query, array $get): void
    {
        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }
    }

    /** @param array<string, mixed> $get */
    protected function applyOptionalPlatform(Query $query, array $get, string $column = 'platform'): void
    {
        if (empty($get['platform'])) {
            return;
        }
        $p = strtoupper(trim((string)$get['platform']));
        if ($p !== '' && strlen($p) <= 32) {
            $query->where($column, $p);
        }
    }

    /** @param array<string, mixed> $get */
    protected function applyOptionalLike(Query $query, array $get, string $param, string $column, int $maxLen = 128): void
    {
        if (empty($get[$param])) {
            return;
        }
        $v = trim((string)$get[$param]);
        if ($v === '') {
            return;
        }
        $query->whereLike($column, '%' . $this->sanitizeLike($v, $maxLen) . '%');
    }

    protected function truncateText(?string $value, int $max = 80): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max) . '…';
    }

    protected function formatDecimalField($value, int $scale = 8): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return $this->trimDecimal($value, $scale);
    }
}