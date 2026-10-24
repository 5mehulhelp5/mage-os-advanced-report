<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Benchmark;

/**
 * Wall clock in unix epoch milliseconds, shared by the concurrent benchmark parent and its workers so they
 * can synchronize on one start instant.
 */
class BarrierClock
{
    public function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * Sleeps until the given instant; returns immediately when it already passed.
     */
    public function waitUntil(int $epochMs): void
    {
        $remainingMs = $epochMs - $this->nowMs();
        if ($remainingMs > 0) {
            usleep($remainingMs * 1000);
        }
    }
}
