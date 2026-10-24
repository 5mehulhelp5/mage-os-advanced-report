<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Security;

/**
 * Shared flag marking the current process as a trusted CLI run (no admin session exists).
 *
 * Disabled by default. Only CLI commands that run reports on behalf of the shell user
 * (the benchmark command) call {@see enable()}, after the area code is set.
 */
class TrustedCliContext
{
    private bool $enabled = false;

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
