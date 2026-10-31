<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateTimeImmutable;
use DateTimeZone;
use MageOS\AdvancedReport\Model\Definition\Granularity;

/**
 * Merges a comparison-period result into the current-period result.
 *
 * Row shape (task 017): `['dims' => .., 'dims_raw' => .., 'metrics' => [code => value]]`;
 * totals: `['metrics' => [code => value]]`. Merged rows/totals gain a `comparison` key:
 * `[metricCode => ['value' => ?float, 'delta' => ?float, 'delta_pct' => ?float]]` (`delta_pct`
 * is a ratio, e.g. `0.234` = +23.4%, `null` when the previous value is zero or missing).
 *
 * Rows are matched by raw dimension values (`dims_raw`), never resolved labels: two rows can
 * share a label (e.g. two stores with the same name) but never the same raw id. Rows present
 * only in the comparison result are dropped: the current-period rows drive the output table.
 * Rows with no match in the comparison result get a `null` comparison value.
 *
 * When the request has a date bucket dimension, pass a {@see DateBucketAlignment} so its raw
 * value is aligned before matching: hour/day buckets are shifted by
 * {@see ComparisonPeriod::$offsetDays} (previous period) or one calendar year (previous year);
 * week/month/year buckets use a calendar shift (same bucket one year earlier, or N buckets
 * earlier where N is the number of buckets of the current range).
 */
class ComparisonMerger
{
    public function __construct(private readonly DateBucketSequence $bucketSequence)
    {
    }

    /**
     * @param array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}> $currentRows
     * @param array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}> $comparisonRows
     * @param string[] $metricCodes
     * @return array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>, comparison: array<string,array{value: ?float, delta: ?float, delta_pct: ?float}>}>
     */
    public function mergeRows(
        array $currentRows,
        array $comparisonRows,
        array $metricCodes,
        ?DateBucketAlignment $alignment = null,
    ): array {
        $index = $this->indexByKey($comparisonRows);

        foreach ($currentRows as &$row) {
            $lookupDimsRaw = $this->toLookupDimsRaw($row['dims_raw'], $alignment);
            $comparisonRow = $index[$this->buildKey($lookupDimsRaw)] ?? null;
            $row['comparison'] = $this->buildComparison($metricCodes, $row['metrics'], $comparisonRow['metrics'] ?? null);
        }
        unset($row);

        return $currentRows;
    }

    /**
     * @param array{metrics: array<string,int|float|null>} $totals
     * @param array{metrics: array<string,int|float|null>} $comparisonTotals
     * @param string[] $metricCodes
     * @return array{metrics: array<string,int|float|null>, comparison: array<string,array{value: ?float, delta: ?float, delta_pct: ?float}>}
     */
    public function mergeTotals(array $totals, array $comparisonTotals, array $metricCodes): array
    {
        $totals['comparison'] = $this->buildComparison($metricCodes, $totals['metrics'], $comparisonTotals['metrics']);

        return $totals;
    }

    /**
     * @param array<string,mixed> $dimsRaw
     * @return array<string,mixed>
     */
    private function toLookupDimsRaw(array $dimsRaw, ?DateBucketAlignment $alignment): array
    {
        if ($alignment === null || !\array_key_exists($alignment->dimensionCode, $dimsRaw)) {
            return $dimsRaw;
        }

        $dimsRaw[$alignment->dimensionCode] = $this->toComparisonBucketKey(
            (string) $dimsRaw[$alignment->dimensionCode],
            $alignment,
        );

        return $dimsRaw;
    }

    private function toComparisonBucketKey(string $currentKey, DateBucketAlignment $alignment): ?string
    {
        return match ($alignment->granularity) {
            Granularity::HOUR => $this->shiftByOffset($currentKey, 'Y-m-d H:00', $alignment),
            Granularity::DAY => $this->shiftByOffset($currentKey, 'Y-m-d', $alignment),
            Granularity::WEEK, Granularity::MONTH, Granularity::YEAR => $this->shiftByCalendar($currentKey, $alignment),
        };
    }

