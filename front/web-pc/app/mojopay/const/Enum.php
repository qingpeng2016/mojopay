<?php

declare (strict_types=1);

namespace app\mojopay\const;

class Enum
{
    /** @return list<string> */
    public static function getMerchantStatuses(): array
    {
        return ['PENDING', 'ACTIVE', 'SUSPENDED', 'REJECTED'];
    }

    /** @return list<string> */
    public static function getProviderStatuses(): array
    {
        return ['ACTIVE', 'INACTIVE'];
    }

    /** @return list<string> */
    public static function getProviderEnvironments(): array
    {
        return ['SANDBOX', 'PRODUCTION'];
    }

    /** @return list<string> */
    public static function getProviderAccountRoles(): array
    {
        return ['MASTER', 'SUB', 'OPERATIONAL'];
    }

    /** @return list<string> */
    public static function getChannelCapabilityStatuses(): array
    {
        return ['ACTIVE', 'INACTIVE'];
    }

    /** @return list<string> */
    public static function getChannelOperations(): array
    {
        return ['PAYIN', 'REFUND', 'FX', 'TRANSFER', 'PAYOUT'];
    }

    /** 上游 inbox 状态 → Layui 徽章底色（默认 badge 在主题里是红色，需显式指定） */
    public static function providerInboxStatusBadgeClass(string $status): string
    {
        $st = strtoupper(trim($status));

        return match ($st) {
            'DONE', 'PROCESSED', 'SUCCEEDED', 'COMPLETED', 'ACKED' => 'layui-bg-green',
            'PENDING', 'NEW', 'RECEIVED', 'QUEUED' => 'layui-bg-orange',
            'PROCESSING', 'RUNNING', 'HANDLING' => 'layui-bg-blue',
            'FAILED', 'ERROR', 'DEAD', 'REJECTED' => 'layui-bg-red',
            'SKIPPED', 'IGNORED', 'CANCELLED', 'EXPIRED' => 'layui-bg-gray',
            default => 'layui-bg-cyan',
        };
    }

    /** @return list<string> */
    public static function getProviderInboxStatuses(): array
    {
        return ['PENDING', 'PROCESSING', 'DONE', 'FAILED', 'SKIPPED'];
    }

    /** @return array<string, string> 上游回调事件 code => 中文 */
    public static function getProviderInboxEventTypeLabels(): array
    {
        return [
            'balance.snapshot'   => '余额快照',
            'balance.change'     => '余额变动',
            'payment.paid'       => '支付到账',
            'payment.failed'     => '支付失败',
            'payout.completed'   => '出款完成',
            'payout.failed'      => '出款失败',
            'transfer.completed' => '划转完成',
            'refund.completed'   => '退款完成',
            'webhook.test'       => '回调测试',
        ];
    }

    public static function providerInboxEventTypeLabel(string $type): string
    {
        $t   = strtolower(trim($type));
        $map = self::getProviderInboxEventTypeLabels();

        return $map[$t] ?? ($t === '' ? '-' : '其他通知');
    }

    public static function isProviderInboxBalanceEventType(string $type): bool
    {
        return str_starts_with(strtolower(trim($type)), 'balance.');
    }

    /** @return list<string> */
    public static function getComplianceStatuses(): array
    {
        return ['PENDING', 'APPROVED', 'REJECTED', 'EXPIRED', 'SUSPENDED'];
    }

    /** @return array<string, string> UI 资质大类 */
    public static function getComplianceQualKinds(): array
    {
        return [
            'KYB' => 'KYB（主体）',
            'KYC' => 'KYC（最终用户）',
        ];
    }

    public static function complianceQualKindLabel(string $subjectType, string $purpose): string
    {
        $st = strtoupper(trim($subjectType));
        $pu = strtoupper(trim($purpose));
        if ($st === 'MERCHANT' || $pu === 'KYB') {
            return 'KYB';
        }
        if ($st === 'MERCHANT_USER' || $pu === 'KYC') {
            return 'KYC';
        }

        return $pu !== '' ? $pu : $st;
    }

    public static function complianceStatusBadgeClass(string $status): string
    {
        $st = strtoupper(trim($status));

        return match ($st) {
            'APPROVED' => 'layui-bg-green',
            'PENDING' => 'layui-bg-orange',
            'REJECTED', 'SUSPENDED' => 'layui-bg-red',
            'EXPIRED' => 'layui-bg-gray',
            default => 'layui-bg-cyan',
        };
    }

    /** @return list<string> */
    public static function getPaymentOrderStatuses(): array
    {
        return ['CREATED', 'PENDING_PAYMENT', 'PAID', 'EXPIRED', 'CANCELLED'];
    }

    public static function paymentOrderStatusLabel(string $status): string
    {
        $st = strtoupper(trim($status));

        return match ($st) {
            'CREATED' => '初始化',
            'PENDING_PAYMENT' => '待支付',
            'PAID' => '已支付',
            'EXPIRED' => '已过期',
            'CANCELLED' => '已取消',
            default => $st === '' ? '-' : $st,
        };
    }

    public static function complianceStatusLabel(string $status): string
    {
        $st = strtoupper(trim($status));

        return match ($st) {
            'PENDING' => '待审核',
            'APPROVED' => '已通过',
            'REJECTED' => '已拒绝',
            'EXPIRED' => '已过期',
            'SUSPENDED' => '已暂停',
            default => $st === '' ? '-' : $st,
        };
    }

    public static function providerInboxStatusLabel(string $status): string
    {
        $st = strtoupper(trim($status));

        return match ($st) {
            'PENDING', 'NEW', 'QUEUED', 'RECEIVED' => '待处理',
            'PROCESSING', 'RUNNING', 'HANDLING' => '处理中',
            'DONE', 'PROCESSED', 'SUCCEEDED', 'COMPLETED', 'ACKED' => '已完成',
            'FAILED', 'ERROR', 'DEAD', 'REJECTED' => '失败',
            'SKIPPED', 'IGNORED', 'CANCELLED', 'EXPIRED' => '已跳过',
            default => $st === '' ? '-' : $st,
        };
    }

    /** @return array<string, string> ISO 3166-1 alpha-2 => 中文名 */
    public static function getRegionNameZhMap(): array
    {
        return [
            'US' => '美国',
            'DE' => '德国',
            'GB' => '英国',
            'JP' => '日本',
            'CN' => '中国',
            'HK' => '中国香港',
            'SG' => '新加坡',
            'AU' => '澳大利亚',
            'CA' => '加拿大',
            'CH' => '瑞士',
            'KR' => '韩国',
            'IN' => '印度',
            'BR' => '巴西',
            'MX' => '墨西哥',
            'TH' => '泰国',
            'MY' => '马来西亚',
            'PH' => '菲律宾',
            'ID' => '印度尼西亚',
            'TW' => '中国台湾',
            'AE' => '阿联酋',
            'NZ' => '新西兰',
            'VN' => '越南',
            'FR' => '法国',
            'IT' => '意大利',
            'ES' => '西班牙',
            'NL' => '荷兰',
            'SE' => '瑞典',
            'NO' => '挪威',
            'DK' => '丹麦',
            'PL' => '波兰',
            'RU' => '俄罗斯',
            'SA' => '沙特阿拉伯',
        ];
    }

    public static function regionNameZhByCode(string $code): string
    {
        $map = self::getRegionNameZhMap();
        $c   = strtoupper(trim($code));

        return $map[$c] ?? '';
    }
}