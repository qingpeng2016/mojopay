<?php

declare (strict_types=1);

namespace app\scarb\trait;

trait ScarbAccountTrait
{
    use ScarbListTrait;

    /**
     * @return array<int, string>
     */
    protected function loadAccountOptions(): array
    {
        $rows = $this->scarbDb()
            ->name('account_exchange')
            ->field('id,account_name,platform')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $options = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $name = (string)($row['account_name'] ?? '');
            $platform = (string)($row['platform'] ?? '');
            $options[$id] = $platform !== '' ? "{$name} (#{$id}, {$platform})" : "{$name} (#{$id})";
        }

        return $options;
    }

    protected function resolveAccountPlatform(int $accountId): ?string
    {
        if ($accountId < 1) {
            return null;
        }
        $platform = $this->scarbDb()
            ->name('account_exchange')
            ->where('id', $accountId)
            ->value('platform');

        if ($platform === null || $platform === '') {
            return null;
        }

        return (string)$platform;
    }
}
