<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Preset;

use Magento\Bundle\Test\Fixture\AddProductToCart as AddBundleProductToCart;
use Magento\Bundle\Test\Fixture\Link as BundleSelection;
use Magento\Bundle\Test\Fixture\Option as BundleOption;
use Magento\Bundle\Test\Fixture\Product as BundleProduct;
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
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Units sold inside bundles and configurables in `low_stock_coverage`.
 *
 * order1: configurable ar-lscb-conf, child ar-lscb-red x 1. order2: bundle ar-lscb-bundle x 1 made of ar-lscb-b1 x 1
 * and ar-lscb-b2 x 1. Both orders are 5 days old (12:00 UTC). Every product has stock 50.
 *
 * Expected units_sold_30d: ar-lscb-red 1 (configurable child counted once), ar-lscb-b1 1, ar-lscb-b2 1.
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
    DataFixture(Product::class, ['sku' => 'ar-lscb-red', 'price' => 30, 'tax_class_id' => 0], 'red'),
    DataFixture(Product::class, ['sku' => 'ar-lscb-blue', 'price' => 30, 'tax_class_id' => 0], 'blue'),
    DataFixture(
        ConfigurableProduct::class,
        ['sku' => 'ar-lscb-conf', '_options' => ['$attr$'], '_links' => ['$red$', '$blue$'], 'tax_class_id' => 0],
        'conf'
    ),
    DataFixture(Product::class, ['sku' => 'ar-lscb-b1', 'price' => 10, 'tax_class_id' => 0], 'b1'),
    DataFixture(Product::class, ['sku' => 'ar-lscb-b2', 'price' => 20, 'tax_class_id' => 0], 'b2'),
    DataFixture(BundleSelection::class, ['sku' => '$b1.sku$', 'qty' => 1], 'link1'),
    DataFixture(BundleSelection::class, ['sku' => '$b2.sku$', 'qty' => 1], 'link2'),
    DataFixture(BundleOption::class, ['product_links' => ['$link1$']], 'opt1'),
    DataFixture(BundleOption::class, ['product_links' => ['$link2$']], 'opt2'),
    DataFixture(
        BundleProduct::class,
        ['sku' => 'ar-lscb-bundle', '_options' => ['$opt1$', '$opt2$'], 'tax_class_id' => 0],
        'bundle'
    ),
    DataFixture(SourceItems::class, [
        ['sku' => 'ar-lscb-red', 'source_code' => 'default', 'quantity' => 50, 'status' => 1],
        ['sku' => 'ar-lscb-b1', 'source_code' => 'default', 'quantity' => 50, 'status' => 1],
        ['sku' => 'ar-lscb-b2', 'source_code' => 'default', 'quantity' => 50, 'status' => 1],
    ]),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(
        AddConfigurableProductToCart::class,
        ['cart_id' => '$cart1.id$', 'product_id' => '$conf.id$', 'child_product_id' => '$red.id$', 'qty' => 1]
    ),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-lscb-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(
        AddBundleProductToCart::class,
        [
            'cart_id' => '$cart2.id$',
            'product_id' => '$bundle.id$',
            'selections' => [['$b1.id$'], ['$b2.id$']],
            'qty' => 1,
        ]
    ),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-lscb-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
]
final class LowStockCoverageBundleTest extends TestCase
{
    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        Bootstrap::getObjectManager()->get(CacheType::class)->clean();
        $this->runner = new ReportTestRunner();
        $this->runner->login();
        $setter = Bootstrap::getObjectManager()->get(SetOrderCreatedAt::class);
        foreach (['order1', 'order2'] as $key) {
            $setter->apply([
                'order_id' => (int) DataFixtureStorageManager::getStorage()->get($key)->getId(),
                'created_at' => gmdate('Y-m-d 12:00:00', time() - 5 * 86400),
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    public function testItCountsUnitsOfASimpleProductSoldInsideABundleInLowStockCoverage(): void
    {
        $this->assertEqualsWithDelta(1.0, (float) $this->unitsSold('ar-lscb-b1'), 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $this->unitsSold('ar-lscb-b2'), 0.0001);
    }

    public function testItDoesNotDoubleCountConfigurableChildUnits(): void
    {
        $this->assertEqualsWithDelta(1.0, (float) $this->unitsSold('ar-lscb-red'), 0.0001);
    }

    private function unitsSold(string $sku): mixed
    {
        $result = $this->runner->runPreset('low_stock_coverage', gmdate('Y-m-d', time() - 86400 * 60), gmdate('Y-m-d'));
        foreach ($this->runner->rows($result) as $row) {
            if ($row['sku'] === $sku) {
                return $row['units_sold_30d'];
            }
        }

        $this->fail(\sprintf('SKU %s not listed.', $sku));
    }
}
