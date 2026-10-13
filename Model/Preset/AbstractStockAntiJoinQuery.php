<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Preset;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\PresetQueryInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\CountedStatusCondition;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\Label\DimensionLabelApplier;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\RawResult;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use MageOS\AdvancedReport\Model\Stock\StockSelectProvider;

/**
 * Catalog presets built on an anti-join over order items: one row per SKU taken from the stock
 * base select (aliased "s"), narrowed by a subclass specific predicate.
 *
 * Output columns: `sku` (dimension), `qty_on_hand`, `stock_value_cost` and the subclass extras.
 * Guardrails (row cap, execution timeout, timeout mapping) are the same as the MySQL data source.
 */
abstract class AbstractStockAntiJoinQuery implements PresetQueryInterface
{
    private const string PRODUCT_DIMENSION = 'product_id';
    private const string DEFAULT_SORT = 'stock_value_cost';

    public function __construct(
        protected readonly ResourceConnection $resourceConnection,
        protected readonly Config $config,
        protected readonly StockSelectProvider $stockSelectProvider,
        protected readonly Guardrails $guardrails,
        protected readonly CountedStatusCondition $countedStatusCondition,
        protected readonly DimensionLabelApplier $dimensionLabelApplier,
    ) {
    }

    public function execute(ReportRequestInterface $request, ResolvedQuery $resolvedQuery): RawResult
    {
        $connection = $this->getConnection();
        $page = $this->getSelect($resolvedQuery);
        $totals = $this->totalsSelect($connection, $this->baseSelect($connection, $resolvedQuery));

        try {
            $fetched = $connection->fetchAll($this->guardrails->applyTimeout((string) $page), $page->getBind());
            $totalsRow = $connection->fetchRow($this->guardrails->applyTimeout((string) $totals), $totals->getBind())
                ?: [];
        } catch (\Throwable $error) {
            throw $this->guardrails->mapException($error);
        }

        $count = (int) ($totalsRow['row_count'] ?? 0);
        $warnings = [];
        $truncation = $this->guardrails->getTruncationWarning($count);
        if ($truncation !== null) {
            $warnings[] = (string) $truncation;
        }

        return new RawResult(
            $this->dimensionLabelApplier->apply(
                $this->productOnlyQuery($resolvedQuery),
                array_map($this->toRow(...), $fetched)
            ),
            ['metrics' => [
                'qty_on_hand' => (float) ($totalsRow['qty_on_hand'] ?? 0),
                'stock_value_cost' => (float) ($totalsRow['stock_value_cost'] ?? 0),
            ]],
            $count,
            $warnings,
        );
    }

    /**
     * The sorted, paginated and capped select; exposed for the explain CLI.
     */
    public function getSelect(ResolvedQuery $resolvedQuery): Select
    {
        $connection = $this->getConnection();
        $select = $this->baseSelect($connection, $resolvedQuery);
        $this->applySort($select, $resolvedQuery, $connection);
        $this->guardrails->limitRows($select, (int) $resolvedQuery->limit);
        if ($resolvedQuery->offset > 0) {
            $select->limit((int) $select->getPart(Select::LIMIT_COUNT), $resolvedQuery->offset);
        }

        return $select;
    }

    /**
     * Adds the preset specific conditions on the stock select aliased "s".
     *
     * @param AdapterInterface $connection
     * @param Select $select
     */
    abstract protected function applyPredicate(AdapterInterface $connection, Select $select): void;

    /**
     * Additional output columns (alias => expression) beyond sku and the stock metrics.
     *
     * @param AdapterInterface $connection
     * @return array<string,string>
     */
    protected function getExtraColumns(AdapterInterface $connection): array
    {
        return [];
    }

    /**
     * Correlated sub select over the order items of the row's product.
     *
     * @param AdapterInterface $connection
     * @param string $columns
     * @param bool $withOrders Whether the items are joined to their orders (filterable by date/status).
     */
    protected function orderItemsOf(AdapterInterface $connection, string $columns, bool $withOrders): string
    {
        $items = $connection->getTableName('sales_order_item');
        $orders = $connection->getTableName('sales_order');
        $from = $withOrders ? \sprintf('%s AS i JOIN %s AS o ON o.entity_id = i.order_id', $items, $orders) : $items . ' AS i';

        return \sprintf('SELECT %s FROM %s WHERE i.product_id = s.product_id', $columns, $from);
    }

