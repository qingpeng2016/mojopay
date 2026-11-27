<?php

declare (strict_types=1);

namespace app\mojopay\trait;

trait EmbedViewTrait
{
    protected function isEmbedMode(): bool
    {
        return (string)$this->request->get('embed', '') === '1';
    }

    protected function assignEmbedContext(): void
    {
        $this->assign('embed', $this->isEmbedMode());
        $pid = $this->sanitizeId($this->request->get('provider_id'));
        if ($pid) {
            $this->assign('locked_provider_id', $pid);
        }
    }

    /** @param string $name 如 index */
    protected function fetchPage(string $name): string
    {
        $this->assignEmbedContext();

        return $this->fetch($this->isEmbedMode() ? ($name . '_embed') : $name);
    }
}