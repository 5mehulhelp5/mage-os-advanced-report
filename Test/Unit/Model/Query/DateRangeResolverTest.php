<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateTimeImmutable;
use MageOS\AdvancedReport\Model\Query\ClockInterface;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DateRangeResolver;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateRangeResolver::class)]
final class DateRangeResolverTest extends TestCase
{
    /**
     * Sunday, so week-start semantics (not used here) can't accidentally make a test pass.
     */
    private const string TODAY = '2026-03-15';

    private DateRangeResolver $resolver;

    protected function setUp(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('today')->willReturn(new DateTimeImmutable(self::TODAY));

        $this->resolver = new DateRangeResolver($clock);
    }

    /**
     * @return array<string, array{DateRangeType, string, string}>
     */
    public static function relativeRangeProvider(): array
    {
        return [
            'today' => [DateRangeType::TODAY, '2026-03-15', '2026-03-15'],
            'last_7_days' => [DateRangeType::LAST_7_DAYS, '2026-03-09', '2026-03-15'],
            'last_30_days' => [DateRangeType::LAST_30_DAYS, '2026-02-14', '2026-03-15'],
            'month_to_date' => [DateRangeType::MONTH_TO_DATE, '2026-03-01', '2026-03-15'],
            'last_month' => [DateRangeType::LAST_MONTH, '2026-02-01', '2026-02-28'],
            'year_to_date' => [DateRangeType::YEAR_TO_DATE, '2026-01-01', '2026-03-15'],
        ];
    }

    #[DataProvider('relativeRangeProvider')]
    public function testItResolvesEachRelativeRangeAgainstTheInjectedToday(
        DateRangeType $type,
        string $expectedFrom,
        string $expectedTo,
    ): void {
        $resolved = $this->resolver->resolve(new DateRange($type));

        self::assertSame($expectedFrom, $resolved->from->format('Y-m-d'));
        self::assertSame($expectedTo, $resolved->to->format('Y-m-d'));
    }

    public function testItResolvesCustomRangesInclusively(): void
    {
        $resolved = $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2026-01-05', '2026-01-10'));

        self::assertSame('2026-01-05', $resolved->from->format('Y-m-d'));
        self::assertSame('2026-01-10', $resolved->to->format('Y-m-d'));
        self::assertSame(6, $resolved->days);
    }

    public function testItComputesThePreviousPeriodWithTheSameNumberOfDays(): void
    {
        $range = $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2026-01-10', '2026-01-14'));

        $previous = $this->resolver->previousPeriod($range);

        self::assertSame('2026-01-05', $previous->range->from->format('Y-m-d'));
        self::assertSame('2026-01-09', $previous->range->to->format('Y-m-d'));
        self::assertSame($range->days, $previous->range->days);
        self::assertSame(5, $previous->offset->days);
    }

    public function testItComputesThePreviousYearAndClampsFebruary29(): void
    {
        $range = $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2024-02-29', '2024-02-29'));

        $previous = $this->resolver->previousYear($range);

        self::assertSame('2023-02-28', $previous->range->from->format('Y-m-d'));
        self::assertSame('2023-02-28', $previous->range->to->format('Y-m-d'));
        self::assertSame(1, $previous->range->days);
    }

    public function testItFlagsRangesThatIncludeToday(): void
    {
        $includesToday = $this->resolver->resolve(new DateRange(DateRangeType::LAST_7_DAYS));
        $doesNotIncludeToday = $this->resolver->resolve(
            new DateRange(DateRangeType::CUSTOM, '2026-01-01', '2026-01-05')
        );

        self::assertTrue($includesToday->includesToday);
        self::assertFalse($doesNotIncludeToday->includesToday);
    }

    public function testItRejectsRangesWhereFromIsAfterTo(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2026-01-10', '2026-01-05'));
    }
}
