<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Granularity;

/**
 * Inserts empty rows for date buckets missing from a result so date series stay continuous
 * (e.g. a day with no orders still produces a zero row instead of a gap in a line chart).
 *
 * Row shape (task 017): `['dims' => .., 'dims_raw' => .., 'metrics' => [code => value]]`.
 * Inserted rows set `dims`/`dims_raw` to the bucket key on `$dimensionCode` and, for each
 * metric, `0` for additive aggregates ({@see AggregateFunction::SUM}, {@see
 * AggregateFunction::COUNT}, {@see AggregateFunction::COUNT_DISTINCT}) or `null` for
 * non-additive ones ({@see AggregateFunction::MIN}, {@see AggregateFunction::MAX}, {@see
 * AggregateFunction::AVG}). Runs before derived metrics so those are computed consistently
 * off the filled aggregates (e.g. `aov` becomes `null` on a zero-order bucket).
 *
 * Existing rows are matched and returned untouched by `dims_raw[$dimensionCode]`; the output
 * is always ordered by bucket key.
 */
class DateBucketFiller
{
    public function __construct(private readonly DateBucketSequence $bucketSequence)
    {
    }

    /**
     * Fills the date bucket sequence expected for `$range`/`$granularity`.
     *
     * @param array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}> $rows
     * @param string[] $metricCodes
     * @param string[] $timezones
     * @return array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}>
     */
    public function fillDateBuckets(
        Dataset $dataset,
        array $rows,
        string $dimensionCode,
        array $metricCodes,
        ResolvedDateRange $range,
        Granularity $granularity,
        int $firstDayOfWeek = 0,
        array $timezones = ['UTC'],
    ): array {
        $keys = $this->bucketSequence->generate($range, $granularity, $firstDayOfWeek, $timezones);

        return $this->fill($dataset, $rows, $dimensionCode, $metricCodes, $keys);
    }

    /**
     * Fills a fixed-domain dimension (e.g. `order_hour`'s 0-23 hours of day, `order_weekday`'s
     * 0-6 days of week) that has no {@see Granularity}: `$domain` lists every value the
     * dimension can take, in the order the output should be returned.
     *
     * @param array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}> $rows
     * @param string[] $metricCodes
     * @param string[] $domain
     * @return array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}>
     */
    public function fillFixedDomain(
        Dataset $dataset,
        array $rows,
        string $dimensionCode,
        array $metricCodes,
        array $domain,
    ): array {
        return $this->fill($dataset, $rows, $dimensionCode, $metricCodes, $domain);
    }

    /**
     * @param array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}> $rows
     * @param string[] $metricCodes
     * @param string[] $keys
     * @return array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}>
     */
    private function fill(Dataset $dataset, array $rows, string $dimensionCode, array $metricCodes, array $keys): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['dims_raw'][$dimensionCode]] = $row;
        }

        $filled = [];
        foreach ($keys as $key) {
            $filled[] = $indexed[$key] ?? $this->emptyRow($dataset, $dimensionCode, $key, $metricCodes);
        }

        return $filled;
    }

    /**
     * @param string[] $metricCodes
     * @return array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}
     */
    private function emptyRow(Dataset $dataset, string $dimensionCode, string $key, array $metricCodes): array
    {
        $metrics = [];
        foreach ($metricCodes as $metricCode) {
            $metrics[$metricCode] = $this->emptyValueFor($dataset->getMetric($metricCode)->aggregate);
        }

        return ['dims' => [$dimensionCode => $key], 'dims_raw' => [$dimensionCode => $key], 'metrics' => $metrics];
    }

    private function emptyValueFor(?AggregateFunction $aggregate): ?int
    {
        return match ($aggregate) {
            AggregateFunction::SUM, AggregateFunction::COUNT, AggregateFunction::COUNT_DISTINCT => 0,
            AggregateFunction::MIN, AggregateFunction::MAX, AggregateFunction::AVG, null => null,
        };
    }
}
