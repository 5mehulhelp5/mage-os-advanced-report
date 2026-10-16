<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Stock;

use Magento\Framework\Module\Manager as ModuleManager;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Stock\MsiDatasetAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MsiDatasetAvailability::class)]
final class MsiDatasetAvailabilityTest extends TestCase
{
    public function testItReportsTheCatalogSalableDatasetUnavailableWhenAnMsiModuleIsDisabled(): void
    {
        $availability = $this->availability(['Magento_InventoryApi', 'Magento_InventoryCatalog']);

        self::assertFalse($availability->isAvailable($this->dataset('catalog_salable')));
    }

    public function testItReportsTheDatasetAvailableWhenAllMsiModulesAreEnabled(): void
    {
        $availability = $this->availability([
            'Magento_InventoryApi',
            'Magento_InventoryIndexer',
            'Magento_InventoryReservations',
            'Magento_InventoryCatalog',
        ]);

        self::assertTrue($availability->isAvailable($this->dataset('catalog_salable')));
    }

    public function testItNeverRestrictsOtherDatasets(): void
    {
        self::assertTrue($this->availability([])->isAvailable($this->dataset('catalog_stock')));
    }

    /**
     * @param string[] $enabled
     */
    private function availability(array $enabled): MsiDatasetAvailability
    {
        $manager = $this->createStub(ModuleManager::class);
        $manager->method('isEnabled')->willReturnCallback(
            static fn (string $module): bool => \in_array($module, $enabled, true)
        );

        return new MsiDatasetAvailability(
            $manager,
            ['catalog_salable'],
            ['Magento_InventoryApi', 'Magento_InventoryIndexer', 'Magento_InventoryReservations', 'Magento_InventoryCatalog']
        );
    }

    private function dataset(string $code): Dataset
    {
        return new Dataset($code, $code, 'acl', 'catalog', true, null, null, null, [], [], [], [], []);
    }
}
