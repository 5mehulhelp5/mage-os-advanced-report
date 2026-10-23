<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\FieldType;

/**
 * Decides whether a request is monetary, i.e. its results or filters depend on currency amounts.
 *
 * A request is monetary when a requested metric is formatted as money, when a requested derived
 * metric (even a percent ratio such as margin_pct) is built from money aggregates, or when a filter
 * targets a money field. Sort can only target selected metrics, so it needs no separate rule.
 */
class MonetaryRequestDetector
{
    public function __construct(private readonly DerivedMetricCalculator $derivedMetricCalculator)
    {
    }

    /**
     * Whether the request involves monetary values.
     *
     * @param Dataset $dataset
     * @param ReportRequestInterface $request
     */
    public function isMonetary(Dataset $dataset, ReportRequestInterface $request): bool
    {
        return $this->hasMoneyMetric($dataset, $request->getMetrics())
            || $this->hasMoneyFilter($dataset, $request->getFilters());
    }

    /**
     * @param Dataset $dataset
     * @param string[] $metricCodes
     */
    private function hasMoneyMetric(Dataset $dataset, array $metricCodes): bool
    {
        $codes = array_merge($metricCodes, $this->derivedMetricCalculator->getRequiredAggregates($dataset, $metricCodes));
        foreach ($codes as $code) {
            if (isset($dataset->metrics[$code]) && $dataset->metrics[$code]->format === 'money') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Dataset $dataset
     * @param FilterCondition[] $filters
     */
    private function hasMoneyFilter(Dataset $dataset, array $filters): bool
    {
        foreach ($filters as $filter) {
            if ($dataset->hasField($filter->field) && $dataset->getField($filter->field)->type === FieldType::MONEY) {
                return true;
            }
        }

        return false;
    }
}
