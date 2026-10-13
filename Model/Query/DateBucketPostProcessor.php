<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use LogicException;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Label\LabelResolverPool;

/**
 * Decides whether a report request targets a single date bucket dimension (a granularity-based
 * one, e.g. `order_date`, or a fixed-domain one, e.g. `order_hour`/`order_weekday`) and, when it
 * does, fetches the full bucket sequence and fills the gaps via {@see DateBucketFiller} so the
 * report runner (task 044) can set `total_row_count` to the filled count and paginate in PHP.
 *
 * The fixed-domain dimensions are configured declaratively (`di.xml`'s `fixedDomainDimensions`
 * argument) rather than hard-coded, so extension modules can register their own.
 *
 * Buckets inserted by the filler carry the raw key as label, so the dimension's label resolver
 * (if any) is re-applied to the filled rows (e.g. weekday `0` becomes "Sunday").
 */
class DateBucketPostProcessor
{
    /**
     * @param DateBucketFiller $bucketFiller
     * @param LabelResolverPool $labelResolverPool
     * @param array<string,string[]> $fixedDomainDimensions Dimension code => ordered domain values.
     */
    public function __construct(
        private readonly DateBucketFiller $bucketFiller,
        private readonly LabelResolverPool $labelResolverPool,
        private readonly array $fixedDomainDimensions = [],
    ) {
    }

    /**
     * Whether the request has exactly one dimension and it is a date bucket dimension (fixed
     * domain or granularity based).
     */
    public function supports(ReportRequestInterface $request): bool
    {
        $selection = $this->singleDimension($request);

        return $selection !== null
            && (isset($this->fixedDomainDimensions[$selection->code]) || $selection->granularity !== null);
    }

    /**
     * Fills the missing buckets for the single date bucket dimension of `$request`. Only call
     * when {@see supports()} returns true.
     *
     * @param array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}> $rows
     * @param string[] $metricCodes
     * @return array{rows: array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}>, totalRowCount: int}
     */
    public function process(
        Dataset $dataset,
        ReportRequestInterface $request,
        array $rows,
        array $metricCodes,
        ?ResolvedDateRange $range,
        int $firstDayOfWeek,
    ): array {
        $selection = $this->singleDimension($request)
            ?? throw new LogicException('process() called without a supported single date bucket dimension.');

        if (isset($this->fixedDomainDimensions[$selection->code])) {
            $filled = $this->bucketFiller->fillFixedDomain(
                $dataset,
                $rows,
                $selection->code,
                $metricCodes,
                $this->fixedDomainDimensions[$selection->code],
            );
            $filled = $this->applyLabels($dataset, $selection->code, $filled);

            return ['rows' => $filled, 'totalRowCount' => \count($filled)];
        }

        $range ?? throw new LogicException('A resolved date range is required to fill a granularity-based bucket dimension.');
        $granularity = $selection->granularity
            ?? throw new LogicException('The single dimension must declare a granularity.');

        $filled = $this->bucketFiller->fillDateBuckets(
            $dataset,
            $rows,
            $selection->code,
            $metricCodes,
            $range,
            $granularity,
            $firstDayOfWeek,
        );
        $filled = $this->applyLabels($dataset, $selection->code, $filled);

        return ['rows' => $filled, 'totalRowCount' => \count($filled)];
    }

    /**
     * @param array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}> $rows
     * @return array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}>
     */
    private function applyLabels(Dataset $dataset, string $dimensionCode, array $rows): array
    {
        $dimension = $dataset->dimensions[$dimensionCode] ?? null;
        $resolver = $dimension?->labelResolver;
        if ($dimension === null || $resolver === null || $rows === [] || !$this->labelResolverPool->has($resolver)) {
            return $rows;
        }

        $values = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['dims_raw'][$dimensionCode],
            $rows,
        )));
        $labels = $this->labelResolverPool->get($resolver)->resolve($values, $dimension->labelContext);
        foreach ($rows as $index => $row) {
            $raw = (string) $row['dims_raw'][$dimensionCode];
            $rows[$index]['dims'][$dimensionCode] = $labels[$raw] ?? $raw;
        }

        return $rows;
    }

    private function singleDimension(ReportRequestInterface $request): ?DimensionSelection
    {
        $dimensions = $request->getDimensions();

        return \count($dimensions) === 1 ? $dimensions[0] : null;
    }
}
