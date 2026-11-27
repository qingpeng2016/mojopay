<?php

declare (strict_types=1);

namespace app\mojopay\trait;

use think\db\Query;
use think\facade\Db;
use think\Paginator;

trait MojopayListTrait
{
    use SafeQueryTrait;
    use RecordViewTrait;

    protected function mojopayDb()
    {
        return Db::connect('db_mojopay');
    }

    /**
     * @param array<string, mixed> $get
     */
    protected function paginateMojopay(Query $query, array $get, string $controllerSlug, int $defaultSize = 20): Paginator
    {
        $slug = trim($controllerSlug, '/');
        if (str_ends_with($slug, '/index')) {
            $slug = substr($slug, 0, -strlen('/index'));
        }

        $embed = (string)($get['embed'] ?? $this->request->get('embed', '')) === '1';
        if ($embed) {
            $get['embed'] = '1';
            $lockPid = $this->sanitizeId($get['provider_id'] ?? $this->request->get('provider_id'));
            if ($lockPid) {
                $get['provider_id'] = $lockPid;
            }
            $lockMid = $this->sanitizeId($get['merchant_id'] ?? $this->request->get('merchant_id'));
            if ($lockMid) {
                $get['merchant_id'] = $lockMid;
            }
            $path = preg_replace('/\?.*$/', '', (string)$this->request->url());
        } else {
            $path = '/admin.html#/mojopay/' . $slug . '/index.html';
        }

        return $query->paginate([
            'list_rows' => isset($get['pageSize'])
                ? min(100, max(10, intval($get['pageSize'])))
                : $defaultSize,
            'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
            'query'     => $get,
            'path'      => $path,
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
}