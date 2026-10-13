<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateTimeImmutable;
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
 * {@see ComparisonPeriod::$offset}; week/month/year buckets are matched by ordinal position in
 * the {@see DateBucketSequence} generated for each period, because a fixed day offset does not
 * line up those buckets (e.g. `last_30_days` vs. the previous 30 days, or 52 weeks vs. 365 days).
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
            Granularity::WEEK, Granularity::MONTH, Granularity::YEAR => $this->shiftByOrdinal($currentKey, $alignment),
        };
    }

    private function shiftByOffset(string $currentKey, string $format, DateBucketAlignment $alignment): string
    {
        $date = DateTimeImmutable::createFromFormat($format, $currentKey);
        if ($date === false) {
            return $currentKey;
        }

        return $date->sub($alignment->comparisonPeriod->offset)->format($format);
    }

    /**
     * Maps `$currentKey` to the bucket at the same ordinal position in the comparison period's
     * expected bucket sequence: a fixed offset does not line up week/month/year buckets (e.g.
     * `last_30_days` vs. the previous 30 days).
     */
    private function shiftByOrdinal(string $currentKey, DateBucketAlignment $alignment): ?string
    {
        $currentSequence = $this->bucketSequence->generate(
            $alignment->currentRange,
            $alignment->granularity,
            $alignment->firstDayOfWeek,
        );
        $comparisonSequence = $this->bucketSequence->generate(
            $alignment->comparisonPeriod->range,
            $alignment->granularity,
            $alignment->firstDayOfWeek,
        );

        $position = array_search($currentKey, $currentSequence, true);

        return $position === false ? null : ($comparisonSequence[$position] ?? null);
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
            $deltaPct = ($delta !== null && $previous !== 0.0) ? $delta / $previous : null;

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
