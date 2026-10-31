<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateTimeImmutable;
use DateTimeZone;
use Magento\Framework\Phrase;

/**
 * Resolves a {@see DateRange} request into a concrete, inclusive local-date interval.
 *
 * "Today" comes from the injected {@see ClockInterface} so relative ranges resolve
 * deterministically in tests, in the timezone of the {@see ReportScope}.
 */
class DateRangeResolver
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /**
     * @throws InvalidRequestException when the resolved range has "from" after "to"
     */
    public function resolve(DateRange $dateRange, ReportScope $scope): ResolvedDateRange
    {
        $today = $this->clock->today($scope->timezone);

        [$from, $to] = match ($dateRange->type) {
            DateRangeType::TODAY => [$today, $today],
            DateRangeType::LAST_7_DAYS => [$today->modify('-6 days'), $today],
            DateRangeType::LAST_30_DAYS => [$today->modify('-29 days'), $today],
            DateRangeType::MONTH_TO_DATE => [$today->modify('first day of this month'), $today],
            DateRangeType::LAST_MONTH => [
                $today->modify('first day of last month'),
                $today->modify('last day of last month'),
            ],
            DateRangeType::YEAR_TO_DATE => [$today->setDate((int) $today->format('Y'), 1, 1), $today],
            DateRangeType::CUSTOM => [
                $this->parseDate((string) $dateRange->from),
                $this->parseDate((string) $dateRange->to),
            ],
        };

        return $this->build($from, $to, $today);
    }

    /**
     * The period immediately preceding `$range->from`: the preceding whole calendar months when
     * `$range` is made of whole months (a whole year is a whole number of months), otherwise
     * the same number of days. The result depends on the range only, never on the granularity.
     */
    public function previousPeriod(ResolvedDateRange $range, ReportScope $scope): ComparisonPeriod
    {
        if ($this->isWholeMonths($range)) {
            $months = $this->monthCount($range);
            $from = $range->from->modify(\sprintf('-%d months', $months));
            $to = $range->from->modify('-1 day');
        } else {
            $to = $range->from->modify('-1 day');
            $from = $to->modify(\sprintf('-%d days', $range->days - 1));
        }

        return $this->toComparisonPeriod($from, $to, $range, $scope, CompareMode::PREVIOUS_PERIOD);
    }

    /**
     * The same dates one year before `$range`, clamping 29 February onto 28 February in
     * non-leap target years.
     */
    public function previousYear(ResolvedDateRange $range, ReportScope $scope): ComparisonPeriod
    {
        return $this->toComparisonPeriod(
            CalendarYear::subtract($range->from),
            CalendarYear::subtract($range->to),
            $range,
            $scope,
            CompareMode::PREVIOUS_YEAR,
        );
    }

    /**
     * @throws InvalidRequestException when the resolved range has "from" after "to"
     */
    private function build(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $today): ResolvedDateRange
    {
        if ($from > $to) {
            throw new InvalidRequestException(
                new Phrase(
                    'The date range "from" (%1) must not be after "to" (%2).',
                    [$from->format('Y-m-d'), $to->format('Y-m-d')]
                )
            );
        }

        return new ResolvedDateRange(
            from: $from,
            to: $to,
            includesToday: $from <= $today && $today <= $to,
            days: $from->diff($to)->days + 1,
        );
    }

    /**
     * Resolves `$from..$to` and pairs it with the day offset that maps its "from" onto
     * `$range->from`, for {@see previousPeriod()} and {@see previousYear()}.
     */
    private function toComparisonPeriod(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ResolvedDateRange $range,
        ReportScope $scope,
        CompareMode $mode,
    ): ComparisonPeriod {
        $comparisonRange = $this->build($from, $to, $this->clock->today($scope->timezone));

        return new ComparisonPeriod($comparisonRange, $mode, $this->calendarDaysBetween($comparisonRange->from, $range->from));
    }

    /**
     * Whole days between two dates, computed on calendar dates so DST never yields fractions.
     */
    private function calendarDaysBetween(DateTimeImmutable $earlier, DateTimeImmutable $later): int
    {
        $utc = new DateTimeZone('UTC');

        return (int) (new DateTimeImmutable($earlier->format('Y-m-d'), $utc))
            ->diff(new DateTimeImmutable($later->format('Y-m-d'), $utc))
            ->days;
    }

    private function isWholeMonths(ResolvedDateRange $range): bool
    {
        return $range->from->format('j') === '1'
            && $range->to->format('Y-m-d') === $range->to->modify('last day of this month')->format('Y-m-d');
    }

    private function monthCount(ResolvedDateRange $range): int
    {
        return ((int) $range->to->format('Y') - (int) $range->from->format('Y')) * 12
            + (int) $range->to->format('n') - (int) $range->from->format('n') + 1;
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}
