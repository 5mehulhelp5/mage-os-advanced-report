<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use MageOS\AdvancedReport\Model\Query\ResultCacheWriteGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResultCacheWriteGuard::class)]
final class ResultCacheWriteGuardTest extends TestCase
{
    public function testItSuppressesStoringOnlyWhileTheCallbackRuns(): void
    {
        $guard = new ResultCacheWriteGuard();

        self::assertFalse($guard->isStoreSuppressed());
        $value = $guard->runWithoutStore(fn (): array => [$guard->isStoreSuppressed(), 'done']);

        self::assertSame([true, 'done'], $value);
        self::assertFalse($guard->isStoreSuppressed());
    }

    public function testItRestoresCacheSavingAfterTheExportEvenWhenItFails(): void
    {
        $guard = new ResultCacheWriteGuard();

        try {
            $guard->runWithoutStore(static function (): never {
                throw new \RuntimeException('export failed');
            });
        } catch (\RuntimeException) {
            // expected: the failure propagates
        }

        self::assertFalse($guard->isStoreSuppressed());
    }

    public function testItStaysSuppressedUntilTheOutermostCallbackEnds(): void
    {
        $guard = new ResultCacheWriteGuard();

        $afterInner = $guard->runWithoutStore(function () use ($guard): bool {
            $guard->runWithoutStore(static fn (): null => null);

            return $guard->isStoreSuppressed();
        });

        self::assertTrue($afterInner);
        self::assertFalse($guard->isStoreSuppressed());
    }
}
