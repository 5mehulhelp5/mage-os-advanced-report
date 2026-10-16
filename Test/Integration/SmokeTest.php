<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Module\ModuleListInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\Config\Data;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Extender\FirstReportAttributePresetExtender;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

#[DbIsolation(true)]
#[AppIsolation(true)]
#[AppArea('adminhtml')]
final class SmokeTest extends TestCase
{
    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    public function testItRegistersTheModuleAsEnabled(): void
    {
        $moduleList = Bootstrap::getObjectManager()->get(ModuleListInterface::class);

        $this->assertTrue($moduleList->has('MageOS_AdvancedReport'));
    }

    public function testItLoadsEveryShippedDatasetWithAllRawMetricsAndDimensions(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $raw = $objectManager->get(Data::class)->get(null);
        $registry = $objectManager->get(DefinitionRegistry::class);

        $this->assertNotEmpty($raw['datasets']);
        foreach ($raw['datasets'] as $code => $rawDataset) {
            if ($code === 'catalog_salable' && !$this->isMsiEnabled()) {
                $this->assertArrayNotHasKey($code, $registry->getDatasets());
                continue;
            }
            $dataset = $registry->getDataset($code);
            $this->assertEqualsCanonicalizing(
                array_keys($rawDataset['metrics']),
                array_keys($dataset->metrics),
                "Metrics of dataset {$code}"
            );
            $missing = array_diff(array_keys($rawDataset['dimensions']), array_keys($dataset->dimensions));
            $this->assertSame([], $missing, "Dimensions missing from dataset {$code}");
        }
    }

    public function testItLoadsEveryShippedPresetExceptTheUnresolvedAttributePlaceholder(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $raw = $objectManager->get(Data::class)->get(null);
        $presets = $objectManager->get(DefinitionRegistry::class)->getPresets();

        $this->assertNotEmpty($raw['presets']);
        foreach ($raw['presets'] as $code => $rawPreset) {
            $usesPlaceholder = \in_array(
                FirstReportAttributePresetExtender::PLACEHOLDER,
                array_column($rawPreset['dimensions'], 'code'),
                true
            );
            if ($usesPlaceholder) {
                continue;
            }
            if (!$this->isMsiEnabled() && $rawPreset['dataset'] === 'catalog_salable') {
                continue;
            }
            $this->assertArrayHasKey($code, $presets, "Preset {$code} was not loaded");
        }
    }

    public function testItRunsTheSalesOrdersOrderCountReportOnAnEmptyDatabaseReturningZeroTotals(): void
    {
        $result = $this->runner->run('sales_orders', ['orders']);

        $this->assertEquals([['orders' => 0]], $this->runner->rows($result));
        $this->assertEquals(0, $this->runner->totals($result)['orders']);
    }

    public function testItRunsEveryShippedPresetWithoutThrowingOnAnEmptyDatabase(): void
    {
        $presets = Bootstrap::getObjectManager()->get(DefinitionRegistry::class)->getPresets();

        $this->assertNotEmpty($presets);
        foreach (array_keys($presets) as $code) {
            $result = $this->runner->runPreset($code, '2026-01-01', '2026-01-31');
            $this->assertGreaterThanOrEqual(0, $result->getTotalRowCount(), "Preset {$code}");
        }
    }

    public function testItRunsTheCatalogSalableDatasetThroughTheReportRunner(): void
    {
        if (!$this->isMsiEnabled()) {
            $this->markTestSkipped('MSI modules are disabled.');
        }

        $result = $this->runner->run(
            'catalog_salable',
            ['stock_qty', 'reserved_qty', 'salable_qty', 'skus_salable'],
            [['code' => 'stock_id', 'granularity' => null]]
        );

        $this->assertGreaterThanOrEqual(0, $result->getTotalRowCount());
    }

    private function isMsiEnabled(): bool
    {
        $moduleManager = Bootstrap::getObjectManager()->get(ModuleManager::class);
        foreach (['Magento_InventoryApi', 'Magento_InventoryIndexer', 'Magento_InventoryReservations', 'Magento_InventoryCatalog'] as $module) {
            if (!$moduleManager->isEnabled($module)) {
                return false;
            }
        }

        return true;
    }
}
