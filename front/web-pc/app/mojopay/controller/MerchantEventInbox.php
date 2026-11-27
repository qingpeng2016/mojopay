<?php

declare (strict_types=1);

namespace app\mojopay\controller;

/**
 * 商户余额流水（merchant_event_inbox 中 balance.* 事件）。
 */
class MerchantEventInbox extends MerchantInboxController
{
    protected function viewSlug(): string
    {
        return 'merchant_event_inbox';
    }
}