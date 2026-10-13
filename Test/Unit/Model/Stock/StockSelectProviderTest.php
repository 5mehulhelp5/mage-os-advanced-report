<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Stock;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Manager as ModuleManager;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Stock\LegacyStockProvider;
use MageOS\AdvancedReport\Model\Stock\MsiStockProvider;
use MageOS\AdvancedReport\Model\Stock\StockSelectProvider;
use PHPUnit\Framework\TestCase;

final class StockSelectProviderTest extends TestCase
{
    public function testItUsesTheMsiProviderWhenMagentoInventoryApiIsEnabled(): void
    {
        $this->assertSame('msi', $this->resolve(true));
    }

    public function testItUsesTheLegacyProviderOtherwise(): void
    {
        $this->assertSame('legacy', $this->resolve(false));
    }

    private function resolve(bool $msiEnabled): string
    {
        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturnCallback(
            static fn (string $name): bool => $name === 'Magento_InventoryApi' && $msiEnabled
        );
        $connection = $this->createStub(AdapterInterface::class);
        $dataset = $this->dataset();
        $legacySelect = $this->createStub(Select::class);
        $msiSelect = $this->createStub(Select::class);
        $legacy = $this->createStub(LegacyStockProvider::class);
        $legacy->method('getSelect')->willReturn($legacySelect);
        $msi = $this->createStub(MsiStockProvider::class);
        $msi->method('getSelect')->willReturn($msiSelect);

        $result = (new StockSelectProvider($moduleManager, $legacy, $msi))->getSelect($connection, $dataset);

        return $result === $msiSelect ? 'msi' : ($result === $legacySelect ? 'legacy' : 'other');
    }

    private function dataset(): Dataset
    {
        return new Dataset('catalog_stock', 'Stock', 'acl', 'catalog', true, null, null, 'provider', [], [], [], [], []);
    }
}
