<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use Magento\Framework\Lock\LockManagerInterface;
use Throwable;

/**
 * Serializes concurrent cache misses of the same report: the first process computes and saves,
 * the waiting ones re-read the cache once they get the lock.
 *
 * Failing to lock (timeout, DB error, MySQL user-lock deadlock) never fails the report: the
 * computation then simply runs unlocked. `GET_LOCK` is re-entrant per DB session, so contention
 * only exists across processes (a nested run in the same process re-locks under its own key).
 */
class ResultCacheLock
{
    private const string LOCK_PREFIX = 'mageos_advancedreport_';

    public function __construct(
        private readonly LockManagerInterface $lockManager,
        private readonly ResultCacheWriteGuard $writeGuard,
        private readonly int $waitSeconds = 10,
    ) {
    }

    public function getLockName(string $cacheKey): string
    {
        return self::LOCK_PREFIX . $cacheKey;
    }

    /**
     * @template T
     * @param callable(): ?T $reload Re-reads the cache once the lock is held
     * @param callable(): T $compute Computes (and saves) the value on a confirmed miss
     * @return T
     */
    public function run(string $cacheKey, callable $reload, callable $compute): mixed
    {
        if ($this->writeGuard->isStoreSuppressed()) {
            return $reload() ?? $compute();
        }

        $lockName = $this->getLockName($cacheKey);
        if (!$this->acquire($lockName)) {
            return $compute();
        }

        try {
            return $reload() ?? $compute();
        } finally {
            $this->release($lockName);
        }
    }

    private function acquire(string $lockName): bool
    {
        try {
            return $this->lockManager->lock($lockName, $this->waitSeconds);
        } catch (Throwable) {
            return false;
        }
    }

    private function release(string $lockName): void
    {
        try {
            $this->lockManager->unlock($lockName);
        } catch (Throwable) {
            // never mask the execution outcome
        }
    }
}
