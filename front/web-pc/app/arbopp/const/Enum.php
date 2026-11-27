<?php

declare (strict_types=1);

namespace app\arbopp\const;

class Enum
{
    /** @return list<string> */
    public static function getPlatforms(): array
    {
        return ['POLYMARKET', 'KALSHI', 'PREDICT', 'SXBET'];
    }

    /** @return list<string> */
    public static function getAuthModes(): array
    {
        return ['KMS', 'CLOB', 'BC'];
    }

    /** @return list<string> */
    public static function getAccountStatuses(): array
    {
        return ['ACTIVE', 'INACTIVE'];
    }

    /** @return list<string> */
    public static function getPlatformStatuses(): array
    {
        return ['ACTIVE', 'INACTIVE'];
    }

    /** @return list<string> */
    public static function getPlatformTypes(): array
    {
        return ['PREDICTION', 'ODDS'];
    }

    /** @return list<string> */
    public static function getQuoteStyles(): array
    {
        return ['PROBABILITY', 'DECIMAL_ODDS', 'AMERICAN_ODDS'];
    }

    /** @return list<string> */
    public static function getMarketStatuses(): array
    {
        return ['ACTIVE', 'INACTIVE'];
    }

    /** @return list<string> */
    public static function getOppKinds(): array
    {
        return ['BINARY_YES_NO', 'ONE_X2_YES_TRIPLE', 'ONE_X2_NO_TRIPLE'];
    }

    /** @return list<string> */
    public static function getOrderStatuses(): array
    {
        return ['FILLED', 'NO_FILL', 'ERROR', 'PENDING', 'PARTIAL'];
    }

    /** @return list<string> */
    public static function getConfigValueTypes(): array
    {
        return ['string', 'int', 'float', 'bool', 'json', 'DECIMAL'];
    }

    /** @return list<string> */
    public static function getTinyStatuses(): array
    {
        return ['0', '1'];
    }

    /** @return list<string> */
    public static function getForegateAccountTypes(): array
    {
        return ['buy', 'sell'];
    }

    /** @return list<string> */
    public static function getYesNo(): array
    {
        return ['0', '1'];
    }
}