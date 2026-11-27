<?php
declare (strict_types=1);

namespace app\cool_pay3\const;

/**
 * 系统常量定义
 */
class Enum
{
    /**
     * 语言类型
     */
    const LANG_EN = 1; // 英文
    const LANG_CH = 2; // 中文
    const LANG_HK = 3; // 繁体中文

    const VENDOR_FOREGATE = "FOREGATE";
    const VENDOR_POLYMARKET = "POLYMARKET";

    const ACCOUNT_TYPE_COLLECTION = "COLLECTION";
    const ACCOUNT_TYPE_MARKET_MAKING_FOREGATE = "MARKET_MAKING_FOREGATE";
    const ACCOUNT_TYPE_MARKET_MAKING_POLYMARKET = "MARKET_MAKING_POLYMARKET";
    const ACCOUNT_TYPE_TRADE_FOREGATE = "TRADE_FOREGATE";

    const ORDER_SIDE_BUY = "BUY";
    const ORDER_SIDE_SELL = "SELL";

    const PENDING = "PENDING";
    const FILLED = "FILLED";
    const CANCELED = "CANCELED";
    const FAILED = "FAILED";

    /**
     * 获取所有市场
     * @return array
     */
    public static function GetAllVendor()
    {
        return [
            self::VENDOR_POLYMARKET,
            self::VENDOR_FOREGATE
        ];
    }

    /**
     * 获取所有账号类型
     * @return array
     */
    public static function GetAllAccountType()
    {
        return [
            self::ACCOUNT_TYPE_COLLECTION,
            self::ACCOUNT_TYPE_MARKET_MAKING_FOREGATE,
            self::ACCOUNT_TYPE_MARKET_MAKING_POLYMARKET,
            self::ACCOUNT_TYPE_TRADE_FOREGATE
        ];
    }

    /*
     * 获取买卖方向
     * @return array
     */
    public static function GetAllOrderSide()
    {        
        return [
            self::ORDER_SIDE_BUY,
            self::ORDER_SIDE_SELL    
         ];
    }

    public static function GetAllOrderStatus()
    {
        return [
            self::PENDING,
            self::FILLED,
            self::CANCELED,
            self::FAILED
        ];
    }
} 
