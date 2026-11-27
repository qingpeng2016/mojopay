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

    /** @return array<int, string> id => label */
    protected function merchantOptions(): array
    {
        $rows = $this->mojopayDb()->name('merchant')->order('id', 'asc')->column('legal_name', 'id');
        $out  = [];
        foreach ($rows as $id => $name) {
            $out[(int)$id] = (string)$name;
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    protected function merchantAccountRows(): array
    {
        return $this->mojopayDb()->name('merchant_account')
            ->alias('a')
            ->leftJoin('merchant m', 'm.id = a.merchant_id')
            ->field('a.id,a.merchant_id,a.code,a.parent_id,m.legal_name as merchant_name')
            ->order('a.id', 'desc')
            ->select()
            ->toArray();
    }

    /** @return array<int, string> */
    protected function merchantAccountOptions(?int $merchantId = null): array
    {
        $out = [];
        foreach ($this->merchantAccountRows() as $row) {
            if ($merchantId !== null && (int)($row['merchant_id'] ?? 0) !== $merchantId) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = sprintf(
                '%s / %s',
                (string)($row['merchant_name'] ?? ''),
                (string)($row['code'] ?? '')
            );
            $out[$id] = $label;
        }

        return $out;
    }

    protected function merchantAccountIdsForMerchant(int $merchantId): array
    {
        $ids = $this->mojopayDb()->name('merchant_account')->where('merchant_id', $merchantId)->column('id');

        return array_map('intval', $ids ?: []);
    }

    /** @return array<int, string> */
    protected function channelCapabilityOptions(): array
    {
        $rows = $this->mojopayDb()->name('channel_capability')->alias('c')
            ->join('provider_account a', 'a.id = c.provider_account_id')
            ->join('provider p', 'p.id = a.provider_id')
            ->field('c.id,c.operation,c.region,c.capability_key,p.name as provider_name,a.external_id')
            ->order('c.id', 'desc')
            ->select()
            ->toArray();
        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[$id] = sprintf(
                '#%d %s / %s %s %s',
                $id,
                (string)($row['provider_name'] ?? ''),
                (string)($row['external_id'] ?? ''),
                (string)($row['operation'] ?? ''),
                (string)($row['capability_key'] ?? '')
            );
        }

        return $out;
    }
}