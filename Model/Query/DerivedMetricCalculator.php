<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Formula\Formula;
use MageOS\AdvancedReport\Model\Definition\Metric;

/**
 * Computes derived metrics (formulas over other metrics) on report rows and totals.
 *
 * Stateless: every call is independent so the same instance can be reused across requests
 * and by {@see \MageOS\AdvancedReport\Model\Source\MysqlDataSource} for in-PHP sorting.
 *
 * Rows use the row shape `['dims' => ..., 'dims_raw' => ..., 'metrics' => [code => value]]`;
 * `dims`/`dims_raw` are passed through untouched. Totals are a flat `[code => value]` map.
 * Derived metrics on totals are recomputed from the total aggregates, never summed from rows.
 *
 * Zero-denominator semantics: a ratio whose denominator is zero (or whose operand is null) evaluates to null,
 * never 0 and never an exception. SUM aggregates are 0 on empty periods, so e.g. `aov` is 0 / 0 = null there.
 */
class DerivedMetricCalculator
{
    /**
     * Aggregate metric codes needed to compute the given requested metrics: the requested
     * aggregates themselves plus every aggregate referenced transitively by requested
     * derived metrics.
     *
     * @param Dataset $dataset
     * @param string[] $metricCodes
     * @return string[]
     */
    public function getRequiredAggregates(Dataset $dataset, array $metricCodes): array
    {
        $aggregates = [];
        $visited = [];
        foreach ($metricCodes as $metricCode) {
            $this->collectAggregates($dataset, $metricCode, $aggregates, $visited);
        }

        return array_values($aggregates);
    }

    /**
     * Recursively collects the aggregate metric codes reachable from the given metric code.
     *
     * @param Dataset $dataset
     * @param string $metricCode
     * @param array<string,string> $aggregates
     * @param array<string,bool> $visited
     */
    private function collectAggregates(Dataset $dataset, string $metricCode, array &$aggregates, array &$visited): void
    {
        if (isset($visited[$metricCode])) {
            return;
        }
        $visited[$metricCode] = true;

        $metric = $dataset->metrics[$metricCode] ?? null;
        if ($metric === null) {
            return;
        }

        if ($metric->isAggregate()) {
            $aggregates[$metricCode] = $metricCode;

            return;
        }

        if (!$metric->isDerived()) {
            return;
        }

        foreach ($this->formulaOf($metric)->getReferences() as $reference) {
            $this->collectAggregates($dataset, $reference, $aggregates, $visited);
        }
    }

    /**
     * Computes derived metrics on each row and on the totals, resolving nested derived
     * metrics in dependency order, then strips every metric not in $metricCodes.
     *
     * @param Dataset $dataset
     * @param string[] $metricCodes
     * @param array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}> $rows
     * @param array<string,int|float|null> $totals
     * @return array{
     *     rows: array<int,array{dims: array<string,mixed>, dims_raw: array<string,mixed>, metrics: array<string,int|float|null>}>,
     *     totals: array<string,int|float|null>
     * }
     */
    public function apply(Dataset $dataset, array $metricCodes, array $rows, array $totals): array
    {
        $derivedOrder = $this->resolveDerivedOrder($dataset, $metricCodes);

        $rows = array_map(
            fn (array $row): array => [
                'dims' => $row['dims'],
                'dims_raw' => $row['dims_raw'],
                'metrics' => $this->selectMetrics($dataset, $metricCodes, $derivedOrder, $row['metrics']),
            ],
            $rows
        );

        return [
            'rows' => $rows,
            'totals' => $this->selectMetrics($dataset, $metricCodes, $derivedOrder, $totals),
        ];
    }

    /**
     * Topologically orders every derived metric transitively needed by $metricCodes so that
     * a derived metric's dependencies are always computed before it.
     *
     * @param Dataset $dataset
     * @param string[] $metricCodes
     * @return string[]
     */
    private function resolveDerivedOrder(Dataset $dataset, array $metricCodes): array
    {
        $order = [];
        $visited = [];
        foreach ($metricCodes as $metricCode) {
            $this->visitDerived($dataset, $metricCode, $order, $visited);
        }

        return $order;
    }

    /**
     * Post-order depth-first traversal of the derived-metric dependency graph: a metric is
     * appended to $order only after all of its references have been.
     *
     * @param Dataset $dataset
     * @param string $metricCode
     * @param string[] $order
     * @param array<string,bool> $visited
     */
    private function visitDerived(Dataset $dataset, string $metricCode, array &$order, array &$visited): void
    {
        if (isset($visited[$metricCode])) {
            return;
        }
        $visited[$metricCode] = true;

        $metric = $dataset->metrics[$metricCode] ?? null;
        if ($metric === null || !$metric->isDerived()) {
            return;
        }

        foreach ($this->formulaOf($metric)->getReferences() as $reference) {
            $this->visitDerived($dataset, $reference, $order, $visited);
        }

        $order[] = $metricCode;
    }

    /**
     * Computes every derived metric in $derivedOrder into $values, then returns only the
     * requested $metricCodes (dropping helper aggregates/derived metrics not requested).
     *
     * @param Dataset $dataset
     * @param string[] $metricCodes
     * @param string[] $derivedOrder
     * @param array<string,int|float|null> $values
     * @return array<string,int|float|null>
     */
    private function selectMetrics(Dataset $dataset, array $metricCodes, array $derivedOrder, array $values): array
    {
        foreach ($derivedOrder as $metricCode) {
            $values[$metricCode] = $this->formulaOf($dataset->metrics[$metricCode])->evaluate($values);
        }

        $selected = [];
        foreach ($metricCodes as $metricCode) {
            $selected[$metricCode] = $values[$metricCode] ?? null;
        }

        return $selected;
    }

    /**
     * Parses a derived metric's raw formula text.
     *
     * @param Metric $metric
     */
    private function formulaOf(Metric $metric): Formula
    {
        return Formula::fromString((string) $metric->formula);
    }
}
