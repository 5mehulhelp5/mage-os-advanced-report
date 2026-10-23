<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateTimeImmutable;
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
     * The period of the same length immediately preceding `$range->from`.
     */
    public function previousPeriod(ResolvedDateRange $range, ReportScope $scope): ComparisonPeriod
    {
        $to = $range->from->modify('-1 day');
        $from = $to->modify(\sprintf('-%d days', $range->days - 1));

        return $this->toComparisonPeriod($from, $to, $range, $scope);
    }

    /**
     * The same dates one year before `$range`, clamping 29 February onto 28 February in
     * non-leap target years.
     */
    public function previousYear(ResolvedDateRange $range, ReportScope $scope): ComparisonPeriod
    {
        return $this->toComparisonPeriod(
            $this->subtractYear($range->from),
            $this->subtractYear($range->to),
            $range,
            $scope,
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
     * Resolves `$from..$to` and pairs it with the offset that maps its "from" onto
     * `$range->from`, for {@see previousPeriod()} and {@see previousYear()}.
     */
    private function toComparisonPeriod(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ResolvedDateRange $range,
        ReportScope $scope,
    ): ComparisonPeriod {
        $comparisonRange = $this->build($from, $to, $this->clock->today($scope->timezone));

        return new ComparisonPeriod($comparisonRange, $comparisonRange->from->diff($range->from));
    }

    /**
     * Subtracts one calendar year from `$date`, clamping 29 February onto 28 February
     * when the target year is not a leap year.
     */
    private function subtractYear(DateTimeImmutable $date): DateTimeImmutable
    {
        $year = (int) $date->format('Y') - 1;
        $month = (int) $date->format('m');
        $day = (int) $date->format('d');
        if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
            $day = 28;
        }

        return $date->setDate($year, $month, $day);
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}
