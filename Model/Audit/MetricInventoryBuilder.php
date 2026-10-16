<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Audit;

use MageOS\AdvancedReport\Model\DataSource\Mysql\ImplicitFilterPool;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\JoinCardinality;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Query\DerivedMetricCalculator;

/**
 * Walks the definition registry and maps every metric of every dataset to its tables, joins,
 * aggregation and context dependencies. Pure: expression providers build correlated subqueries
 * that need a DB connection, so they are reported as `provider:<name>` and not analysed.
 */
class MetricInventoryBuilder
{
    private const string SOURCE = 'mysql';
    private const string FIRST_ORDER_PROVIDER = 'first_order';

    /**
     * @param DefinitionRegistry $definitionRegistry
     * @param DerivedMetricCalculator $derivedMetricCalculator
     */
    public function __construct(
        private readonly DefinitionRegistry $definitionRegistry,
        private readonly DerivedMetricCalculator $derivedMetricCalculator,
    ) {
    }

    /**
     * One entry per metric of every dataset.
     *
     * @return MetricInventoryEntry[]
     */
    public function build(): array
    {
        $entries = [];
        foreach ($this->definitionRegistry->getDatasets() as $dataset) {
            $fanOutDimensions = $this->getFanOutDimensions($dataset);
            foreach ($dataset->metrics as $metric) {
                $entries[] = $this->buildEntry($dataset, $metric, $fanOutDimensions);
            }
        }

        return $entries;
    }

    /**
     * @param Dataset $dataset
     * @param Metric $metric
     * @param string[] $datasetFanOutDimensions
     */
    private function buildEntry(Dataset $dataset, Metric $metric, array $datasetFanOutDimensions): MetricInventoryEntry
    {
        $components = $this->getComponents($dataset, $metric);
        $fields = $this->getFields($dataset, $components);
        $joins = $this->getJoins($dataset, $fields);
        $fanOutJoins = $this->filterMany($dataset, $joins);
        $additive = $this->isAdditive($components);

        return new MetricInventoryEntry(
            datasetCode: $dataset->code,
            metricCode: $metric->code,
            label: $metric->label,
            kind: $this->getKind($metric),
            hidden: $metric->hidden,
            aggregateFunction: $metric->aggregate,
            expression: $this->getExpression($dataset, $metric),
            baseTable: $dataset->baseTable,
            baseProvider: $dataset->baseProvider,
            joins: $joins,
            fanOutJoins: $fanOutJoins,
            fanOutDimensions: $additive ? $datasetFanOutDimensions : [],
            dependencies: $this->getDependencies($dataset, $fields),
            additive: $additive,
        );
    }

    /**
     * The aggregate metrics the given metric is computed from (itself for aggregates).
     *
     * @param Dataset $dataset
     * @param Metric $metric
     * @return Metric[]
     */
    private function getComponents(Dataset $dataset, Metric $metric): array
    {
        if ($metric->isAggregate()) {
            return [$metric];
        }
        if (!$metric->isDerived()) {
            return [];
        }

        $codes = $this->derivedMetricCalculator->getRequiredAggregates($dataset, [$metric->code]);

        return array_values(array_map($dataset->getMetric(...), $codes));
    }

    /**
     * Fields (metric and condition) used by the given aggregate metrics.
     *
     * @param Dataset $dataset
     * @param Metric[] $components
     * @return Field[]
     */
    private function getFields(Dataset $dataset, array $components): array
    {
        $fields = [];
        foreach ($components as $component) {
            foreach ([$component->fieldCode, $component->conditionFieldCode] as $fieldCode) {
                if ($fieldCode !== null && $dataset->hasField($fieldCode)) {
                    $fields[$fieldCode] = $dataset->getField($fieldCode);
                }
            }
        }

        return array_values($fields);
    }

    /**
     * Join codes of the given fields, transitive through `requires`, required joins first.
     *
     * @param Dataset $dataset
     * @param Field[] $fields
     * @return string[]
     */
    private function getJoins(Dataset $dataset, array $fields): array
    {
        $joins = [];
        $seen = [];
        foreach ($fields as $field) {
            if (!isset($field->sourceMappings[self::SOURCE])) {
                continue;
            }
            foreach ($field->sourceMappings[self::SOURCE]->joins as $joinCode) {
                $this->addJoin($dataset, $joinCode, $joins, $seen);
            }
        }

        return $joins;
    }