    private function getConnection(): AdapterInterface
    {
        return $this->resourceConnection->getConnectionByName($this->config->getConnectionName());
    }

    private function baseSelect(AdapterInterface $connection, ResolvedQuery $resolvedQuery): Select
    {
        $select = $connection->select();
        $select->from(
            ['s' => new \Zend_Db_Expr('(' . $this->stockSelectProvider->getSelect($connection, $resolvedQuery->dataset) . ')')],
            [
                'sku' => 's.sku',
                'qty_on_hand' => 'SUM(s.qty)',
                'stock_value_cost' => 'SUM(s.qty * COALESCE(s.cost, 0))',
            ]
        );
        if ($this->hasProductDimension($resolvedQuery)) {
            $select->columns([self::PRODUCT_DIMENSION => 's.product_id']);
        }
        $select->columns(array_map(
            static fn (string $expression): \Zend_Db_Expr => new \Zend_Db_Expr($expression),
            $this->getExtraColumns($connection)
        ));
        $select->group(['s.product_id', 's.sku']);
        $this->applyPredicate($connection, $select);

        return $select;
    }

    private function totalsSelect(AdapterInterface $connection, Select $inner): Select
    {
        $select = $connection->select();
        $select->from(
            ['t' => new \Zend_Db_Expr('(' . $inner . ')')],
            ['row_count' => 'COUNT(*)', 'qty_on_hand' => 'SUM(t.qty_on_hand)', 'stock_value_cost' => 'SUM(t.stock_value_cost)']
        );
        $select->bind($inner->getBind());

        return $select;
    }

    private function hasProductDimension(ResolvedQuery $resolvedQuery): bool
    {
        return array_any(
            $resolvedQuery->dimensions,
            static fn (DimensionSelection $selection): bool => $selection->code === self::PRODUCT_DIMENSION
        );
    }

    /**
     * The query restricted to the only dimension this preset emits besides sku, so labels are not
     * resolved for dimensions the rows do not carry.
     */
    private function productOnlyQuery(ResolvedQuery $resolvedQuery): ResolvedQuery
    {
        return new ResolvedQuery(
            $resolvedQuery->dataset,
            $resolvedQuery->metrics,
            array_values(array_filter(
                $resolvedQuery->dimensions,
                static fn (DimensionSelection $selection): bool => $selection->code === self::PRODUCT_DIMENSION
            )),
            $resolvedQuery->filters,
            $resolvedQuery->dateRange,
            $resolvedQuery->storeIds,
            $resolvedQuery->sort,
            $resolvedQuery->limit,
            $resolvedQuery->offset
        );
    }

    private function applySort(Select $select, ResolvedQuery $resolvedQuery, AdapterInterface $connection): void
    {
        $allowed = ['sku', 'qty_on_hand', 'stock_value_cost', ...array_keys($this->getExtraColumns($connection))];
        if ($this->hasProductDimension($resolvedQuery)) {
            $allowed[] = self::PRODUCT_DIMENSION;
        }
        $orders = [];
        foreach ($resolvedQuery->sort as $order) {
            if (\in_array($order->by, $allowed, true)) {
                $orders[] = $order->by . (strtolower($order->direction) === 'desc' ? ' DESC' : ' ASC');
            }
        }
        $select->order($orders === [] ? [self::DEFAULT_SORT . ' DESC', 'sku ASC'] : [...$orders, 'sku ASC']);
    }

    /**
     * @param array<string,mixed> $fetched
     * @return array{dims: array<string,string>, dims_raw: array<string,?string>, metrics: array<string,int|float|null>}
     */
    private function toRow(array $fetched): array
    {
        $dims = ['sku' => (string) $fetched['sku']];
        unset($fetched['sku']);
        if (isset($fetched[self::PRODUCT_DIMENSION])) {
            $dims[self::PRODUCT_DIMENSION] = (string) $fetched[self::PRODUCT_DIMENSION];
        }
        unset($fetched[self::PRODUCT_DIMENSION]);

        return [
            'dims' => $dims,
            'dims_raw' => $dims,
            'metrics' => array_map(
                static fn (mixed $value): ?float => $value === null ? null : (float) $value,
                $fetched
            ),
        ];
    }
}
