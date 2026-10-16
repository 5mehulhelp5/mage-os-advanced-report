<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Inventory;

use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Sales\Test\Fixture\Shipment;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Test\Integration\Fixture\CancelOrder;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Stock, reserved and salable quantity (`catalog_salable`) through the order lifecycle. Default stock 1, default
 * source only (single-source mode, no extra stocks or links: those would be DDL and leak out of DbIsolation).
 *
 * Product ar-ss-a: quantity 20, own minimum quantity 2 (`use_config_min_qty` = 0); config min qty pinned to 0.
 * Every test method declares its full fixture chain (method-level fixtures replace class-level ones): product,
 * order 1 for 3 units, then the steps of that test.
 *
 * Expected values (report == literal == core `GetProductSalableQtyInterface`):
 *  | step                                    | stock | reserved | salable                              |
 *  | order 1 placed (3 units)                | 20    | 3        | 15 = 20 - 3 - 2                      |
 *  | order 1 shipped (deducts 3, compensates)| 17    | 0        | 15 = 17 - 0 - 2                      |
 *  | order 2 (4 units) placed then canceled  | 17    | 0        | 15 = 17 - (-4 + 4) - 2               |
 *  | default source set to 25                | 25    | 0        | 23 = 25 - 0 - 2                      |
 *  | default source set to 40 (last test)    | 40    | 0        | 38 = 40 - 0 - 2                      |
 *  | default source set to 2 (last test)     | 2     | 0        | 0 = 2 - 0 - 2                        |
 * Reserved is reported positive when quantity is reserved.
 */
#[DbIsolation(true), AppIsolation(true), AppArea('adminhtml')]
final class SalableStockTest extends TestCase
{
    private const string SKU = 'ar-ss-a';
    private const int DEFAULT_STOCK_ID = 1;

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

