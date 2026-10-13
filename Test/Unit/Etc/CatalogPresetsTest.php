<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use MageOS\AdvancedReport\Model\Preset\LowStockCoverageQuery;
use PHPUnit\Framework\TestCase;

final class CatalogPresetsTest extends TestCase
{
    use ReportDatasetsFixtureTrait;

    public function testItDeclaresProductPerformanceWithAnAlternateSimpleSkuDimension(): void
    {
        $preset = $this->loadReportPresets()['product_performance'];

        self::assertSame('catalog', $preset->area);
        self::assertSame('sales_items', $preset->dataset);
        self::assertSame(['units_sold', 'net_sales', 'gross_margin', 'return_rate'], $preset->metrics);
        self::assertSame('parent_sku', $preset->dimensions[0]['code']);
        self::assertSame('sku', $preset->alternateDimension);
    }

    public function testItDescribesProductPerformanceWithoutShippedUnits(): void
    {
        $preset = $this->loadReportPresets()['product_performance'];

        self::assertSame(
            'Units sold, net sales, margin and return rate for each product.',
            $preset->description
        );
    }

    public function testItDeclaresStockValueBySkuAndSource(): void
    {
        $preset = $this->loadReportPresets()['stock_value'];

        self::assertSame('catalog_stock', $preset->dataset);
        self::assertSame(['qty_on_hand', 'stock_value_cost', 'stock_value_retail'], $preset->metrics);
        self::assertSame(['sku', 'source_code'], array_column($preset->dimensions, 'code'));
    }

    public function testItDeclaresSalesByCategoryAndLowStockCoverage(): void
    {
        $presets = $this->loadReportPresets();

        self::assertSame(['category_id'], array_column($presets['sales_by_category']->dimensions, 'code'));
        self::assertSame(
            LowStockCoverageQuery::class,
            $presets['low_stock_coverage']->presetQuery
        );
        self::assertContains('coverage_days', $presets['low_stock_coverage']->metrics);
    }
}
