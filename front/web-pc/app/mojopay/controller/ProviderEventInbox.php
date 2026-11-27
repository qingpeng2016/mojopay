<?php

declare (strict_types=1);

namespace app\mojopay\controller;

/**
 * 上游余额流水（provider_event_inbox 中 balance.* 事件）。
 */
class ProviderEventInbox extends ProviderInboxController
{
    protected function inboxScope(): string
    {
        return 'balance';
    }

    protected function viewSlug(): string
    {
        return 'provider_event_inbox';
    }
}