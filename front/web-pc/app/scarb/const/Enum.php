<?php

declare (strict_types=1);

namespace app\scarb\const;

class Enum
{
    /** @return list<string> */
    public static function getAllOrderStatuses(): array
    {
        return [
            'PENDING_SUBMIT',
            'NEW',
            'PARTIALLY_FILLED',
            'FILLED',
            'CANCELED',
            'REJECTED',
            'EXPIRED',
        ];
    }

    /** @return list<string> */
    public static function getAllSides(): array
    {
        return ['BUY', 'SELL'];
    }

    /** @return list<string> */
    public static function getAllPlatforms(): array
    {
        return ['binance', 'bitfinex'];
    }

    /** @return list<string> */
    public static function getPositionStatuses(): array
    {
        return ['OPEN', 'CLOSED'];
    }

    /** @return list<string> */
    public static function getPositionSides(): array
    {
        return ['LONG', 'SHORT'];
    }

    /** @return list<string> */
    public static function getContractReviewStatuses(): array
    {
        return ['INIT', 'PENDING', 'APPROVED', 'REJECTED', 'DONE', 'FAILED'];
    }

    /** @return list<string> */
    public static function getRebalanceReviewStatuses(): array
    {
        return ['INIT', 'PENDING', 'DOING', 'DONE', 'FAILED', 'CANCELLED'];
    }

    /** @return list<string> */
    public static function getConvertStatuses(): array
    {
        return ['INIT', 'PENDING', 'SUCCESS', 'FAILED'];
    }

    /** @return list<string> */
    public static function getAccountStatuses(): array
    {
        return ['0', '1'];
    }

    /** @return list<string> */
    public static function getAccountTypes(): array
    {
        return ['um_futures_multi_asset', 'portfolio_margin'];
    }
}
