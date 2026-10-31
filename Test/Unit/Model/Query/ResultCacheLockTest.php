<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\Lock\LockManagerInterface;
use MageOS\AdvancedReport\Model\Cache\Type;
use MageOS\AdvancedReport\Model\Config;
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
        $lock = $this->build($lockManager, $guard);

        $value = $guard->runWithoutStore(
            fn (): string => $lock->run('key', static fn (): ?string => null, static fn (): string => 'computed')
        );

        self::assertSame('computed', $value);
    }

    public function testItStillReusesACachedResultWhileCacheStoringIsSuppressed(): void
    {
        $guard = new ResultCacheWriteGuard();
        $lock = $this->build($this->createStub(LockManagerInterface::class), $guard);

        $value = $guard->runWithoutStore(
            fn (): string => $lock->run('key', static fn (): string => 'cached', static function (): never {
                throw new \LogicException('must not compute');
            })
        );

        self::assertSame('cached', $value);
    }

    public function testItWaitsAtLeastTheConfiguredQueryTimeoutPlusAMarginForTheLock(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())->method('lock')->with('mageos_advancedreport_key', 32)->willReturn(true);
        $lock = $this->build($lockManager, new ResultCacheWriteGuard(), 30000);

        $lock->run('key', static fn (): ?string => null, static fn (): string => 'x');
    }

    public function testItCapsTheWaitWhenTheQueryTimeoutIsUnlimited(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())->method('lock')->with('mageos_advancedreport_key', 60)->willReturn(true);
        $lock = $this->build($lockManager, new ResultCacheWriteGuard(), 0);

        $lock->run('key', static fn (): ?string => null, static fn (): string => 'x');
    }

    public function testItSkipsLockingWhenTheAdvancedReportCacheTypeIsDisabled(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::never())->method('lock');
        $lock = $this->build($lockManager, new ResultCacheWriteGuard(), 30000, false);

        self::assertSame('x', $lock->run('key', static fn (): ?string => null, static fn (): string => 'x'));
    }

    public function testItReReadsTheCacheAfterAcquiringTheLock(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $lockManager->expects(self::once())->method('unlock')->with('mageos_advancedreport_key');
        $lock = $this->build($lockManager, new ResultCacheWriteGuard());

        $value = $lock->run('key', static fn (): string => 'filled', static function (): never {
            throw new \LogicException('must not compute');
        });

        self::assertSame('filled', $value);
    }

    private function build(
        LockManagerInterface $lockManager,
        ResultCacheWriteGuard $guard,
        int $timeoutMs = 30000,
        bool $cacheEnabled = true,
    ): ResultCacheLock {
        $config = $this->createStub(Config::class);
        $config->method('getQueryTimeoutMs')->willReturn($timeoutMs);
        $state = $this->createStub(StateInterface::class);
        $state->method('isEnabled')->willReturnCallback(
            static fn (string $type): bool => $type === Type::TYPE_IDENTIFIER && $cacheEnabled
        );

        return new ResultCacheLock($lockManager, $guard, $config, $state);
    }
}
