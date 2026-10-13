<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Stock\StockSelectProvider;
use PHPUnit\Framework\TestCase;

final class CatalogStockDatasetTest extends TestCase
{
    use ReportDatasetsFixtureTrait;

    public function testItDefinesCatalogStockAsDatelessWithAProviderBasedSource(): void
    {
        $dataset = $this->loadCatalogStockDataset();

        self::assertTrue($dataset->dateless);
        self::assertSame(StockSelectProvider::class, $dataset->baseProvider);
        self::assertNull($dataset->baseTable);
        self::assertNull($dataset->baseAlias);
        self::assertFalse($dataset->hasField(Dataset::FIELD_CREATED_AT));
        self::assertFalse($dataset->hasField(Dataset::FIELD_STORE_ID));
    }

    public function testItGuardsSalesItemsWithTheSalesAclAndCatalogStockWithTheCatalogAcl(): void
    {
        $datasets = $this->loadReportDatasets();

        self::assertSame('MageOS_AdvancedReport::sales', $datasets['sales_items']->aclResource);
        self::assertSame('MageOS_AdvancedReport::catalog', $datasets['catalog_stock']->aclResource);
    }

    public function testItPassesDefinitionValidationForBothDatasets(): void
    {
        $datasets = $this->loadReportDatasets();

        self::assertCount(
            18,
            $datasets['sales_items']->metrics,
            'No sales_items metric should have been dropped by the validator.'
        );
        self::assertCount(
            8,
            $datasets['catalog_stock']->metrics,
            'No catalog_stock metric should have been dropped by the validator.'
        );
    }

    private function loadCatalogStockDataset(): Dataset
    {
        return $this->loadReportDataset('catalog_stock');
    }
}
