<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use Magento\Framework\Lock\LockManagerInterface;
use MageOS\AdvancedReport\Model\Query\ResultCacheLock;
use MageOS\AdvancedReport\Model\Query\ResultCacheWriteGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResultCacheLock::class)]
final class ResultCacheLockTest extends TestCase
{
    public function testItDoesNotLockWhileCacheStoringIsSuppressed(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::never())->method('lock');
        $guard = new ResultCacheWriteGuard();
        $lock = new ResultCacheLock($lockManager, $guard);

        $value = $guard->runWithoutStore(
            fn (): string => $lock->run('key', static fn (): ?string => null, static fn (): string => 'computed')
        );

        self::assertSame('computed', $value);
    }

    public function testItStillReusesACachedResultWhileCacheStoringIsSuppressed(): void
    {
        $guard = new ResultCacheWriteGuard();
        $lock = new ResultCacheLock($this->createStub(LockManagerInterface::class), $guard);

        $value = $guard->runWithoutStore(
            fn (): string => $lock->run('key', static fn (): string => 'cached', static function (): never {
                throw new \LogicException('must not compute');
            })
        );

        self::assertSame('cached', $value);
    }
}
