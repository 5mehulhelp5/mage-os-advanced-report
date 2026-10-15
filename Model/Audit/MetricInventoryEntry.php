<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Audit;

use MageOS\AdvancedReport\Model\Definition\AggregateFunction;

/**
 * One row of the metric inventory: a metric of a dataset mapped to its tables, joins,
 * aggregation and context dependencies, with the derived fan out (row multiplication) risk.
 */
final readonly class MetricInventoryEntry
{
    public const string KIND_AGGREGATE = 'aggregate';
    public const string KIND_DERIVED = 'derived';
    public const string KIND_VIRTUAL = 'virtual';

    public const string DEPENDENCY_TIMEZONE = 'timezone';
    public const string DEPENDENCY_CURRENCY = 'currency';
    public const string DEPENDENCY_COUNTED_STATUSES = 'counted_statuses';
    public const string DEPENDENCY_STOCK_PROVIDER = 'stock_provider';

    /**
     * @param string $datasetCode
     * @param string $metricCode
     * @param string $label
     * @param string $kind One of the KIND_* constants.
     * @param bool $hidden
     * @param AggregateFunction|null $aggregateFunction
     * @param string|null $expression Mysql expression, `provider:<name>`, or the raw formula for derived metrics.
     * @param string|null $baseTable
     * @param string|null $baseProvider
     * @param string[] $joins Join codes, transitive.
     * @param string[] $fanOutJoins Subset of joins with many cardinality.
     * @param string[] $fanOutDimensions Dimension codes backed by a many join (additive metrics only).
     * @param string[] $dependencies Subset of the DEPENDENCY_* constants.
     * @param bool $additive Whether the metric is summed over rows (sum, count, avg or derived from those).
     */
    public function __construct(
        public string $datasetCode,
        public string $metricCode,
        public string $label,
        public string $kind,
        public bool $hidden,
        public ?AggregateFunction $aggregateFunction,
        public ?string $expression,
        public ?string $baseTable,
        public ?string $baseProvider,
        public array $joins,
        public array $fanOutJoins,
        public array $fanOutDimensions,
        public array $dependencies,
        public bool $additive,
    ) {
    }

    /**
     * Whether row multiplication can inflate this metric.
     */
    public function isFanOutRisk(): bool
    {
        return $this->additive && ($this->fanOutJoins !== [] || $this->fanOutDimensions !== []);
    }
}
