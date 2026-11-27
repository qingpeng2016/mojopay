<?php

declare (strict_types=1);

namespace app\mojopay\controller;

/**
 * 上游回调通知（provider_event_inbox，非余额类事件）。
 */
class ProviderCallbackInbox extends ProviderInboxController
{
    protected function inboxScope(): string
    {
        return 'callback';
    }

    protected function viewSlug(): string
    {
        return 'provider_callback_inbox';
    }
}