<?php

declare (strict_types=1);

namespace app\mojopay\trait;

/**
 * 从商户工作台（merchant_id）进入时锁定商户范围，禁止跨商户操作。
 */
trait MerchantScopeTrait
{
    protected function merchantScopeId(): ?int
    {
        $id = $this->sanitizeId($this->request->get('merchant_id'));
        if ($id) {
            return $id;
        }

        return $this->sanitizeId($this->request->post('_scope_merchant_id'));
    }

    protected function assignMerchantScopeContext(?int $merchantId = null): void
    {
        $lock = $merchantId ?? $this->merchantScopeId();
        if (!$lock) {
            return;
        }

        $row = $this->mojopayDb()->name('merchant')->where('id', $lock)->field('legal_name,code')->find();
        $name = trim((string)($row['legal_name'] ?? ''));
        $code = trim((string)($row['code'] ?? ''));

        $this->assign([
            'locked_merchant_id'    => $lock,
            'locked_merchant_label' => $name !== '' ? $name : ('#' . $lock),
            'locked_merchant_code'  => $code,
        ]);
    }

    protected function assertMerchantScope(int $merchantId): void
    {
        $lock = $this->merchantScopeId();
        if ($lock && $merchantId !== $lock) {
            throw new \InvalidArgumentException('当前页面已锁定商户，不能选择其他商户');
        }
    }

    /** @return array<int, string> */
    protected function scopedMerchantOptions(?int $merchantId): array
    {
        $scope = $merchantId ?? $this->merchantScopeId();
        if (!$scope) {
            return $this->merchantOptions();
        }

        $all = $this->merchantOptions();
        if (isset($all[$scope])) {
            return [$scope => $all[$scope]];
        }

        $label = (string)$this->mojopayDb()->name('merchant')->where('id', $scope)->value('legal_name');

        return [$scope => $label !== '' ? $label : ('#' . $scope)];
    }

    protected function assertMerchantAccountInScope(int $accountId): void
    {
        $lock = $this->merchantScopeId();
        if (!$lock) {
            return;
        }

        $mid = (int)$this->mojopayDb()->name('merchant_account')->where('id', $accountId)->value('merchant_id');
        if ($mid !== $lock) {
            throw new \InvalidArgumentException('所选账号不属于当前商户');
        }
    }

    /** 弹窗/表单链接追加 merchant_id 查询参数 */
    protected function merchantScopeQuerySuffix(): string
    {
        $lock = $this->merchantScopeId();
        if (!$lock) {
            return '';
        }

        return 'merchant_id=' . $lock;
    }
}