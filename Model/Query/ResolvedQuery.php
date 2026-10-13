<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * A {@see \MageOS\AdvancedReport\Api\Data\ReportRequestInterface} planned into
 * source-independent, ready-to-execute terms: the dataset, the aggregate metrics actually
 * needed (including those required transitively by derived metrics), dimensions with their
 * date granularity, filters, the resolved date range, store scope and result shaping.
 *
 * Built by the query planner (task 018) and executed by a
 * {@see \MageOS\AdvancedReport\Api\DataSourceInterface}.
 */
final readonly class ResolvedQuery
{
    /**
     * @param Dataset $dataset
     * @param string[] $metrics Required aggregate metric codes.
     * @param DimensionSelection[] $dimensions
     * @param FilterCondition[] $filters
     * @param ResolvedDateRange|null $dateRange Null for dateless datasets.
     * @param int[] $storeIds Store ids this query is scoped to; empty means all stores.
     * @param SortOrder[] $sort
     * @param int|null $limit Maximum number of rows to return, null for no explicit limit.
     * @param int $offset Number of rows to skip.
     */
    public function __construct(
        public Dataset $dataset,
        public array $metrics,
        public array $dimensions,
        public array $filters,
        public ?ResolvedDateRange $dateRange,
        public array $storeIds,
        public array $sort,
        public ?int $limit,
        public int $offset,
    ) {
    }
}
