<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;

/**
 * Fallback for databases without timezone tables: rows grouped by UTC slot in SQL are
 * re-aggregated here into local hour/day/week/month/year/hour-of-day/weekday buckets.
 *
 * Exact for sums, counts, min/max and `count_distinct(order_id)` (each order falls in exactly one
 * hour). Other distinct counts and averages cannot be merged: they are nulled with a warning.
 * Output is unsorted and unpaginated; bucket keys match the SQL buckets of {@see TimezoneStrategy}.
 */
class UtcHourReaggregator
{
    private const string UTC_HOUR_KEY = 'utc_hour';
    private const string ADDITIVE_DISTINCT_FIELD = 'order_id';

    public function __construct(
        private readonly StoreTimezoneMap $timezoneMap,
    ) {
    }

    /**
     * @param array<int,array{dims_raw: array<string,?string>, metrics: array<string,int|float|null>}> $rows
     *                                                                                                       Rows carrying `utc_hour` (`Y-m-d H:i:00`, a 60 or 15 minute UTC slot) and, when timezones differ, the store id.
     * @param string $bucketDimCode Dimension code the local bucket is written to.
     * @param string $bucket hour|day|week|month|year|hour_of_day|weekday
     * @param Metric[] $metrics Aggregate metrics to merge (others are ignored).
     * @param int[] $storeIds
     * @param int $firstDayOfWeek 0 (Sunday) to 6 (Saturday), used by the week bucket.
     * @param string|null $storeKey Key in `dims_raw` holding the store id (multi-timezone only).
     * @param bool $dropStoreKey Remove the store key from output (when it is only a helper column).
     * @return array{rows: array<int,array{dims: array<string,?string>, dims_raw: array<string,?string>, metrics: array<string,int|float|null>}>, warnings: string[]}
     */
    public function reaggregate(
        array $rows,
        string $bucketDimCode,
        string $bucket,
        array $metrics,
        array $storeIds,
        int $firstDayOfWeek,
        ?string $storeKey = null,
        bool $dropStoreKey = false,
    ): array {
        $metrics = array_values(array_filter($metrics, static fn (Metric $m): bool => $m->isAggregate()));
        $groups = [];
        $timezones = $this->timezoneMap->getTimezonesForStores($storeIds);
        $defaultTimezone = $this->timezoneMap->getDefaultTimezone();
        $filterTimezone = (string) (reset($timezones) ?: $defaultTimezone);

        foreach ($rows as $row) {
            $raw = $row['dims_raw'];
            $utcHour = (string) $raw[self::UTC_HOUR_KEY];
            $timezone = $storeKey !== null
                ? ($timezones[(int) $raw[$storeKey]] ?? $defaultTimezone)
                : $filterTimezone;
            unset($raw[self::UTC_HOUR_KEY]);
            if ($dropStoreKey && $storeKey !== null) {
                unset($raw[$storeKey]);
            }
            $raw[$bucketDimCode] = $this->bucketValue($bucket, $utcHour, $timezone, $firstDayOfWeek);

            $key = json_encode($raw, JSON_THROW_ON_ERROR);
            $groups[$key]['dims'] = $raw;
            foreach ($metrics as $metric) {
                $groups[$key]['metrics'][$metric->code] = $this->merge(
                    $metric,
                    $groups[$key]['metrics'][$metric->code] ?? null,
                    $row['metrics'][$metric->code] ?? null,
                );
            }
        }

        return [
            'rows' => array_values(array_map(
                static fn (array $group): array => [
                    'dims' => $group['dims'],
                    'dims_raw' => $group['dims'],
                    'metrics' => $group['metrics'] ?? [],
                ],
                $groups
            )),
            'warnings' => [
                ...$this->unsupportedWarnings($metrics),
            ],
        ];
    }

    private function bucketValue(string $bucket, string $utcHour, string $timezone, int $firstDayOfWeek): string
    {
        $local = (new DateTimeImmutable($utcHour, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($timezone));

        return match ($bucket) {
            'hour' => $local->format('Y-m-d H:00'),
            'day' => $local->format('Y-m-d'),
            'week' => $local
                ->modify(\sprintf('-%d days', ((int) $local->format('w') - $firstDayOfWeek % 7 + 7) % 7))
                ->format('Y-m-d'),
            'month' => $local->format('Y-m'),
            'year' => $local->format('Y'),
            'hour_of_day' => (string) (int) $local->format('G'),
            'weekday' => $local->format('w'),
            default => throw new InvalidArgumentException(\sprintf('Unknown bucket "%s".', $bucket)),
        };
    }

    private function merge(Metric $metric, int|float|null $carry, int|float|null $value): int|float|null
    {
        if (!$this->isSupported($metric) || $value === null) {
            return $this->isSupported($metric) ? $carry : null;
        }
        if ($carry === null) {
            return $value;
        }

        return match ($metric->aggregate) {
            AggregateFunction::MIN => min($carry, $value),
            AggregateFunction::MAX => max($carry, $value),
            default => $carry + $value,
        };
    }

    private function isSupported(Metric $metric): bool
    {
        return match ($metric->aggregate) {
            AggregateFunction::SUM,
            AggregateFunction::COUNT,
            AggregateFunction::MIN,
            AggregateFunction::MAX => true,
            AggregateFunction::COUNT_DISTINCT => $metric->fieldCode === self::ADDITIVE_DISTINCT_FIELD,
            default => false,
        };
    }

    /**
     * @param Metric[] $metrics
     * @return string[]
     */
    private function unsupportedWarnings(array $metrics): array
    {
        $warnings = [];
        foreach ($metrics as $metric) {
            if (!$this->isSupported($metric)) {
                $warnings[] = (string) new Phrase(
                    'The "%1" metric is not available because the database has no timezone tables.',
                    [$metric->label],
                );
            }
        }

        return $warnings;
    }
}
