<?php
declare(strict_types=1);

namespace app\so_strategy\service;

use think\facade\Db;

/**
 * SO 快盘做市商账号。
 *
 * 唯一来源是 SO 配置表 sys_config 的 fastbook_pnl_excluded_account_ids：
 * 该配置既是 PNL 统计中需要排除的账号，也是快盘策略唯一的做市商账号。
 * 后台不硬编码任何账号；配置缺失时直接抛异常，避免算出不准确的 PNL。
 */
final class FastbookAccounts
{
    private const CONNECTION = 'db_fastbook';
    private const CONFIG_KEY = 'fastbook_pnl_excluded_account_ids';

    /** 按 | 分隔去重后的账号列表；空列表表示没有需要排除的账号。 */
    private static ?array $ids = null;

    /**
     * PNL 统计需要排除的账号（做市商账号）。
     *
     * @return string[]
     */
    public static function excludedIds(): array
    {
        return self::load();
    }

    /**
     * 判定成交买卖方向使用的做市商账号。
     *
     * 与 PNL 排除账号同源：这份配置就是唯一的快盘做市账号。
     *
     * @return string[]
     */
    public static function makerIds(): array
    {
        return self::load();
    }

    /** @return string[] */
    private static function load(): array
    {
        if (self::$ids !== null) {
            return self::$ids;
        }
        $value = Db::connect(self::CONNECTION)->table('sys_config')
            ->where('config_key', self::CONFIG_KEY)->value('config_value');
        if ($value === null) {
            throw new \RuntimeException('缺少必填配置 ' . self::CONFIG_KEY . '，无法准确计算快盘 PNL');
        }
        $ids = [];
        foreach (explode('|', (string)$value) as $accountId) {
            $accountId = trim($accountId);
            if ($accountId !== '') {
                $ids[$accountId] = true;
            }
        }
        return self::$ids = array_keys($ids);
    }
}
