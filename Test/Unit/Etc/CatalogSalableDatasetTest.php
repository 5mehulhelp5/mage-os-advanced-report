<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Label\ProductNameLabelResolver;
use MageOS\AdvancedReport\Model\Label\StockLabelResolver;
use MageOS\AdvancedReport\Model\Stock\SalableStockSelectProvider;
use PHPUnit\Framework\TestCase;

final class CatalogSalableDatasetTest extends TestCase
{
    use ReportDatasetsFixtureTrait;

    public function testItDeclaresTheCatalogSalableDatasetWithStockReservedAndSalableMetrics(): void
    {
        $dataset = $this->loadReportDataset('catalog_salable');

        self::assertTrue($dataset->dateless);
        self::assertSame('catalog', $dataset->area);
        self::assertSame('MageOS_AdvancedReport::catalog', $dataset->aclResource);
        self::assertSame(SalableStockSelectProvider::class, $dataset->baseProvider);
        self::assertEqualsCanonicalizing(
            ['stock_qty', 'reserved_qty', 'salable_qty', 'skus_salable'],
            array_keys($dataset->metrics)
        );
        foreach (['stock_qty', 'reserved_qty', 'salable_qty'] as $code) {
            self::assertSame(AggregateFunction::SUM, $dataset->getMetric($code)->aggregate);
        }
        $skus = $dataset->getMetric('skus_salable');
        self::assertSame(AggregateFunction::COUNT_DISTINCT, $skus->aggregate);
        self::assertSame('product_id', $skus->fieldCode);
        self::assertSame('is_salable_qty', $skus->conditionFieldCode);
        self::assertEqualsCanonicalizing(['stock_id', 'sku', 'product_id'], array_keys($dataset->dimensions));
        self::assertSame(StockLabelResolver::class, $dataset->getDimension('stock_id')->labelResolver);
        self::assertSame(ProductNameLabelResolver::class, $dataset->getDimension('product_id')->labelResolver);
    }
}
