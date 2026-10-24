<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Preset;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\LabelResolverInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\CountedStatusCondition;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Label\DimensionLabelApplier;
use MageOS\AdvancedReport\Model\Label\LabelResolverPool;
use MageOS\AdvancedReport\Model\Label\ProductNameLabelResolver;
use MageOS\AdvancedReport\Model\Preset\AbstractStockAntiJoinQuery;
use MageOS\AdvancedReport\Model\Preset\DeadStockQuery;
use MageOS\AdvancedReport\Model\Preset\NeverSoldQuery;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\RawResult;
use MageOS\AdvancedReport\Model\Query\ReportScope;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use MageOS\AdvancedReport\Model\Query\SortOrder;
use MageOS\AdvancedReport\Model\Stock\StockSelectProvider;
use MageOS\AdvancedReport\Test\Unit\Helper\SelectTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractStockAntiJoinQuery::class)]
#[CoversClass(DeadStockQuery::class)]
#[CoversClass(NeverSoldQuery::class)]
final class StockAntiJoinQueryTest extends TestCase
{
    use SelectTestTrait;

    private Mysql&Stub $adapter;
    private AbstractStockAntiJoinQuery $query;

    public function testItSelectsInStockProductsWithoutCountedSalesSinceTheDeadStockThreshold(): void
    {
        $select = $this->build(DeadStockQuery::class)->getSelect($this->resolved());
        $sql = $this->normalizeSql($select);

        self::assertStringContainsString('s.qty > 0', $sql);
        self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM sales_order_item AS i JOIN sales_order AS o', $sql);
        self::assertStringContainsString('i.product_id = s.product_id AND o.created_at >= :utcSince', $sql);
        self::assertStringContainsString("o.status NOT IN ('canceled')", $sql);
        $since = strtotime((string) $select->getBind()['utcSince'] . ' UTC');
        self::assertEqualsWithDelta(time() - 90 * 86400, $since, 5);
    }

    public function testItComputesDaysSinceLastSaleAndNullWhenNeverSold(): void
    {
        $sql = $this->normalizeSql($this->build(DeadStockQuery::class)->getSelect($this->resolved()));

        self::assertStringContainsString(
            '(SELECT DATEDIFF(UTC_TIMESTAMP(), MAX(o.created_at)) FROM sales_order_item AS i JOIN sales_order AS o'
            . ' ON o.entity_id = i.order_id WHERE i.product_id = s.product_id AND o.status NOT IN (\'canceled\'))'
            . ' AS `days_since_last_sale`',
            $sql
        );
    }

    public function testItSelectsEnabledProductsNeverPresentInOrderItems(): void
    {
        $sql = $this->normalizeSql($this->build(NeverSoldQuery::class)->getSelect($this->resolved()));

        self::assertStringContainsString('s.is_enabled = 1', $sql);
        self::assertStringContainsString(
            'NOT EXISTS (SELECT 1 FROM sales_order_item AS i WHERE i.product_id = s.product_id)',
            $sql
        );
        self::assertStringNotContainsString('sales_order AS o', $sql);
    }

    public function testItAppliesGuardrailsAndMaxRows(): void
    {
        $query = $this->build(DeadStockQuery::class);
        $sql = $this->normalizeSql($query->getSelect($this->resolved(1000, 20, [new SortOrder('qty_on_hand', 'desc')])));

        self::assertStringContainsString('ORDER BY `qty_on_hand` DESC, `sku` ASC LIMIT 100 OFFSET 20', $sql);

        $adapter = $this->adapter;
        $adapter->method('fetchAll')->willThrowException(new \RuntimeException('boom'));
        $this->expectException(\RuntimeException::class);
        $query->execute($this->createStub(ReportRequestInterface::class), $this->resolved());
    }

    public function testItExposesTheSelectForExplain(): void
    {
        $select = $this->build(NeverSoldQuery::class)->getSelect($this->resolved());

        self::assertStringContainsString('ORDER BY `stock_value_cost` DESC, `sku` ASC', $this->normalizeSql($select));
    }

    public function testItSelectsProductIdWhenTheProductDimensionIsRequested(): void
    {
        $sql = $this->normalizeSql(
            $this->build(NeverSoldQuery::class)->getSelect($this->resolved(dimensions: [new DimensionSelection('product_id')]))
        );

        self::assertStringContainsString('`s`.`product_id`', $sql);
    }

    public function testItDoesNotSelectProductIdWhenNotRequested(): void
    {
        $sql = $this->normalizeSql($this->build(NeverSoldQuery::class)->getSelect($this->resolved()));

        self::assertStringContainsString('SELECT `s`.`sku`, SUM(s.qty)', $sql);
    }

    public function testItReturnsTheProductIdInDimsRaw(): void
    {
        $result = $this->executeWithRow(['sku' => 'A', 'product_id' => '5', 'qty_on_hand' => '2']);

        self::assertSame(['sku' => 'A', 'product_id' => '5'], $result->rows[0]['dims_raw']);
    }

    public function testItReturnsTheProductNameLabelInDims(): void
    {
        $result = $this->executeWithRow(['sku' => 'A', 'product_id' => '5', 'qty_on_hand' => '2']);

        self::assertSame(['sku' => 'A', 'product_id' => 'Widget'], $result->rows[0]['dims']);
    }

    public function testItAlwaysKeepsTheSkuDimension(): void
    {
        $result = $this->executeWithRow(['sku' => 'A', 'qty_on_hand' => '2'], []);

        self::assertSame(['sku' => 'A'], $result->rows[0]['dims']);
        self::assertSame(['sku' => 'A'], $result->rows[0]['dims_raw']);
    }

