<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Stock;

use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Stock\LegacyStockProvider;
use MageOS\AdvancedReport\Model\Stock\MsiStockProvider;
use MageOS\AdvancedReport\Test\Unit\Helper\SelectTestTrait;
use PHPUnit\Framework\TestCase;

final class StockProvidersTest extends TestCase
{
    use SelectTestTrait;

    public function testItExposesProductIdSkuQtySourceCodeCostAndPriceColumnsFromTheLegacyTables(): void
    {
        $sql = $this->normalizeSql(
            (new LegacyStockProvider())->getSelect($this->createSqlAdapter(), $this->dataset())
        );

        $this->assertStringContainsString('`si`.`product_id`', $sql);
        $this->assertStringContainsString('`si`.`qty`', $sql);
        $this->assertStringContainsString("'default' AS `source_code`", $sql);
        $this->assertStringContainsString('`e`.`sku`', $sql);
        $this->assertStringContainsString('`cost`.`value` AS `cost`', $sql);
        $this->assertStringContainsString('`price`.`value` AS `price`', $sql);
        $this->assertStringContainsString('`status`.`value` AS `is_enabled`', $sql);
        $this->assertStringContainsString('FROM `cataloginventory_stock_item` AS `si`', $sql);
        $this->assertStringContainsString('(si.stock_id = 1)', $sql);
        $this->assertStringContainsString("'simple', 'virtual', 'downloadable'", $sql);
    }

    public function testItExposesTheSameColumnsFromInventorySourceItemJoinedBySku(): void
    {
        $sql = $this->normalizeSql(
            (new MsiStockProvider())->getSelect($this->createSqlAdapter(), $this->dataset())
        );

        $this->assertStringContainsString('FROM `inventory_source_item` AS `si`', $sql);
        $this->assertStringContainsString('`si`.`quantity` AS `qty`', $sql);
        $this->assertStringContainsString('`si`.`source_code`', $sql);
        $this->assertStringContainsString('`e`.`entity_id` AS `product_id`', $sql);
        $this->assertStringContainsString('`e`.`sku`', $sql);
        $this->assertStringContainsString('ON e.sku = si.sku', $sql);
    }

    public function testItExcludesSourceItemsOfDisabledSources(): void
    {
        $sql = $this->normalizeSql(
            (new MsiStockProvider())->getSelect($this->createSqlAdapter(), $this->dataset())
        );

        $this->assertStringContainsString(
            'INNER JOIN `inventory_source` AS `src` ON src.source_code = si.source_code AND src.enabled = 1',
            $sql
        );
    }

    public function testItReadsCostAndPriceAtStoreZeroJoinedOnEntityId(): void
    {
        $sql = $this->normalizeSql(
            (new MsiStockProvider())->getSelect($this->createSqlAdapter(), $this->dataset())
        );

        $this->assertStringContainsString(
            'LEFT JOIN `catalog_product_entity_decimal` AS `cost` ON cost.entity_id = e.entity_id'
            . ' AND cost.store_id = 0',
            $sql
        );
        $this->assertStringContainsString(
            'LEFT JOIN `catalog_product_entity_decimal` AS `price` ON price.entity_id = e.entity_id'
            . ' AND price.store_id = 0',
            $sql
        );
        $this->assertStringContainsString("a.attribute_code = 'cost'", $sql);
        $this->assertStringContainsString("a.attribute_code = 'price'", $sql);
    }

    private function dataset(): Dataset
    {
        return new Dataset('catalog_stock', 'Stock', 'acl', 'catalog', true, null, null, 'provider', [], [], [], [], []);
    }
}