    /**
     * @param Dataset $dataset
     * @param string $joinCode
     * @param string[] $joins
     * @param array<string,bool> $seen
     */
    private function addJoin(Dataset $dataset, string $joinCode, array &$joins, array &$seen): void
    {
        if (isset($seen[$joinCode]) || !isset($dataset->joins[$joinCode])) {
            return;
        }
        $seen[$joinCode] = true;
        foreach ($dataset->joins[$joinCode]->requires as $required) {
            $this->addJoin($dataset, $required, $joins, $seen);
        }
        $joins[] = $joinCode;
    }

    /**
     * @param Dataset $dataset
     * @param string[] $joins
     * @return string[]
     */
    private function filterMany(Dataset $dataset, array $joins): array
    {
        return array_values(array_filter(
            $joins,
            static fn (string $code): bool => $dataset->joins[$code]->cardinality === JoinCardinality::MANY
        ));
    }

    /**
     * Dimension codes whose field needs a many join, either directly or transitively.
     *
     * @param Dataset $dataset
     * @return string[]
     */
    private function getFanOutDimensions(Dataset $dataset): array
    {
        $codes = [];
        foreach ($dataset->dimensions as $dimension) {
            if (!$dataset->hasField($dimension->fieldCode)) {
                continue;
            }
            $joins = $this->getJoins($dataset, [$dataset->getField($dimension->fieldCode)]);
            if ($this->filterMany($dataset, $joins) !== []) {
                $codes[] = $dimension->code;
            }
        }

        return $codes;
    }

    /**
     * Additive when any aggregate component is sum, count or avg.
     *
     * @param Metric[] $components
     */
    private function isAdditive(array $components): bool
    {
        foreach ($components as $component) {
            if (\in_array($component->aggregate, [AggregateFunction::SUM, AggregateFunction::COUNT, AggregateFunction::AVG], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Metric $metric
     */
    private function getKind(Metric $metric): string
    {
        return match (true) {
            $metric->isAggregate() => MetricInventoryEntry::KIND_AGGREGATE,
            $metric->isDerived() => MetricInventoryEntry::KIND_DERIVED,
            default => MetricInventoryEntry::KIND_VIRTUAL,
        };
    }

    /**
     * @param Dataset $dataset
     * @param Metric $metric
     */
    private function getExpression(Dataset $dataset, Metric $metric): ?string
    {
        if ($metric->isDerived()) {
            return $metric->formula;
        }
        if (!$metric->isAggregate() || $metric->fieldCode === null || !$dataset->hasField($metric->fieldCode)) {
            return null;
        }

        $mapping = $dataset->getField($metric->fieldCode)->sourceMappings[self::SOURCE] ?? null;
        if ($mapping?->expressionProvider !== null) {
            return 'provider:' . $mapping->expressionProvider;
        }

        return $mapping?->expression;
    }

    /**
     * @param Dataset $dataset
     * @param Field[] $fields
     * @return string[]
     */
    private function getDependencies(Dataset $dataset, array $fields): array
    {
        $dependencies = [];
        if (!$dataset->dateless) {
            $dependencies[] = MetricInventoryEntry::DEPENDENCY_TIMEZONE;
        }
        if ($this->any($fields, static fn (Field $field): bool => $field->type === FieldType::MONEY)) {
            $dependencies[] = MetricInventoryEntry::DEPENDENCY_CURRENCY;
        }
        if (isset($dataset->implicitFilters[ImplicitFilterPool::COUNTED_STATUS])
            || $this->any(
                $fields,
                static fn (Field $field): bool => ($field->sourceMappings[self::SOURCE]->expressionProvider ?? null)
                    === self::FIRST_ORDER_PROVIDER
            )
        ) {
            $dependencies[] = MetricInventoryEntry::DEPENDENCY_COUNTED_STATUSES;
        }
        if ($dataset->baseProvider !== null) {
            $dependencies[] = MetricInventoryEntry::DEPENDENCY_STOCK_PROVIDER;
        }

        return $dependencies;
    }

    /**
     * @param Field[] $fields
     * @param callable(Field): bool $predicate
     */
    private function any(array $fields, callable $predicate): bool
    {
        return array_filter($fields, $predicate) !== [];
    }
}
