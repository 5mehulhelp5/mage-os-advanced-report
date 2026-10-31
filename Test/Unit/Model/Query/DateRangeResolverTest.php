<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateTimeImmutable;
use MageOS\AdvancedReport\Model\Query\ClockInterface;
use MageOS\AdvancedReport\Model\Query\CompareMode;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DateRangeResolver;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use MageOS\AdvancedReport\Model\Query\ReportScope;
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

    private ReportScope $scope;

    protected function setUp(): void
    {
        $this->scope = new ReportScope(1, 'UTC', 0, ['UTC']);
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
        $resolved = $this->resolver->resolve(new DateRange($type), $this->scope);

        self::assertSame($expectedFrom, $resolved->from->format('Y-m-d'));
        self::assertSame($expectedTo, $resolved->to->format('Y-m-d'));
    }

    public function testItResolvesCustomRangesInclusively(): void
    {
        $resolved = $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2026-01-05', '2026-01-10'), $this->scope);

        self::assertSame('2026-01-05', $resolved->from->format('Y-m-d'));
        self::assertSame('2026-01-10', $resolved->to->format('Y-m-d'));
        self::assertSame(6, $resolved->days);
    }

    public function testItComputesThePreviousPeriodWithTheSameNumberOfDays(): void
    {
        $range = $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2026-01-10', '2026-01-14'), $this->scope);

        $previous = $this->resolver->previousPeriod($range, $this->scope);

        self::assertSame('2026-01-05', $previous->range->from->format('Y-m-d'));
        self::assertSame('2026-01-09', $previous->range->to->format('Y-m-d'));
        self::assertSame($range->days, $previous->range->days);
        self::assertSame(5, $previous->offsetDays);
        self::assertSame(CompareMode::PREVIOUS_PERIOD, $previous->mode);
    }

    public function testItResolvesThePreviousPeriodOfAWholeCalendarMonthAsThePrecedingWholeMonth(): void
    {
        $range = $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2024-10-01', '2024-10-31'), $this->scope);

        $previous = $this->resolver->previousPeriod($range, $this->scope);

        self::assertSame('2024-09-01', $previous->range->from->format('Y-m-d'));
        self::assertSame('2024-09-30', $previous->range->to->format('Y-m-d'));
        self::assertSame(30, $previous->range->days);
        self::assertSame(30, $previous->offsetDays);
    }

    public function testItResolvesThePreviousPeriodOfWholeMonthsSpanningSeveralMonthsAsThePrecedingSameNumberOfMonths(): void
    {
        $range = $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2024-02-01', '2024-04-30'), $this->scope);

        $previous = $this->resolver->previousPeriod($range, $this->scope);

        self::assertSame('2023-11-01', $previous->range->from->format('Y-m-d'));
        self::assertSame('2024-01-31', $previous->range->to->format('Y-m-d'));

        $year = $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2024-01-01', '2024-12-31'), $this->scope);
        $previousYear = $this->resolver->previousPeriod($year, $this->scope);

        self::assertSame('2023-01-01', $previousYear->range->from->format('Y-m-d'));
        self::assertSame('2023-12-31', $previousYear->range->to->format('Y-m-d'));
    }

    public function testItKeepsTheDayCountPreviousPeriodForRangesThatAreNotWholeMonthsOrYears(): void
    {
        $range = $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2024-10-01', '2024-10-30'), $this->scope);

        $previous = $this->resolver->previousPeriod($range, $this->scope);

        self::assertSame('2024-09-01', $previous->range->from->format('Y-m-d'));
        self::assertSame('2024-09-30', $previous->range->to->format('Y-m-d'));

        $partial = $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2024-10-02', '2024-10-31'), $this->scope);
        $previousPartial = $this->resolver->previousPeriod($partial, $this->scope);

        self::assertSame('2024-09-02', $previousPartial->range->from->format('Y-m-d'));
        self::assertSame('2024-10-01', $previousPartial->range->to->format('Y-m-d'));
    }

    public function testItUsesTheSameAlignedPreviousPeriodRangeForTheComparisonTotalsAndTheRowAlignment(): void
    {
        // QueryPlanner::plan() and ReportRunner::buildAlignment() both call previousPeriod()
        // with the main query range and scope: the result must be identical.
        $range = $this->resolver->resolve(new DateRange(DateRangeType::LAST_MONTH), $this->scope);

        $forTotals = $this->resolver->previousPeriod($range, $this->scope);
        $forRows = $this->resolver->previousPeriod($range, $this->scope);

        self::assertEquals($forTotals, $forRows);
        self::assertSame('2026-01-01', $forTotals->range->from->format('Y-m-d'));
        self::assertSame('2026-01-31', $forTotals->range->to->format('Y-m-d'));
    }

    public function testItComputesThePreviousYearAndClampsFebruary29(): void
    {
        $range = $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2024-02-29', '2024-02-29'), $this->scope);

        $previous = $this->resolver->previousYear($range, $this->scope);

        self::assertSame('2023-02-28', $previous->range->from->format('Y-m-d'));
        self::assertSame('2023-02-28', $previous->range->to->format('Y-m-d'));
        self::assertSame(1, $previous->range->days);
    }

    public function testItStoresTheComparisonOffsetAsAWholeNumberOfDaysWithTheCompareMode(): void
    {
        $range = $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2024-04-01', '2024-06-29'), $this->scope);

        $previousPeriod = $this->resolver->previousPeriod($range, $this->scope);
        $previousYear = $this->resolver->previousYear($range, $this->scope);

        self::assertSame(CompareMode::PREVIOUS_PERIOD, $previousPeriod->mode);
        self::assertSame(90, $previousPeriod->offsetDays);
        self::assertSame(CompareMode::PREVIOUS_YEAR, $previousYear->mode);
        self::assertSame(366, $previousYear->offsetDays);
    }

    public function testItFlagsRangesThatIncludeToday(): void
    {
        $includesToday = $this->resolver->resolve(new DateRange(DateRangeType::LAST_7_DAYS), $this->scope);
        $doesNotIncludeToday = $this->resolver->resolve(
            new DateRange(DateRangeType::CUSTOM, '2026-01-01', '2026-01-05'),
            $this->scope
        );

        self::assertTrue($includesToday->includesToday);
        self::assertFalse($doesNotIncludeToday->includesToday);
    }

    public function testItRejectsRangesWhereFromIsAfterTo(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2026-01-10', '2026-01-05'), $this->scope);
    }

    public function testItResolvesRelativeDateRangesWithTheReportScopeTimezone(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::atLeastOnce())->method('today')->with('Asia/Tokyo')
            ->willReturn(new DateTimeImmutable(self::TODAY));
        $resolver = new DateRangeResolver($clock);
        $scope = new ReportScope(null, 'Asia/Tokyo', 0, ['Asia/Tokyo']);

        $range = $resolver->resolve(new DateRange(DateRangeType::LAST_7_DAYS), $scope);
        $resolver->previousPeriod($range, $scope);

        self::assertSame('2026-03-15', $range->to->format('Y-m-d'));
    }

    public function testItFlagsACustomRangeContainingTheLocalTodayAsIncludingTodayInANonUtcScope(): void
    {
        // Local today is already 2026-03-16 in the scope timezone, as midnight in the default zone.
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('today')->willReturn(new DateTimeImmutable('2026-03-16'));
        $resolver = new DateRangeResolver($clock);
        $scope = new ReportScope(1, 'Asia/Tokyo', 0, ['Asia/Tokyo']);

        $resolved = $resolver->resolve(new DateRange(DateRangeType::CUSTOM, '2026-03-16', '2026-03-16'), $scope);

        self::assertTrue($resolved->includesToday);
    }
}
