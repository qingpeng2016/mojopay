<?php
declare (strict_types=1);

namespace app\rmpd\trait;
// 这个 Trait 属于 app\rmpd\trait 命名空间。

/**
 * 安全查询工具集（Trait）
 *
 * 什么是 Trait？
 * PHP 是单继承语言（一个类只能有一个父类）。
 * Trait 是一种"混入（Mixin）"机制：把可复用的方法定义在 Trait 里，
 * 然后多个类都可以用 "use SafeQueryTrait;" 引入这些方法，
 * 相当于把 Trait 里的代码"复制粘贴"进那个类，但比复制粘贴更优雅。
 *
 * 这个 Trait 的作用：
 * 对用户输入进行清洗和验证，防止 SQL 注入和恶意数据进入数据库。
 * MarketPair、ArbRecord、AiCallLog 三个控制器都使用了它。
 */
trait SafeQueryTrait
{
    /**
     * 清洗用于 LIKE 查询的字符串
     *
     * SQL LIKE 语句中有三个特殊字符需要转义：
     *   %  → 匹配任意多个字符（通配符）
     *   _  → 匹配任意单个字符（通配符）
     *   \  → 转义符本身
     *
     * 如果用户输入 "100%"，不转义的话 SQL 变成 LIKE '%100%%'，
     * 会错误地匹配所有行。转义后变成 LIKE '%100\%%'，只匹配含"100%"的行。
     *
     * @param string $value     用户输入的原始字符串
     * @param int $maxLength    最大允许长度，超出部分截断（防止超长字符串撑爆 SQL 或 DB）
     * @return string           处理后可以安全放入 LIKE 子句的字符串
     */
    protected function sanitizeLike(string $value, int $maxLength = 100): string
    // protected 表示只有本类及其子类可以调用（外部不能直接调用）
    // string $value 是参数类型声明，$value 必须是字符串
    // int $maxLength = 100 是带默认值的参数，调用时可以不传第二个参数
    // : string 在括号后面表示返回值类型声明，此方法一定返回字符串
    {
        // strlen() 返回字符串的字节长度（注意：不是字符数，对中文不准确）
        // 这里用于限制输入长度，防止过长查询
        if (strlen($value) > $maxLength) {
            // substr($string, $start, $length) 截取字符串
            // 从第 0 个字符开始，截取 $maxLength 个字符
            $value = substr($value, 0, $maxLength);
        }

        // str_replace($search, $replace, $subject) 字符串替换
        // 第一个参数是"要找什么"（数组），第二个是"替换成什么"（数组，一一对应），第三个是"在哪里找"
        // 转义顺序很重要：必须先转义 \，否则后续加上的 \ 又会被当成需要转义的内容
        return str_replace(
            ['\\',   '%',    '_'],    // 要替换的特殊字符
            ['\\\\', '\\%', '\\_'],   // 替换后的转义形式
            $value
        );
    }

    /**
     * 验证并清洗 ID 参数（必须是正整数）
     *
     * @param mixed $value  用户传入的任意值（可能是字符串 "abc"、负数、小数等）
     * @return int|null     合法则返回正整数，否则返回 null
     *
     * 返回类型 ?int 中的 ? 表示"可为 null"（nullable），
     * 即返回值要么是 int，要么是 null。
     */
    protected function sanitizeId($value): ?int
    // 注意：参数 $value 没有类型声明（mixed），因为用户输入可以是任何类型
    {
        // is_numeric() 检查值是否可以被当成数字（包括数字字符串如 "42"）
        // intval() 把值转换为整数
        // 两个条件用 && 连接，表示"并且"，两个都为真才执行
        if (is_numeric($value) && intval($value) > 0) {
            return intval($value); // 返回正整数
        }
        return null; // 不合法则返回 null，调用方需要检查 null
    }

    /**
     * 验证枚举值是否在白名单内
     *
     * 白名单校验是防止枚举字段被注入的标准做法。
     * 例如 status 字段只允许 'review', 'monitoring' 等固定值，
     * 用户伪造的 'review OR 1=1' 不在白名单中，会被直接拒绝。
     *
     * @param mixed  $value         用户传入的值
     * @param array  $allowedValues 允许的合法值列表（从 Enum 类的 getAll*() 方法获取）
     * @return string|null          合法则返回原值，否则返回 null
     */
    protected function sanitizeEnum($value, array $allowedValues): ?string
    {
        // in_array($needle, $haystack, $strict) 检查 $needle 是否存在于 $haystack 数组中
        // 第三个参数 true 表示"严格模式"：类型也必须匹配（字符串 "0" ≠ 整数 0）
        // 严格模式防止类型混淆攻击
        if (in_array($value, $allowedValues, true)) {
            return $value; // 在白名单内，直接返回原值（类型安全）
        }
        return null; // 不在白名单，拒绝
    }

    /**
     * 验证并解析日期范围字符串
     *
     * 前端 laydate 日期范围选择器提交的格式为：
     *   "2024-01-01 - 2024-01-31"
     *
     * @param string $dateRange  用户传入的日期范围字符串
     * @return array|null        成功解析则返回 ['2024-01-01', '2024-01-31']，否则返回 null
     */
    protected function sanitizeDateRange(string $dateRange): ?array
    {
        // preg_match() 是 PHP 的正则表达式匹配函数
        // 正则表达式 '/^(\d{4}-\d{2}-\d{2})\s*-\s*(\d{4}-\d{2}-\d{2})$/' 解读：
        //   ^              → 字符串开头
        //   (\d{4}-\d{2}-\d{2})  → 捕获组1：4位数字-2位数字-2位数字（即 YYYY-MM-DD）
        //   \s*-\s*        → 中间的短横线，两侧允许有空格
        //   (\d{4}-\d{2}-\d{2})  → 捕获组2：同上
        //   $              → 字符串结尾
        // $matches 是输出参数：匹配成功后，$matches[0] 是完整匹配，$matches[1] $matches[2] 是两个捕获组
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*-\s*(\d{4}-\d{2}-\d{2})$/', $dateRange, $matches)) {
            $start = $matches[1]; // 开始日期，如 "2024-01-01"
            $end   = $matches[2]; // 结束日期，如 "2024-01-31"

            // strtotime() 把日期字符串转成 Unix 时间戳（整数）
            // 如果日期格式不合法（如 "2024-99-99"），返回 false
            // !== false 是严格不等判断（区分 false 和 0）
            if (strtotime($start) !== false && strtotime($end) !== false) {
                // 返回包含两个元素的索引数组（key 为 0 和 1）
                return [$start, $end];
            }
        }
        return null; // 格式不合法或日期无效
    }
}
