<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateTimeImmutable;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Query\DateBucketSequence;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateBucketSequence::class)]
final class DateBucketSequenceTest extends TestCase
{
    private DateBucketSequence $sequence;

    protected function setUp(): void
    {
        $this->sequence = new DateBucketSequence();
    }

    public function testItGeneratesDayWeekMonthYearAndHourBucketKeysForARange(): void
    {
        $range = $this->range('2026-01-15', '2026-03-05');

        self::assertSame(
            ['2026-01-15', '2026-01-16', '2026-01-17'],
            $this->sequence->generate($this->range('2026-01-15', '2026-01-17'), Granularity::DAY),
        );
        self::assertSame(
            ['2026-01-11'],
            $this->sequence->generate($this->range('2026-01-15', '2026-01-17'), Granularity::WEEK),
        );
        self::assertSame(['2026-01', '2026-02', '2026-03'], $this->sequence->generate($range, Granularity::MONTH));
        self::assertSame(
            ['2026', '2027'],
            $this->sequence->generate($this->range('2026-12-15', '2027-01-05'), Granularity::YEAR),
        );

        $hours = $this->sequence->generate($this->range('2026-03-10', '2026-03-10'), Granularity::HOUR);
        self::assertCount(24, $hours);
        self::assertSame('2026-03-10 00:00', $hours[0]);
    }

    public function testItGeneratesMonthBucketKeysForARange(): void
    {
        $keys = $this->sequence->generate($this->range('2026-01-15', '2026-03-05'), Granularity::MONTH);

        self::assertSame(['2026-01', '2026-02', '2026-03'], $keys);
    }

    public function testItGeneratesDayBucketKeysForARange(): void
    {
        $keys = $this->sequence->generate($this->range('2026-03-10', '2026-03-12'), Granularity::DAY);

        self::assertSame(['2026-03-10', '2026-03-11', '2026-03-12'], $keys);
    }

    public function testItGeneratesYearBucketKeysForARange(): void
    {
        $keys = $this->sequence->generate($this->range('2024-06-01', '2026-01-01'), Granularity::YEAR);

        self::assertSame(['2024', '2025', '2026'], $keys);
    }

    public function testItGeneratesHourBucketKeysForARange(): void
    {
        $keys = $this->sequence->generate($this->range('2026-03-10', '2026-03-10'), Granularity::HOUR);

        self::assertCount(24, $keys);
        self::assertSame('2026-03-10 00:00', $keys[0]);
        self::assertSame('2026-03-10 23:00', $keys[23]);
    }

    public function testItStartsWeekBucketsOnTheConfiguredFirstDayOfWeek(): void
    {
        // 2026-03-15 is a Sunday.
        $keys = $this->sequence->generate($this->range('2026-03-15', '2026-03-16'), Granularity::WEEK, 1);

        self::assertSame(['2026-03-09', '2026-03-16'], $keys);
    }

    private function range(string $from, string $to): ResolvedDateRange
    {
        $fromDate = new DateTimeImmutable($from);
        $toDate = new DateTimeImmutable($to);

        return new ResolvedDateRange(
            from: $fromDate,
            to: $toDate,
            includesToday: false,
            days: $fromDate->diff($toDate)->days + 1,
        );
    }
}