    public function testItSortsByProductIdWhenRequestedAndSelected(): void
    {
        $sql = $this->normalizeSql($this->build(NeverSoldQuery::class)->getSelect(
            $this->resolved(sort: [new SortOrder('product_id', 'desc')], dimensions: [new DimensionSelection('product_id')])
        ));

        self::assertStringContainsString('ORDER BY `product_id` DESC, `sku` ASC', $sql);
    }

    public function testItIgnoresProductIdSortWhenNotSelected(): void
    {
        $sql = $this->normalizeSql(
            $this->build(NeverSoldQuery::class)->getSelect($this->resolved(sort: [new SortOrder('product_id', 'desc')]))
        );

        self::assertStringNotContainsString('`product_id` DESC', $sql);
        self::assertStringContainsString('ORDER BY `stock_value_cost` DESC, `sku` ASC', $sql);
    }

    public function testItReportsTheStatementsRunByStockAntiJoinPresetQueries(): void
    {
        $result = $this->executeWithRow(['sku' => 'A', 'qty_on_hand' => '2']);

        self::assertSame(2, $result->meta['sql_query_count']);
        self::assertIsFloat($result->meta['sql_time_ms']);
    }

    public function testItDoesNotExposeProductIdAsAMetric(): void
    {
        $result = $this->executeWithRow(['sku' => 'A', 'product_id' => '5', 'qty_on_hand' => '2']);

        self::assertSame(['qty_on_hand' => 2.0], $result->rows[0]['metrics']);
    }

    public function testItKeepsTheTotalsSelectConsistentWhenProductIdIsSelected(): void
    {
        $totalsSql = null;
        $this->build(NeverSoldQuery::class);
        $this->adapter->method('fetchAll')->willReturn([]);
        $this->adapter->method('fetchRow')->willReturnCallback(
            static function (string $sql) use (&$totalsSql): array {
                $totalsSql = $sql;

                return ['row_count' => '3', 'qty_on_hand' => '7', 'stock_value_cost' => '9'];
            }
        );
        $result = $this->query->execute(
            $this->createStub(ReportRequestInterface::class),
            $this->resolved(dimensions: [new DimensionSelection('product_id')])
        );

        self::assertStringContainsString('COUNT(*) AS `row_count`', (string) $totalsSql);
        self::assertStringContainsString('SUM(t.qty_on_hand)', (string) $totalsSql);
        self::assertSame(3, $result->totalRowCount);
        self::assertSame(7.0, $result->totals['metrics']['qty_on_hand']);
    }

    /**
     * @param array<string,string> $row
     * @param DimensionSelection[] $dimensions
     */
    private function executeWithRow(array $row, ?array $dimensions = null): RawResult
    {
        $this->build(NeverSoldQuery::class);
        $this->adapter->method('fetchAll')->willReturn([$row]);
        $this->adapter->method('fetchRow')->willReturn(['row_count' => '1']);

        return $this->query->execute(
            $this->createStub(ReportRequestInterface::class),
            $this->resolved(dimensions: $dimensions ?? [new DimensionSelection('product_id')])
        );
    }

    /**
     * @param class-string<AbstractStockAntiJoinQuery> $class
     */
    private function build(string $class): AbstractStockAntiJoinQuery
    {
        $this->adapter = $this->createSqlAdapter();
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($this->adapter);
        $config = $this->createStub(Config::class);
        $config->method('getDeadStockDays')->willReturn(90);
        $config->method('getCountedStatuses')->willReturn([]);
        $config->method('getConnectionName')->willReturn('default');
        $config->method('getMaxRows')->willReturn(100);
        $stock = $this->createStub(StockSelectProvider::class);
        $stock->method('getSelect')->willReturnCallback(
            fn (): Select => $this->adapter->select()->from(['si' => 'stock'], ['product_id'])
        );
        $guardrails = $this->createStub(Guardrails::class);
        $guardrails->method('getMaxRows')->willReturn(100);
        $guardrails->method('limitRows')->willReturnCallback(
            static fn (Select $select, int $requested): Select => $select->limit(
                $requested > 0 ? min($requested, 100) : 100
            )
        );
        $guardrails->method('applyTimeout')->willReturnArgument(0);
        $guardrails->method('mapException')->willReturnArgument(0);

        $resolver = $this->createStub(LabelResolverInterface::class);
        $resolver->method('resolve')->willReturn(['5' => 'Widget']);
        $applier = new DimensionLabelApplier(new LabelResolverPool([ProductNameLabelResolver::class => $resolver]));

        return $this->query = new $class(
            new ReportConnection($resource, $config),
            $config,
            $stock,
            $guardrails,
            new CountedStatusCondition($config),
            $applier
        );
    }

    /**
     * @param SortOrder[] $sort
     * @param DimensionSelection[] $dimensions
     */
    private function resolved(?int $limit = null, int $offset = 0, array $sort = [], array $dimensions = []): ResolvedQuery
    {
        return new ResolvedQuery(
            new Dataset('catalog_stock', 'Stock', 'acl', 'catalog', true, null, null, null, [], [], [], [], [
                'sku' => new Dimension('sku', 'SKU', 'sku'),
                'product_id' => new Dimension('product_id', 'Product', 'product_id', [], ProductNameLabelResolver::class),
            ]),
            [],
            $dimensions,
            [],
            null,
            [],
            $sort,
            $limit,
            $offset,
            new ReportScope(null, 'UTC', 0, ['UTC'])
        );
    }
}
