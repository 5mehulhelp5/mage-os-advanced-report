<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Metric;

/**
 * Builds the SQL aggregate expression of an aggregate metric.
 *
 * SUM aggregates are wrapped in COALESCE(..., 0) so empty periods report 0; AVG/MIN/MAX stay NULL on no rows.
 * Derived ratios are null (never 0) when their denominator is zero, see DerivedMetricCalculator.
 */
class AggregateExpressionBuilder
{
    public function __construct(private readonly FieldExpressionResolver $fieldExpressionResolver)
    {
    }

    /**
     * Aggregate expression, wrapping the field in a CASE when the metric has a condition field.
     *
     * @param Dataset $dataset
     * @param Metric $metric
     * @param AdapterInterface $connection
     */
    public function build(Dataset $dataset, Metric $metric, AdapterInterface $connection): string
    {
        $function = $metric->aggregate ?? AggregateFunction::SUM;
        $expression = $this->fieldExpressionResolver->resolve($dataset, (string) $metric->fieldCode, $connection);
        if ($metric->conditionFieldCode !== null) {
            $condition = $this->fieldExpressionResolver->resolve($dataset, $metric->conditionFieldCode, $connection);
            $else = $function === AggregateFunction::SUM ? ' ELSE 0' : '';
            $expression = \sprintf('CASE WHEN %s THEN %s%s END', $condition, $expression, $else);
        }

        return match ($function) {
            AggregateFunction::COUNT_DISTINCT => \sprintf('COUNT(DISTINCT %s)', $expression),
            AggregateFunction::SUM => \sprintf('COALESCE(SUM(%s), 0)', $expression),
            default => \sprintf('%s(%s)', strtoupper($function->value), $expression),
        };
    }

    /**
     * Dataset field codes the metric's expression relies on.
     *
     * @param Metric $metric
     * @return string[]
     */
    public function getFieldCodes(Metric $metric): array
    {
        return array_values(array_filter([$metric->fieldCode, $metric->conditionFieldCode]));
    }
}
