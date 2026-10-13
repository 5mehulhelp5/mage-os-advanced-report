<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use InvalidArgumentException;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use MageOS\AdvancedReport\Model\Definition\Formula\Formula;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;

/**
 * Applies ORDER BY, LIMIT and OFFSET to a grouped select.
 *
 * Dimension and aggregate metrics are sorted by their select alias; derived metrics by their
 * formula rendered over the aggregate SQL expressions. Dimension aliases are always appended
 * as tie-breakers so that offset pagination is deterministic.
 */
class SortApplier
{
    public function __construct(private readonly AggregateExpressionBuilder $aggregateExpressionBuilder)
    {
    }

    /**
     * @param Select $select
     * @param ResolvedQuery $query
     * @param AdapterInterface $connection
     * @throws InvalidArgumentException When a sort target is not a selected dimension or metric.
     */
    public function apply(Select $select, ResolvedQuery $query, AdapterInterface $connection): void
    {
        $dimensionCodes = array_map(static fn (DimensionSelection $selection): string => $selection->code, $query->dimensions);
        $sorted = [];
        foreach ($query->sort as $sort) {
            $direction = strtolower($sort->direction) === 'desc' ? 'DESC' : 'ASC';
            $select->order($this->term($query, $sort->by, $dimensionCodes, $direction, $connection));
            $sorted[] = $sort->by;
        }
        foreach (array_diff($dimensionCodes, $sorted) as $code) {
            $select->order($code . ' ASC');
        }
        if ($query->limit !== null) {
            $select->limit($query->limit, $query->offset);
        }
    }

    /**
     * @param ResolvedQuery $query
     * @param string $target
     * @param string[] $dimensionCodes
     * @param string $direction
     * @param AdapterInterface $connection
     */
    private function term(
        ResolvedQuery $query,
        string $target,
        array $dimensionCodes,
        string $direction,
        AdapterInterface $connection
    ): string|Expression {
        if (\in_array($target, $dimensionCodes, true) || \in_array($target, $query->metrics, true)) {
            return $target . ' ' . $direction;
        }
        $metric = $query->dataset->metrics[$target] ?? null;
        if ($metric === null || !$metric->isDerived()) {
            throw new InvalidArgumentException(\sprintf('Cannot sort by "%s".', $target));
        }
        $expression = Formula::fromString((string) $metric->formula)->render(
            function (string $code) use ($query, $connection): string {
                if (!\in_array($code, $query->metrics, true)) {
                    throw new InvalidArgumentException(\sprintf('Cannot sort by unselected metric "%s".', $code));
                }

                return $this->aggregateExpressionBuilder->build(
                    $query->dataset,
                    $query->dataset->getMetric($code),
                    $connection
                );
            }
        );

        return new Expression($expression . ' ' . $direction);
    }
}
