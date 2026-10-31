<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Stock;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Stock\SalableStockSelectProvider;
use MageOS\AdvancedReport\Test\Unit\Helper\SelectTestTrait;
use PHPUnit\Framework\TestCase;

final class SalableStockSelectProviderTest extends TestCase
{
    use SelectTestTrait;

    public function testItUnionsTheDefaultStockViewAndEveryExistingStockIndexTable(): void
    {
        $sql = $this->sql([1, 2], ['inventory_stock_2']);

        $this->assertStringContainsString('UNION ALL', $sql);
        $this->assertStringContainsString('1 AS `stock_id`', $sql);
        $this->assertStringContainsString('FROM `inventory_stock_1`', $sql);
        $this->assertStringContainsString('website_id = 0', $sql);
        $this->assertStringContainsString('2 AS `stock_id`', $sql);
        $this->assertStringContainsString('FROM `inventory_stock_2`', $sql);
        $this->assertStringContainsString('e.sku = s.sku', $sql);
    }

    public function testItSkipsStocksWhoseIndexTableDoesNotExist(): void
    {
        $sql = $this->sql([1, 2, 3], ['inventory_stock_3']);

        $this->assertStringNotContainsString('inventory_stock_2`', $sql);
        $this->assertStringContainsString('FROM `inventory_stock_3`', $sql);
    }

    public function testItJoinsPendingReservationsSummedPerStockAndSku(): void
    {
        $sql = $this->sql([1]);

        $this->assertStringContainsString('SUM(quantity) AS `reservation_qty`', $sql);
        $this->assertStringContainsString('FROM `inventory_reservation` GROUP BY `stock_id`, `sku`', $sql);
        $this->assertStringContainsString('LEFT JOIN', $sql);
        $this->assertStringContainsString('r.stock_id = s.stock_id AND r.sku = s.sku', $sql);
    }

    public function testItExposesReservedQuantityAsAPositiveNumber(): void
    {
        $this->assertStringContainsString('-COALESCE(r.reservation_qty, 0) AS `reserved_qty`', $this->sql([1]));
    }

    public function testItComputesSalableQuantityAsStockQuantityPlusReservationsMinusEffectiveMinQtyWithoutClamping(): void
    {
        $sql = $this->sql([1]);

        $this->assertStringContainsString(
            's.stock_qty + COALESCE(r.reservation_qty, 0) - (CASE WHEN si.item_id IS NULL',
            $sql
        );
        $this->assertStringContainsString('ELSE si.min_qty END) ELSE 0 END AS `salable_qty`', $sql);
        $this->assertStringNotContainsString('GREATEST', $sql);
        $this->assertStringContainsString('si.product_id = e.entity_id AND si.website_id = 0', $sql);
    }

    public function testItUsesTheConfiguredMinQtyWhenTheItemUsesConfigMinQty(): void
    {
        $sql = $this->sql([1], [], '2.5');

        $this->assertStringContainsString("si.use_config_min_qty = 1 THEN '2.5' ELSE", $sql);
    }

    public function testItReportsZeroSalableQuantityForNonSalableIndexRows(): void
    {
        $sql = $this->sql([1]);

        $this->assertStringContainsString('CASE WHEN s.is_salable = 1 THEN', $sql);
        $this->assertStringContainsString('ELSE 0 END AS `salable_qty`', $sql);
    }

    public function testItSelectsFromPrefixedStockIndexReservationAndInventoryStockTablesInTheSalableStockSelect(): void
    {
        $sql = $this->sql([1, 2], ['pfx_inventory_stock_2'], '0', 'pfx_');

        $this->assertStringContainsString('FROM `pfx_inventory_stock_1`', $sql);
        $this->assertStringContainsString('FROM `pfx_inventory_stock_2`', $sql);
        $this->assertStringContainsString('FROM `pfx_inventory_reservation`', $sql);
        $this->assertStringContainsString('INNER JOIN `pfx_catalog_product_entity` AS `e`', $sql);
        $this->assertStringContainsString('LEFT JOIN `pfx_cataloginventory_stock_item` AS `si`', $sql);
        $this->assertStringContainsString('FROM `pfx_inventory_stock`', $this->sql([], [], '0', 'pfx_'));
    }

    public function testItExcludesCompositeProductTypes(): void
    {
        $this->assertStringContainsString(
            "e.type_id NOT IN ('configurable', 'bundle', 'grouped')",
            $this->sql([1])
        );
    }

    public function testItReturnsAnEmptyButValidSelectWhenNoStockExists(): void
    {
        $sql = $this->sql([]);

        $this->assertStringContainsString('1 = 0', $sql);
        foreach (['stock_id', 'sku', 'product_id', 'stock_qty', 'reserved_qty', 'salable_qty'] as $column) {
            $this->assertStringContainsString('AS `' . $column . '`', $sql);
        }
        $this->assertStringNotContainsString('UNION', $sql);
    }

    public function testItAppliesTheExecutionTimeHintToTheStockIdLookup(): void
    {
        $adapter = $this->createSqlAdapter();
        $lookups = [];
        $adapter->method('fetchCol')->willReturnCallback(
            static function (mixed $sql) use (&$lookups): array {
                $lookups[] = $sql;

                return [];
            }
        );
        $provider = new SalableStockSelectProvider(
            $this->createReportConnection(),
            $this->createStub(ScopeConfigInterface::class),
            $this->hintingGuardrails(),
        );

        $provider->getSelect($adapter, $this->dataset());

        $this->assertCount(1, $lookups);
        $this->assertStringStartsWith('HINTED ', $lookups[0]);
    }

    private function hintingGuardrails(): Guardrails
    {
        $guardrails = $this->createStub(Guardrails::class);
        $guardrails->method('applyTimeout')->willReturnCallback(static fn (string $sql): string => 'HINTED ' . $sql);

        return $guardrails;
    }

    /**
     * @param int[] $stockIds
     * @param string[] $existingTables
     */
    private function sql(
        array $stockIds,
        array $existingTables = [],
        string $minQty = '0',
        string $tablePrefix = ''
    ): string {
        $adapter = $this->createSqlAdapter();
        $adapter->method('fetchCol')->willReturn($stockIds);
        $adapter->method('isTableExists')->willReturnCallback(
            static fn (string $table): bool => \in_array($table, $existingTables, true)
        );
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($minQty);
        $provider = new SalableStockSelectProvider(
            $this->createReportConnection($tablePrefix),
            $config,
            $this->hintingGuardrails(),
            ['configurable', 'bundle', 'grouped']
        );

        return $this->normalizeSql($provider->getSelect($adapter, $this->dataset()));
    }

    private function dataset(): Dataset
    {
        return new Dataset('salable_stock', 'Stock', 'acl', 'catalog', true, null, null, 'provider', [], [], [], [], []);
    }
}
