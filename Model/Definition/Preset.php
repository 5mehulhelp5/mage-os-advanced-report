<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * A ready-to-run report configuration on top of a dataset: which metrics and
 * dimensions to show, default filters/sort/date range and supported sources.
 */
final readonly class Preset
{
    /**
     * @param string $code
     * @param string $area One of sales|catalog|customers|carts.
     * @param string $label
     * @param string $dataset Dataset code this preset queries.
     * @param string[] $metrics Metric codes.
     * @param array<int,array{code:string,granularity:Granularity|null}> $dimensions
     * @param array<int,array{field:string,operator:FilterOperator,value:mixed}> $filters
     * @param array<int,array{metric:string,direction:string}> $sort
     * @param string $compareTo One of none|previous_period|previous_year.
     * @param string|null $dateRange
     * @param string[] $sources Data source names this preset can run against (e.g. "mysql").
     * @param string|null $presetQuery
     * @param string|null $alternateDimension
     * @param string $description Short text shown on the report index page.
     */
    public function __construct(
        public string $code,
        public string $area,
        public string $label,
        public string $dataset,
        public array $metrics,
        public array $dimensions,
        public array $filters,
        public array $sort,
        public string $compareTo,
        public ?string $dateRange,
        public array $sources,
        public ?string $presetQuery = null,
        public ?string $alternateDimension = null,
        public string $description = '',
    ) {
    }
}
