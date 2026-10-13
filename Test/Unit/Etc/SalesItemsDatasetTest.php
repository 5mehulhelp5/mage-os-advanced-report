<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use MageOS\AdvancedReport\Model\Definition\Dataset;
use PHPUnit\Framework\TestCase;

final class SalesItemsDatasetTest extends TestCase
{
    use ReportDatasetsFixtureTrait;

    public function testItDefinesSalesItemsMetricsIncludingCostGrossMarginMarginPctReturnRateAndCostCoverage(): void
    {
        $dataset = $this->loadSalesItemsDataset();

        foreach (['cost', 'gross_margin', 'margin_pct', 'return_rate', 'cost_coverage'] as $code) {
            self::assertArrayHasKey($code, $dataset->metrics, \sprintf('Missing metric "%s".', $code));
        }

        $cost = $dataset->getMetric('cost');
        self::assertTrue($cost->isAggregate());
        self::assertSame('has_cost', $cost->conditionFieldCode);

        $grossMargin = $dataset->getMetric('gross_margin');
        self::assertTrue($grossMargin->isDerived());
        self::assertSame('net_sales - cost', $grossMargin->formula);

        $marginPct = $dataset->getMetric('margin_pct');
        self::assertTrue($marginPct->isDerived());
        self::assertSame('gross_margin / net_sales', $marginPct->formula);

        $returnRate = $dataset->getMetric('return_rate');
        self::assertTrue($returnRate->isDerived());
        self::assertSame('returns / gross_sales', $returnRate->formula);
        self::assertTrue($returnRate->lowerIsBetter);

        $costCoverage = $dataset->getMetric('cost_coverage');
        self::assertTrue($costCoverage->isDerived());
        self::assertSame('has_cost_rows / rows', $costCoverage->formula);

        self::assertTrue($dataset->getMetric('has_cost_rows')->hidden);
        self::assertTrue($dataset->getMetric('rows')->hidden);
    }

    public function testItResolvesUnitCostFromChildRowFirstThenParentRow(): void
    {
        $dataset = $this->loadSalesItemsDataset();

        $unitCost = $dataset->getField('unit_cost');
        $mapping = $unitCost->sourceMappings['mysql'];

        self::assertSame('COALESCE(child.base_cost, main.base_cost)', $mapping->expression);
        self::assertSame(['child'], $mapping->joins);
    }

    public function testItRestrictsTheChildJoinToConfigurableParentsThroughTheOrderIdIndex(): void
    {
        $dataset = $this->loadSalesItemsDataset();

        $childJoin = $dataset->getJoin('child');

        self::assertSame(
            "child.order_id = main.order_id AND child.parent_item_id = main.item_id"
                . " AND main.product_type = 'configurable'",
            $childJoin->condition
        );
    }

    public function testItMapsSalesItemsCreatedAtStoreIdAndStatusToTheOrderRow(): void
    {
        $dataset = $this->loadSalesItemsDataset();

        $createdAt = $dataset->getField('created_at')->sourceMappings['mysql'];
        self::assertSame('order.created_at', $createdAt->expression);
        self::assertSame(['order'], $createdAt->joins);

        $storeId = $dataset->getField('store_id')->sourceMappings['mysql'];
        self::assertSame('order.store_id', $storeId->expression);
        self::assertSame(['order'], $storeId->joins);

        $status = $dataset->getField('status')->sourceMappings['mysql'];
        self::assertSame('order.status', $status->expression);
        self::assertSame(['order'], $status->joins);
    }

    public function testItRestrictsSalesItemsToTopLevelItemsAndCountedStatuses(): void
    {
        $dataset = $this->loadSalesItemsDataset();

        self::assertArrayHasKey('top_level_item', $dataset->implicitFilters);
        self::assertArrayHasKey('counted_status', $dataset->implicitFilters);
    }

    public function testItMarksTheCategoryDimensionAsNonAdditive(): void
    {
        $dataset = $this->loadSalesItemsDataset();

        self::assertTrue($dataset->getDimension('category_id')->nonAdditive);
    }

    public function testItMapsShipmentCountToTheShipmentCountExpressionProvider(): void
    {
        $field = $this->loadSalesItemsDataset()->getField('shipment_count');

        self::assertSame('integer', $field->type->value);
        self::assertSame('shipment_count', $field->sourceMappings['mysql']->expressionProvider);
    }

    public function testItMapsCreditMemoCountToTheCreditMemoCountExpressionProvider(): void
    {
        $field = $this->loadSalesItemsDataset()->getField('credit_memo_count');

        self::assertSame('integer', $field->type->value);
        self::assertSame('credit_memo_count', $field->sourceMappings['mysql']->expressionProvider);
    }

