<?php

declare (strict_types=1);

namespace app\scarb\trait;

use think\db\Query;
use think\facade\Db;
use think\Paginator;

trait ScarbListTrait
{
    use SafeQueryTrait;
    use RecordViewTrait;

    protected function scarbDb()
    {
        return Db::connect('db_sc_arb');
    }

    /**
     * @param array<string, mixed> $get
     * @param string $controllerSlug 控制器 URL 段，与 bitfinex 一致，如 positions、rebalance_close_review（勿带 /index）
     */
    protected function paginateScarb(Query $query, array $get, string $controllerSlug, int $defaultSize = 20): Paginator
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
            'path'      => '/admin.html#/scarb/' . $slug . '/index.html',
        ], false);
    }

    /**
     * @param array<string, mixed> $get
     */
    protected function applyCommonListFilters(Query $query, array $get, string $dateColumn = 'created_at'): void
    {
        $idFilter = !empty($get['id']) ? $this->sanitizeId($get['id']) : null;
        $this->applyDateRangeFilter($query, $get, $dateColumn, $idFilter === null);

        if ($idFilter) {
            $query->where('id', $idFilter);
        }

        if (!empty($get['exchange_account_id'])) {
            $accountId = $this->sanitizeId($get['exchange_account_id']);
            if ($accountId) {
                $query->where('exchange_account_id', $accountId);
            }
        }

        if (!empty($get['platform']) && is_string($get['platform'])) {
            $platform = trim($get['platform']);
            if ($platform !== '' && strlen($platform) <= 32) {
                $query->where('platform', $platform);
            }
        }
    }

    protected function optionalDecimal(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value) || !is_finite((float)$value)) {
            throw new \InvalidArgumentException('数值格式无效');
        }

        return $value;
    }

    protected function requiredDecimal(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value) || !is_finite((float)$value)) {
            throw new \InvalidArgumentException($label . ' 不能为空且须为有效数字');
        }

        return $value;
    }

    /** 数量口径：空=BASE；BASE=标的 asset 单位；QUOTE=合约报价币（多腿 USDT / 空腿 USDC） */
    protected function sanitizeQtyAsset(string $value, string $contractAsset): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return '';
        }
        if ($value === 'USDT' || $value === 'USDC') {
            return 'QUOTE';
        }
        $base = strtoupper(trim($contractAsset));
        if ($base !== '' && $value === $base) {
            return 'BASE';
        }
        if ($value === 'BASE' || $value === 'QUOTE') {
            return $value;
        }
        throw new \InvalidArgumentException('数量口径须为空（BASE）、BASE 或 QUOTE');
    }

    /** U 本位合约 → 标的 base（如 1000PEPEUSDT→1000PEPE），与 Go BaseAssetFromUMSymbol 一致 */
    protected function baseAssetFromUMSymbol(string $symbol): string
    {
        $s = strtoupper(trim($symbol));
        if ($s === '') {
            return '';
        }
        if (str_ends_with($s, 'USDT')) {
            return substr($s, 0, -4);
        }
        if (str_ends_with($s, 'USDC')) {
            return substr($s, 0, -4);
        }

        return $s;
    }
}
