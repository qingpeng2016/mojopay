<?php

declare (strict_types=1);

namespace app\bitfinex\trait;

use think\facade\Db;

/**
 * 主/子账号划转、兑换共用逻辑
 */
trait BitfinexWalletOpsTrait
{
    /**
     * @return array{USD:array{funding:float,exchange:float},USDT:array{funding:float,exchange:float}}
     */
    protected function loadMasterWalletBalances(int $id): array
    {
        $row = Db::connect('db_bitfinex')
            ->name('fund_bitfinex_master_accounts')
            ->where('id', $id)
            ->find();
        if (empty($row)) {
            return $this->emptyWalletBalances();
        }

        return [
            'USD' => [
                'funding'  => (float)($row['usd_funding_cash_total'] ?? 0),
                'exchange' => (float)($row['usd_exchange_cash_total'] ?? 0),
            ],
            'USDT' => [
                'funding'  => (float)($row['usdt_funding_cash_total'] ?? 0),
                'exchange' => (float)($row['usdt_exchange_cash_total'] ?? 0),
            ],
        ];
    }

    /**
     * 子账号钱包余额：与主账号同口径，读 sub_accounts 上的 funding/exchange 空闲字段
     *
     * @return array{USD:array{funding:float,exchange:float},USDT:array{funding:float,exchange:float}}
     */
    protected function loadSubWalletBalances(int $id): array
    {
        $row = Db::connect('db_bitfinex')
            ->name('fund_bitfinex_sub_accounts')
            ->where('id', $id)
            ->find();
        if (empty($row)) {
            return $this->emptyWalletBalances();
        }

        return [
            'USD' => [
                'funding'  => (float)($row['usd_funding_cash_total'] ?? 0),
                'exchange' => (float)($row['usd_exchange_cash_total'] ?? 0),
            ],
            'USDT' => [
                'funding'  => (float)($row['usdt_funding_cash_total'] ?? 0),
                'exchange' => (float)($row['usdt_exchange_cash_total'] ?? 0),
            ],
        ];
    }

    /**
     * @return array{USD:array{funding:float,exchange:float},USDT:array{funding:float,exchange:float}}
     */
    protected function loadWalletBalances(string $accountType, int $accountId): array
    {
        return $accountType === 'master'
            ? $this->loadMasterWalletBalances($accountId)
            : $this->loadSubWalletBalances($accountId);
    }

    /**
     * @return array{USD:array{funding:float,exchange:float},USDT:array{funding:float,exchange:float}}
     */
    protected function emptyWalletBalances(): array
    {
        return [
            'USD'  => ['funding' => 0.0, 'exchange' => 0.0],
            'USDT' => ['funding' => 0.0, 'exchange' => 0.0],
        ];
    }

    protected function parsePositiveAmount(mixed $raw): ?string
    {
        $value = trim((string)$raw);
        if ($value === '' || !is_numeric($value) || (float)$value <= 0) {
            return null;
        }

        return $value;
    }

    /**
     * @param array{USD:array{funding:float,exchange:float},USDT:array{funding:float,exchange:float}} $balances
     */
    protected function availableWalletAmount(array $balances, string $currency, string $wallet): float
    {
        $currency = strtoupper($currency);
        if ($currency === 'UST') {
            $currency = 'USDT';
        }
        $wallet = strtolower($wallet);
        if (!isset($balances[$currency][$wallet])) {
            return 0.0;
        }

        return (float)$balances[$currency][$wallet];
    }

    /**
     * 兑换校验：来源币种仅看 exchange 钱包余额
     *
     * @param array{USD:array{funding:float,exchange:float},USDT:array{funding:float,exchange:float}} $balances
     */
    protected function availableExchangeAmount(array $balances, string $direction): float
    {
        $source = $direction === 'usdt_to_usd' ? 'USDT' : 'USD';

        return (float)($balances[$source]['exchange'] ?? 0);
    }
}
