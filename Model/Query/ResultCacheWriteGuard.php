<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

/**
 * Shared switch that suppresses result cache writes (and the cache-miss lock) while a callback
 * runs, e.g. for exports whose large results must not be stored. Cached entries are still read.
 */
class ResultCacheWriteGuard
{
    private int $depth = 0;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function runWithoutStore(callable $callback): mixed
    {
        ++$this->depth;
        try {
            return $callback();
        } finally {
            --$this->depth;
        }
    }

    public function isStoreSuppressed(): bool
    {
        return $this->depth > 0;
    }
}
