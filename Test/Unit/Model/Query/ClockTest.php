<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use Magento\Framework\Stdlib\DateTime\DateTime;
use MageOS\AdvancedReport\Model\Query\Clock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Clock::class)]
final class ClockTest extends TestCase
{
    public function testItResolvesTodayInTheGivenTimezoneWhenUtcIsAlreadyOnTheNextDay(): void
    {
        $dateTime = $this->createStub(DateTime::class);
        // 2026-03-16 01:00:00 UTC
        $dateTime->method('gmtTimestamp')->willReturn(1773622800);

        $today = (new Clock($dateTime))->today('America/Los_Angeles');

        self::assertSame('2026-03-15 00:00:00', $today->format('Y-m-d H:i:s'));
        self::assertSame(date_default_timezone_get(), $today->getTimezone()->getName());
    }

    public function testItResolvesTodayAheadOfUtcInTheGivenTimezone(): void
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1773622800);

        $today = (new Clock($dateTime))->today('Asia/Tokyo');

        self::assertSame('2026-03-16', $today->format('Y-m-d'));
    }

    public function testItReturnsTheCurrentUtcInstantFromTheClock(): void
    {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1773622800);

        $now = (new Clock($dateTime))->now();

        self::assertSame('2026-03-16 01:00:00', $now->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $now->getTimezone()->getName());
    }
}
