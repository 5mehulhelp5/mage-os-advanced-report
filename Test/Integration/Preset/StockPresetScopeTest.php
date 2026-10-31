<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Preset;

use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\ConfigurableProduct\Test\Fixture\AddProductToCart as AddConfigurableProductToCart;
use Magento\ConfigurableProduct\Test\Fixture\Attribute as ConfigurableAttribute;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProduct;
use Magento\InventoryApi\Test\Fixture\SourceItems;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderStore;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use MageOS\AdvancedReport\Test\Integration\Helper\StoreProvisioner;
use PHPUnit\Framework\TestCase;

/**
 * Request scope of the `dead_stock` and `never_sold` presets.
 *
 * order1: configurable ar-ssc-conf with child ar-ssc-red x 1. order2: simple ar-ssc-store x 1, moved to the second
 * store view. Both orders are 5 days old; every product has stock 50. ar-ssc-blue (unsold sibling child) and
 * ar-ssc-other are never ordered.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    Config('carriers/flatrate/active', '1'),
    Config('carriers/flatrate/type', 'O'),
    Config('carriers/flatrate/price', '5'),
    Config('payment/checkmo/active', '1'),
    DataFixture(ConfigurableAttribute::class, as: 'attr'),
    DataFixture(Product::class, ['sku' => 'ar-ssc-red', 'price' => 30, 'tax_class_id' => 0], 'red'),
    DataFixture(Product::class, ['sku' => 'ar-ssc-blue', 'price' => 30, 'tax_class_id' => 0], 'blue'),
    DataFixture(
        ConfigurableProduct::class,
        ['sku' => 'ar-ssc-conf', '_options' => ['$attr$'], '_links' => ['$red$', '$blue$'], 'tax_class_id' => 0],
        'conf'
    ),
    DataFixture(Product::class, ['sku' => 'ar-ssc-store', 'price' => 10, 'tax_class_id' => 0], 'store'),
    DataFixture(Product::class, ['sku' => 'ar-ssc-other', 'price' => 10, 'tax_class_id' => 0], 'other'),
    DataFixture(SourceItems::class, [
        ['sku' => 'ar-ssc-red', 'source_code' => 'default', 'quantity' => 50, 'status' => 1],
        ['sku' => 'ar-ssc-blue', 'source_code' => 'default', 'quantity' => 50, 'status' => 1],
        ['sku' => 'ar-ssc-store', 'source_code' => 'default', 'quantity' => 50, 'status' => 1],
        ['sku' => 'ar-ssc-other', 'source_code' => 'default', 'quantity' => 50, 'status' => 1],
    ]),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(
        AddConfigurableProductToCart::class,
        ['cart_id' => '$cart1.id$', 'product_id' => '$conf.id$', 'child_product_id' => '$red.id$', 'qty' => 1]
    ),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-ssc-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$store.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-ssc-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
]
final class StockPresetScopeTest extends TestCase
{
    private const array PRESETS = ['dead_stock', 'never_sold'];

    private static int $secondStoreId;

    private ReportTestRunner $runner;

    public static function setUpBeforeClass(): void
    {
        self::$secondStoreId = (new StoreProvisioner())->ensureStoreView();
    }

    protected function setUp(): void
    {
        Bootstrap::getObjectManager()->get(CacheType::class)->clean();
        $this->runner = new ReportTestRunner();
        $this->runner->login();
        $objectManager = Bootstrap::getObjectManager();
        foreach (['order1', 'order2'] as $key) {
            $objectManager->get(SetOrderCreatedAt::class)->apply([
                'order_id' => (int) DataFixtureStorageManager::getStorage()->get($key)->getId(),
                'created_at' => gmdate('Y-m-d 12:00:00', time() - 5 * 86400),
            ]);
        }
        $objectManager->get(SetOrderStore::class)->apply([
            'order_id' => (int) DataFixtureStorageManager::getStorage()->get('order2')->getId(),
            'store_id' => self::$secondStoreId,
        ]);
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    public function testItKeepsTreatingAConfigurableChildSoldThroughItsParentAsSoldInDeadStockAndNeverSold(): void
    {
        foreach (self::PRESETS as $preset) {
            $skus = $this->skus($preset);

            $this->assertNotContains('ar-ssc-red', $skus, $preset);
            $this->assertContains('ar-ssc-blue', $skus, $preset);
        }
    }

    public function testItOnlyConsidersSalesOfTheRequestedStoresInDeadStockAndNeverSold(): void
    {
        $defaultStoreId = (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)
            ->getDefaultStoreView()->getId();
        foreach (self::PRESETS as $preset) {
            $this->assertNotContains('ar-ssc-store', $this->skus($preset), $preset . ' all stores');
            $this->assertContains(
                'ar-ssc-store',
                $this->skus($preset, ['storeIds' => [$defaultStoreId]]),
                $preset . ' default store'
            );
            $this->assertNotContains(
                'ar-ssc-store',
                $this->skus($preset, ['storeIds' => [self::$secondStoreId]]),
                $preset . ' second store'
            );
        }
    }

    public function testItAppliesTheRequestFiltersToDeadStockAndNeverSold(): void
    {
        foreach (self::PRESETS as $preset) {
            $this->assertSame(
                ['ar-ssc-blue'],
                $this->skus($preset, ['filters' => [['field' => 'sku', 'operator' => 'eq', 'value' => 'ar-ssc-blue']]]),
                $preset
            );
            $this->assertSame(
                [],
                $this->skus($preset, ['filters' => [['field' => 'qty', 'operator' => 'gte', 'value' => 51]]]),
                $preset
            );
        }
    }

    /**
     * Unsold-stock SKUs of this test among the preset rows.
     *
     * @param array<string,mixed> $overrides
     * @return string[]
     */
    private function skus(string $preset, array $overrides = []): array
    {
        $result = $this->runner->runPreset(
            $preset,
            gmdate('Y-m-d', time() - 86400 * 60),
            gmdate('Y-m-d'),
            $overrides
        );
        $skus = array_column($this->runner->rows($result), 'sku');

        return array_values(array_filter($skus, static fn (string $sku): bool => str_starts_with($sku, 'ar-ssc-')));
    }
}
