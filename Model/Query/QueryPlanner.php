<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;

/**
 * Turns a {@see ReportRequestInterface} into source-independent, ready-to-execute
 * {@see ResolvedQuery} objects: validates the request (task 013), resolves the reporting and,
 * when requested, comparison date range (task 012), and computes the aggregate metrics
 * actually needed, including those required transitively by derived metrics (task 014).
 *
 * Reused as-is by the report runner (task 044) and the `advanced-report:explain` CLI
 * (task 028, which plans with `$checkAcl = false` since no admin session exists there).
 */
class QueryPlanner
{
    public function __construct(
        private readonly RequestValidator $requestValidator,
        private readonly DefinitionRegistry $definitionRegistry,
        private readonly DateRangeResolver $dateRangeResolver,
        private readonly DerivedMetricCalculator $derivedMetricCalculator,
    ) {
    }

    /**
     * @param ReportRequestInterface $request
     * @param bool $checkAcl Skipped only by the trusted `advanced-report:explain` CLI.
     * @return array{main: ResolvedQuery, comparison: ?ResolvedQuery}
     */
    public function plan(ReportRequestInterface $request, bool $checkAcl = true): array
    {
        $this->requestValidator->validate($request, $checkAcl);

        $dataset = $this->definitionRegistry->getDataset($request->getDataset());
        $aggregates = $this->derivedMetricCalculator->getRequiredAggregates($dataset, $request->getMetrics());
        $range = $dataset->dateless ? null : $this->dateRangeResolver->resolve($request->getDateRange());

        return [
            'main' => $this->buildQuery($dataset, $aggregates, $request, $range),
            'comparison' => $this->planComparison($dataset, $aggregates, $request, $range),
        ];
    }

    /**
     * @param string[] $aggregates
     */
    private function planComparison(
        Dataset $dataset,
        array $aggregates,
        ReportRequestInterface $request,
        ?ResolvedDateRange $range,
    ): ?ResolvedQuery {
        if ($range === null || $request->getCompareTo() === CompareMode::NONE) {
            return null;
        }

        $comparisonPeriod = match ($request->getCompareTo()) {
            CompareMode::PREVIOUS_PERIOD => $this->dateRangeResolver->previousPeriod($range),
            CompareMode::PREVIOUS_YEAR => $this->dateRangeResolver->previousYear($range),
        };

        return $this->buildQuery($dataset, $aggregates, $request, $comparisonPeriod->range);
    }

    /**
     * @param string[] $aggregates
     */
    private function buildQuery(
        Dataset $dataset,
        array $aggregates,
        ReportRequestInterface $request,
        ?ResolvedDateRange $range,
    ): ResolvedQuery {
        return new ResolvedQuery(
            dataset: $dataset,
            metrics: $aggregates,
            dimensions: $request->getDimensions(),
            filters: $request->getFilters(),
            dateRange: $range,
            storeIds: $request->getStoreIds(),
            sort: $request->getSort(),
            limit: $request->getLimit(),
            offset: $request->getOffset(),
        );
    }
}
