<?php
declare (strict_types=1);

namespace app\cool_pay2\trait;

/**
 * 安全查询 Trait
 * 用于防止 LIKE 通配符注入和输入验证
 */
trait SafeQueryTrait
{
    /**
     * 清理 LIKE 查询的输入
     * @param string $value 用户输入
     * @param int $maxLength 最大长度
     * @return string 清理后的值
     */
    protected function sanitizeLike(string $value, int $maxLength = 100): string
    {
        // 1. 长度限制
        if (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        
        // 2. 转义 LIKE 特殊字符
        $value = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        
        return $value;
    }
    
    /**
     * 清理 JSON 搜索的输入（额外移除引号）
     * @param string $value 用户输入
     * @param int $maxLength 最大长度
     * @return string 清理后的值
     */
    protected function sanitizeJsonLike(string $value, int $maxLength = 50): string
    {
        // 1. 长度限制
        if (strlen($value) > $maxLength) {
            $value = substr($value, 0, $maxLength);
        }
        
        // 2. 转义 LIKE 特殊字符
        $value = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        
        // 3. 移除可能破坏 JSON 结构的字符
        $value = str_replace(['"', "'", "\0", "\n", "\r"], '', $value);
        
        return $value;
    }
    
    /**
     * 验证并返回整数 ID
     * @param mixed $value 用户输入
     * @return int|null 有效的整数 ID 或 null
     */
    protected function sanitizeId($value): ?int
    {
        if (is_numeric($value) && intval($value) > 0) {
            return intval($value);
        }
        return null;
    }
    
    /**
     * 验证枚举值是否在白名单中
     * @param mixed $value 用户输入
     * @param array $allowedValues 允许的值列表
     * @return string|null 有效的值或 null
     */
    protected function sanitizeEnum($value, array $allowedValues): ?string
    {
        if (in_array($value, $allowedValues, true)) {
            return $value;
        }
        return null;
    }
    
    /**
     * 验证日期范围格式
     * @param string $dateRange 格式："YYYY-MM-DD - YYYY-MM-DD"
     * @return array|null [start_date, end_date] 或 null
     */
    protected function sanitizeDateRange(string $dateRange): ?array
    {
        $dateRange = trim($dateRange);
        if ($dateRange === '') {
            return null;
        }
        // 后台 hash 路由或 serialize 后常见字面量「+-+」，与标准「 - 」不一致，先归一
        $dateRange = str_replace(['+-+', '%2B-%2B', '%2b-%2b'], ' - ', $dateRange);
        // Layui / 浏览器可能使用 en dash、全角横线等，统一为 ASCII '-'
        $dateRange = str_replace(
            ["\u{2013}", "\u{2014}", "\u{2212}", '－', '〜', '~'],
            '-',
            $dateRange
        );
        $dateRange = preg_replace('/\s+/u', ' ', $dateRange);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*-\s*(\d{4}-\d{2}-\d{2})$/u', $dateRange, $matches)) {
            $start = $matches[1];
            $end = $matches[2];
            if (strtotime($start) !== false && strtotime($end) !== false) {
                return [$start, $end];
            }
        }
        // 单日
        if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $dateRange, $matches)) {
            $d = $matches[1];
            if (strtotime($d) !== false) {
                return [$d, $d];
            }
        }
        return null;
    }
}

