<?php

declare (strict_types=1);

namespace app\mojopay\trait;

trait LookupTrait
{
    /** @return array<int, string> id => label */
    protected function providerOptions(): array
    {
        $rows = $this->mojopayDb()->name('provider')->order('id', 'asc')->column('name', 'id');
        $out  = [];
        foreach ($rows as $id => $name) {
            $out[(int)$id] = (string)$name;
        }

        return $out;
    }

    /** @return array<int, string> */
    protected function assetOptions(): array
    {
        $rows = $this->mojopayDb()->name('asset')
            ->orderRaw("CASE `kind` WHEN 'FIAT' THEN 0 ELSE 1 END")
            ->order('code', 'asc')
            ->field('id,code,kind')
            ->select()
            ->toArray();
        $out = [];
        foreach ($rows as $row) {
            $id   = (int)($row['id'] ?? 0);
            $code = (string)($row['code'] ?? '');
            if ($id <= 0 || $code === '') {
                continue;
            }
            $kindLabel          = (string)($row['kind'] ?? '') === 'FIAT' ? '法币' : '加密';
            $out[$id] = $code . '（' . $kindLabel . '）';
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    protected function providerAccountRows(): array
    {
        return $this->mojopayDb()->name('provider_account')
            ->alias('a')
            ->leftJoin('provider p', 'p.id = a.provider_id')
            ->field('a.id,a.provider_id,a.environment,a.external_id,a.role,p.name as provider_name')
            ->order('a.id', 'desc')
            ->select()
            ->toArray();
    }

    /** @return array<int, string> */
    protected function providerAccountOptions(?int $providerId = null): array
    {
        $out = [];
        foreach ($this->providerAccountRows() as $row) {
            if ($providerId !== null && (int)($row['provider_id'] ?? 0) !== $providerId) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = sprintf(
                '%s / %s / %s',
                (string)($row['provider_name'] ?? ''),
                (string)($row['environment'] ?? ''),
                (string)($row['external_id'] ?? '')
            );
            $out[$id] = $label;
        }

        return $out;
    }

    protected function accountIdsForProvider(int $providerId): array
    {
        $ids = $this->mojopayDb()->name('provider_account')->where('provider_id', $providerId)->column('id');

        return array_map('intval', $ids ?: []);
    }
}