    private function shiftByOffset(string $currentKey, string $format, DateBucketAlignment $alignment): string
    {
        // Bucket keys are naive local strings: parse them in UTC so no DST conversion applies.
        $date = DateTimeImmutable::createFromFormat($format, $currentKey, new DateTimeZone('UTC'));
        if ($date === false) {
            return $currentKey;
        }

        $period = $alignment->comparisonPeriod;
        $shifted = $period->mode === CompareMode::PREVIOUS_YEAR
            ? CalendarYear::subtract($date)
            : $date->modify(\sprintf('-%d days', $period->offsetDays));

        return $shifted->format($format);
    }

    /**
     * Calendar shift of a week/month/year bucket key. Previous year: the same bucket one year
     * earlier (weeks: the week containing the same date one year earlier, so two adjacent weeks
     * can occasionally map onto the same previous-year week). Previous period: the bucket N
     * buckets earlier, N being the number of buckets the current range spans.
     */
    private function shiftByCalendar(string $currentKey, DateBucketAlignment $alignment): ?string
    {
        $format = match ($alignment->granularity) {
            Granularity::MONTH => '!Y-m',
            Granularity::YEAR => '!Y',
            default => '!Y-m-d',
        };
        $date = DateTimeImmutable::createFromFormat($format, $currentKey, new DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }

        $shifted = $alignment->comparisonPeriod->mode === CompareMode::PREVIOUS_YEAR
            ? $this->subtractYear($date, $alignment)
            : $this->subtractBuckets($date, $alignment);

        return $shifted->format(match ($alignment->granularity) {
            Granularity::MONTH => 'Y-m',
            Granularity::YEAR => 'Y',
            default => 'Y-m-d',
        });
    }

    private function subtractYear(DateTimeImmutable $date, DateBucketAlignment $alignment): DateTimeImmutable
    {
        $shifted = CalendarYear::subtract($date);

        return $alignment->granularity === Granularity::WEEK
            ? $this->bucketSequence->weekStart($shifted, $alignment->firstDayOfWeek)
            : $shifted;
    }

    private function subtractBuckets(DateTimeImmutable $date, DateBucketAlignment $alignment): DateTimeImmutable
    {
        $count = \count($this->bucketSequence->generate(
            $alignment->currentRange,
            $alignment->granularity,
            $alignment->firstDayOfWeek,
        ));

        return match ($alignment->granularity) {
            Granularity::WEEK => $date->modify(\sprintf('-%d weeks', $count)),
            Granularity::MONTH => $date->modify(\sprintf('-%d months', $count)),
            default => $date->modify(\sprintf('-%d years', $count)),
        };
    }

    /**
     * @param string[] $metricCodes
     * @param array<string,int|float|null> $currentMetrics
     * @param array<string,int|float|null>|null $comparisonMetrics
     * @return array<string,array{value: ?float, delta: ?float, delta_pct: ?float}>
     */
    private function buildComparison(array $metricCodes, array $currentMetrics, ?array $comparisonMetrics): array
    {
        $comparison = [];
        foreach ($metricCodes as $metricCode) {
            $current = $this->toFloat($currentMetrics[$metricCode] ?? null);
            $previous = $comparisonMetrics !== null ? $this->toFloat($comparisonMetrics[$metricCode] ?? null) : null;
            $delta = ($current !== null && $previous !== null) ? $current - $previous : null;
            $deltaPct = ($delta !== null && $previous !== null && $previous !== 0.0) ? $delta / abs($previous) : null;

            $comparison[$metricCode] = ['value' => $previous, 'delta' => $delta, 'delta_pct' => $deltaPct];
        }

        return $comparison;
    }

    private function toFloat(int|float|null $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * @param array<int,array{dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}> $rows
     * @return array<string,array{dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}>
     */
    private function indexByKey(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $index[$this->buildKey($row['dims_raw'])] = $row;
        }

        return $index;
    }

    /**
     * @param array<string,mixed> $dimsRaw
     */
    private function buildKey(array $dimsRaw): string
    {
        ksort($dimsRaw);

        return (string) json_encode($dimsRaw);
    }
}