    public function testItDefinesShipmentsAsAnIntegerSumOfShipmentCount(): void
    {
        $metric = $this->loadSalesItemsDataset()->getMetric('shipments');

        self::assertTrue($metric->isAggregate());
        self::assertSame('sum', $metric->aggregate?->value);
        self::assertSame('integer', $metric->format);
        self::assertSame('shipment_count', $metric->fieldCode);
        self::assertFalse($metric->lowerIsBetter);
    }

    public function testItDefinesCreditMemosAsAnIntegerSumOfCreditMemoCountMarkedLowerIsBetter(): void
    {
        $metric = $this->loadSalesItemsDataset()->getMetric('credit_memos');

        self::assertTrue($metric->isAggregate());
        self::assertSame('sum', $metric->aggregate?->value);
        self::assertSame('integer', $metric->format);
        self::assertSame('credit_memo_count', $metric->fieldCode);
        self::assertTrue($metric->lowerIsBetter);
    }

    public function testItMapsQtyShippedQtyToTheOrderItemShippedQuantity(): void
    {
        $field = $this->loadSalesItemsDataset()->getField('qty_shipped_qty');

        self::assertSame('decimal', $field->type->value);
        self::assertSame('COALESCE(main.qty_shipped, 0)', $field->sourceMappings['mysql']->expression);
    }

    public function testItMapsQtyToShipQtyToTheClampedRemainingQuantityExcludingVirtualItems(): void
    {
        $field = $this->loadSalesItemsDataset()->getField('qty_to_ship_qty');
        $expression = (string) $field->sourceMappings['mysql']->expression;

        self::assertSame('decimal', $field->type->value);
        self::assertStringContainsString('WHEN main.is_virtual = 1 THEN 0', $expression);
        self::assertStringContainsString(
            'GREATEST(COALESCE(main.qty_ordered, 0) - COALESCE(main.qty_shipped, 0)'
            . ' - COALESCE(main.qty_refunded, 0) - COALESCE(main.qty_canceled, 0), 0)',
            $expression
        );
    }

    public function testItExcludesBundleParentsShippedSeparatelyFromQtyToShipQty(): void
    {
        $expression = (string) $this->loadSalesItemsDataset()->getField('qty_to_ship_qty')->sourceMappings['mysql']->expression;

        self::assertStringContainsString("main.product_type = 'bundle'", $expression);
        self::assertStringContainsString('JSON_VALID(main.product_options)', $expression);
        self::assertStringContainsString("'$.shipment_type'", $expression);
    }

    public function testItDocumentsTheOrderDateAttributionLimitationOnUnitsToShip(): void
    {
        $metric = $this->loadSalesItemsDataset()->getMetric('units_to_ship');

        self::assertStringContainsString('attributed to the original order date', (string) $metric->description);
    }

    public function testItDefinesUnitsShippedAsANumberSumOfQtyShippedQty(): void
    {
        $metric = $this->loadSalesItemsDataset()->getMetric('units_shipped');

        self::assertTrue($metric->isAggregate());
        self::assertSame('sum', $metric->aggregate?->value);
        self::assertSame('number', $metric->format);
        self::assertSame('qty_shipped_qty', $metric->fieldCode);
        self::assertFalse($metric->lowerIsBetter);
    }

    public function testItDefinesUnitsToShipAsANumberSumOfQtyToShipQtyMarkedLowerIsBetter(): void
    {
        $metric = $this->loadSalesItemsDataset()->getMetric('units_to_ship');

        self::assertTrue($metric->isAggregate());
        self::assertSame('sum', $metric->aggregate?->value);
        self::assertSame('number', $metric->format);
        self::assertSame('qty_to_ship_qty', $metric->fieldCode);
        self::assertTrue($metric->lowerIsBetter);
    }

    public function testItDocumentsTheOrderDateAttributionLimitationOnUnitsShipped(): void
    {
        $metric = $this->loadSalesItemsDataset()->getMetric('units_shipped');

        self::assertStringContainsString('attributed to the original order date', (string) $metric->description);
        self::assertStringContainsString('shipped separately are not counted', (string) $metric->description);
    }

    public function testItKeepsTheShipmentsAndCreditMemosMetricsOutOfEveryPreset(): void
    {
        foreach ($this->loadReportPresets() as $code => $preset) {
            self::assertNotContains('shipments', $preset->metrics, $code);
            self::assertNotContains('credit_memos', $preset->metrics, $code);
        }
    }

    public function testItKeepsTheShippedQtyMetricsOutOfEveryPreset(): void
    {
        foreach ($this->loadReportPresets() as $code => $preset) {
            self::assertNotContains('units_shipped', $preset->metrics, $code);
            self::assertNotContains('units_to_ship', $preset->metrics, $code);
        }
    }

    private function loadSalesItemsDataset(): Dataset
    {
        return $this->loadReportDataset('sales_items');
    }
}
