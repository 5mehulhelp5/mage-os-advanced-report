<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Benchmark;

use MageOS\AdvancedReport\Model\Benchmark\BarrierClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BarrierClock::class)]
final class BarrierClockTest extends TestCase
{
    public function testItWaitsUntilTheGivenEpochInstant(): void
    {
        $clock = new BarrierClock();
        $target = $clock->nowMs() + 60;

        $clock->waitUntil($target);

        self::assertGreaterThanOrEqual($target, $clock->nowMs());
    }

    public function testItReturnsImmediatelyWhenTheInstantIsInThePast(): void
    {
        $clock = new BarrierClock();
        $start = hrtime(true);

        $clock->waitUntil($clock->nowMs() - 10_000);

        self::assertLessThan(100, (hrtime(true) - $start) / 1_000_000);
    }
}