    #[
        Config('cataloginventory/item_options/min_qty', '0'),
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        DataFixture(
            Product::class,
            [
                'sku' => 'ar-ss-a',
                'price' => 10,
                'tax_class_id' => 0,
                'extension_attributes' => [
                    'stock_item' => ['qty' => 20, 'use_config_min_qty' => false, 'min_qty' => 2, 'is_in_stock' => true],
                ],
            ],
            'product'
        ),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 3]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-ss-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    ]
    public function testItReportsReservedQuantityAfterAnOrderIsPlaced(): void
    {
        // order 3 -> reserved 3, stock 20 (no source deduction before shipment), salable 20 - 3 - 2 = 15
        $this->assertStep(20.0, 3.0, 15.0);
    }

    #[
        Config('cataloginventory/item_options/min_qty', '0'),
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        DataFixture(
            Product::class,
            [
                'sku' => 'ar-ss-a',
                'price' => 10,
                'tax_class_id' => 0,
                'extension_attributes' => [
                    'stock_item' => ['qty' => 20, 'use_config_min_qty' => false, 'min_qty' => 2, 'is_in_stock' => true],
                ],
            ],
            'product'
        ),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 3]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-ss-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(Shipment::class, ['order_id' => '$order1.id$']),
    ]
    public function testItReportsPhysicalQuantityReducedAndReservationReleasedAfterShipment(): void
    {
        // shipped 3 -> stock 20 - 3 = 17, compensation +3 -> reserved 0, salable 17 - 0 - 2 = 15
        $this->assertStep(17.0, 0.0, 15.0);
    }

    #[
        Config('cataloginventory/item_options/min_qty', '0'),
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        DataFixture(
            Product::class,
            [
                'sku' => 'ar-ss-a',
                'price' => 10,
                'tax_class_id' => 0,
                'extension_attributes' => [
                    'stock_item' => ['qty' => 20, 'use_config_min_qty' => false, 'min_qty' => 2, 'is_in_stock' => true],
                ],
            ],
            'product'
        ),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 3]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-ss-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(Shipment::class, ['order_id' => '$order1.id$']),
        DataFixture(GuestCart::class, as: 'cart2'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 4]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-ss-2@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
        DataFixture(CancelOrder::class, ['order_id' => '$order2.id$']),
    ]
    public function testItReleasesTheReservationWhenTheOrderIsCanceled(): void
    {
        // order 1 shipped (17, reserved 0); order 2 for 4 placed (-4) then canceled (+4) -> reserved 0, salable 15
        $this->assertStep(17.0, 0.0, 15.0);
    }

    #[
        Config('cataloginventory/item_options/min_qty', '0'),
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        DataFixture(
            Product::class,
            [
                'sku' => 'ar-ss-a',
                'price' => 10,
                'tax_class_id' => 0,
                'extension_attributes' => [
                    'stock_item' => ['qty' => 20, 'use_config_min_qty' => false, 'min_qty' => 2, 'is_in_stock' => true],
                ],
            ],
            'product'
        ),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 3]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-ss-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(Shipment::class, ['order_id' => '$order1.id$']),
        DataFixture(GuestCart::class, as: 'cart2'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 4]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-ss-2@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
        DataFixture(CancelOrder::class, ['order_id' => '$order2.id$']),
    ]
    public function testItReflectsASourceQuantityAdjustmentInSalableQuantity(): void
    {
        $this->setDefaultSourceQuantity(25.0);

        // source set to 25, nothing reserved -> salable 25 - 0 - 2 = 23
        $this->assertStep(25.0, 0.0, 23.0);
    }

    #[
        Config('cataloginventory/item_options/min_qty', '0'),
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        DataFixture(
            Product::class,
            [
                'sku' => 'ar-ss-a',
                'price' => 10,
                'tax_class_id' => 0,
                'extension_attributes' => [
                    'stock_item' => ['qty' => 20, 'use_config_min_qty' => false, 'min_qty' => 2, 'is_in_stock' => true],
                ],
            ],
            'product'
        ),
        DataFixture(GuestCart::class, as: 'cart1'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 3]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-ss-1@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
        DataFixture(Shipment::class, ['order_id' => '$order1.id$']),
        DataFixture(GuestCart::class, as: 'cart2'),
        DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 4]),
        DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-ss-2@example.com']),
        DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
        DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
        DataFixture(CancelOrder::class, ['order_id' => '$order2.id$']),
    ]
    public function testItMatchesCoreSalableQuantityAtEveryStep(): void
    {
        $this->assertStep(17.0, 0.0, 15.0);

        $this->setDefaultSourceQuantity(40.0);
        // 40 - 0 - 2
        $this->assertStep(40.0, 0.0, 38.0);

        $this->setDefaultSourceQuantity(2.0);
        // salable mirrors core and is not clamped: 2 - 0 - 2 = 0
        $this->assertStep(2.0, 0.0, 0.0);
    }

    private function assertStep(float $stock, float $reserved, float $salable): void
    {
        $result = $this->runner->run(
            'catalog_salable',
            ['stock_qty', 'reserved_qty', 'salable_qty'],
            [['code' => 'sku', 'granularity' => null]],
            [
                ['field' => 'sku', 'operator' => 'in', 'value' => [self::SKU]],
                ['field' => 'stock_id', 'operator' => 'in', 'value' => [self::DEFAULT_STOCK_ID]],
            ]
        );

        $rows = $this->runner->rows($result);
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta($stock, (float) $rows[0]['stock_qty'], 0.0001, 'stock_qty');
        $this->assertEqualsWithDelta($reserved, (float) $rows[0]['reserved_qty'], 0.0001, 'reserved_qty');
        $this->assertEqualsWithDelta($salable, (float) $rows[0]['salable_qty'], 0.0001, 'salable_qty');

        $core = Bootstrap::getObjectManager()->get(GetProductSalableQtyInterface::class)
            ->execute(self::SKU, self::DEFAULT_STOCK_ID);
        $this->assertEqualsWithDelta($core, (float) $rows[0]['salable_qty'], 0.0001, 'core salable quantity');
    }

    private function setDefaultSourceQuantity(float $quantity): void
    {
        $objectManager = Bootstrap::getObjectManager();
        /** @var SourceItemInterface $item */
        $item = $objectManager->get(SourceItemInterfaceFactory::class)->create();
        $item->setSku(self::SKU);
        $item->setSourceCode($objectManager->get(DefaultSourceProviderInterface::class)->getCode());
        $item->setQuantity($quantity);
        $item->setStatus(SourceItemInterface::STATUS_IN_STOCK);
        $objectManager->get(SourceItemsSaveInterface::class)->execute([$item]);
    }
}
