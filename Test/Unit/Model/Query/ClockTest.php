<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MageOS\AdvancedReport\Model\Query\Clock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Clock::class)]
final class ClockTest extends TestCase
{
    public function testTodayReturnsTheCurrentScopeDateAtMidnight(): void
    {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->expects(self::once())
            ->method('date')
            ->with(null, null, true, false)
            ->willReturn(new DateTime('2026-03-15 18:30:00'));

        $today = (new Clock($timezone))->today();

        self::assertSame('2026-03-15 00:00:00', $today->format('Y-m-d H:i:s'));
    }
}
