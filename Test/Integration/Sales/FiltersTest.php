<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Sales;

use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Sales\Test\Fixture\Invoice;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderStore;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use MageOS\AdvancedReport\Test\Integration\Helper\StoreProvisioner;
use PHPUnit\Framework\TestCase;

/**
 * Date-range and store/website filter semantics.
 *
 * The store timezone is pinned to UTC with method-level `#[Config]` attributes at default and website scope
 * (the report reads the website scope): this Magento version ignores class-level `#[Config]` attributes.
 * Shipping is the default flat rate of 5.00 per item (one item per order); counted statuses use the default ''.
 *
 * Inputs (product ar-fl-a 10.00, flat rate 5.00 per order, no tax, one unit per order, guests):
 *  - order 1: created 2026-06-01 00:00:00 (start boundary), default store, invoiced -> processing
 *  - order 2: created 2026-06-30 23:59:59 (end boundary), default store, pending
 *  - order 3: created 2026-07-01 00:00:00 (outside the range), default store, pending
 *  - order 4: created 2026-06-15 12:00:00, pending, moved in the test to store ar_store_2 (second website)
 * Range 2026-06-01..2026-06-30 counts orders 1, 2, 4 (counted statuses '' = all but canceled).
 *
 * Expected values (hard-coded in the tests):
 *  | report                                     | value                                              |
 *  | orders, all stores                         | 3 (orders 1, 2, 4); order 3 excluded               |
 *  | gross_sales, all stores                    | 30.00 = 3 * 10                                     |
 *  | store 1 only: orders / gross_sales         | 2 / 20.00 (orders 1, 2)                            |
 *  | store 2 only: orders / gross_sales         | 1 / 10.00 (order 4)                                |
 *  | by website_id                              | default website => 2, second website => 1          |
 *  | status = processing                        | 1 order, gross 10.00, total_sales 15.00 = 10 + 5   |
 *  | status = pending                           | 2 orders (2, 4), gross 20.00, total_sales 30.00     |
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-fl-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-fl-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(Invoice::class, ['order_id' => '$order1.id$']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-06-01 00:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-fl-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-06-30 23:59:59']),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-fl-3@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-07-01 00:00:00']),
    DataFixture(GuestCart::class, as: 'cart4'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart4.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart4.id$', 'email' => 'ar-fl-4@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart4.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart4.id$'], 'order4'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order4.id$', 'created_at' => '2026-06-15 12:00:00']),
]
final class FiltersTest extends TestCase
{
    private const FROM = '2026-06-01';
    private const TO = '2026-06-30';

    /** @var array{website_id:int, store_id:int} */
    private static array $secondWebsiteStore;

    private ReportTestRunner $runner;

    public static function setUpBeforeClass(): void
    {
        self::$secondWebsiteStore = (new StoreProvisioner())->ensureSecondWebsiteStore();
    }

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
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
        Config('general/locale/timezone', 'UTC', 'website', 'ar_website_2'),
    ]
    public function testItIncludesOrdersCreatedOnTheFirstAndLastDayOfTheRange(): void
    {
        $this->moveOrderFourToSecondStore();

        $result = $this->runner->run('sales_orders', ['orders'], [], [], self::FROM, self::TO);

        // orders 1 (start boundary), 2 (end boundary), 4 (mid range)
        $this->assertEquals(3, $this->runner->totals($result)['orders']);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
        Config('general/locale/timezone', 'UTC', 'website', 'ar_website_2'),
    ]
    public function testItExcludesOrdersCreatedAfterTheEndOfTheRange(): void
    {
        $result = $this->runner->run('sales_orders', ['orders', 'gross_sales'], [], [], self::FROM, '2026-06-29');

        // order 2 (06-30 23:59:59) is outside, order 3 (07-01) too; orders 1 and 4 remain (both on the default store)
        $this->assertEquals(2, $this->runner->totals($result)['orders']);
        $this->assertEqualsWithDelta(20.0, $this->runner->totals($result)['gross_sales'], 0.0001);

        $full = $this->runner->run('sales_orders', ['orders'], [], [], self::FROM, self::TO);
        // order 3 (07-01 00:00:00) never leaks into June: 3 of the 4 orders (1, 2, 4) are in range
        $this->assertEquals(3, $this->runner->totals($full)['orders']);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
        Config('general/locale/timezone', 'UTC', 'website', 'ar_website_2'),
    ]
    public function testItRestrictsTotalsToTheSelectedStore(): void
    {
        $this->moveOrderFourToSecondStore();
        $defaultStoreId = $this->defaultStoreId();

        $first = $this->runner->run(
            'sales_orders',
            ['orders', 'gross_sales'],
            [],
            [],
            self::FROM,
            self::TO,
            [$defaultStoreId]
        );
        $second = $this->runner->run(
            'sales_orders',
            ['orders', 'gross_sales'],
            [],
            [],
            self::FROM,
            self::TO,
            [self::$secondWebsiteStore['store_id']]
        );

        // default store: orders 1, 2; second store: order 4
        $this->assertEquals(2, $this->runner->totals($first)['orders']);
        $this->assertEqualsWithDelta(20.0, $this->runner->totals($first)['gross_sales'], 0.0001);
        $this->assertEquals(1, $this->runner->totals($second)['orders']);
        $this->assertEqualsWithDelta(10.0, $this->runner->totals($second)['gross_sales'], 0.0001);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
        Config('general/locale/timezone', 'UTC', 'website', 'ar_website_2'),
    ]
    public function testItGroupsOrdersByWebsiteWithOneRowPerWebsite(): void
    {
        $this->moveOrderFourToSecondStore();

        $result = $this->runner->run(
            'sales_orders',
            ['orders'],
            [['code' => 'website_id', 'granularity' => null]],
            [],
            self::FROM,
            self::TO
        );

        $byWebsite = [];
        foreach ($this->runner->rows($result) as $row) {
            $byWebsite[(int) $row['website_id']] = (int) $row['orders'];
        }
        ksort($byWebsite);
        $defaultWebsiteId = (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)
            ->getDefaultStoreView()->getWebsiteId();
        $expected = [$defaultWebsiteId => 2, self::$secondWebsiteStore['website_id'] => 1];
        ksort($expected);
        $this->assertCount(2, $this->runner->rows($result));
        $this->assertSame($expected, $byWebsite);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
        Config('general/locale/timezone', 'UTC', 'website', 'ar_website_2'),
    ]
    public function testItAppliesAStatusFilterWithoutChangingPerOrderAmounts(): void
    {
        $this->moveOrderFourToSecondStore();
        $metrics = ['orders', 'gross_sales', 'total_sales'];

        $processing = $this->runner->run(
            'sales_orders',
            $metrics,
            [],
            [['field' => 'status', 'operator' => 'eq', 'value' => 'processing']],
            self::FROM,
            self::TO
        );
        $pending = $this->runner->run(
            'sales_orders',
            $metrics,
            [],
            [['field' => 'status', 'operator' => 'eq', 'value' => 'pending']],
            self::FROM,
            self::TO
        );

        // processing: order 1 => 10 gross, 15 = 10 + 5 shipping; pending: orders 2, 4 => 15 each
        $this->assertEquals(1, $this->runner->totals($processing)['orders']);
        $this->assertEqualsWithDelta(10.0, $this->runner->totals($processing)['gross_sales'], 0.0001);
        $this->assertEqualsWithDelta(15.0, $this->runner->totals($processing)['total_sales'], 0.0001);
        $this->assertEquals(2, $this->runner->totals($pending)['orders']);
        $this->assertEqualsWithDelta(20.0, $this->runner->totals($pending)['gross_sales'], 0.0001);
        $this->assertEqualsWithDelta(30.0, $this->runner->totals($pending)['total_sales'], 0.0001);
    }

    #[
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
        Config('general/locale/timezone', 'UTC', 'website', 'ar_website_2'),
    ]
    public function testItReportsFilteredTotalsEqualToTheSumOfTheMatchingOrders(): void
    {
        $this->moveOrderFourToSecondStore();

        $filters = [['field' => 'has_coupon', 'operator' => 'eq', 'value' => false]];
        $all = $this->runner->run('sales_orders', ['orders', 'total_sales'], [], $filters, self::FROM, self::TO);
        $perStore = $this->runner->run(
            'sales_orders',
            ['orders', 'total_sales'],
            [['code' => 'store_id', 'granularity' => null]],
            $filters,
            self::FROM,
            self::TO
        );

        // no coupons: all 3 in-range orders match; total_sales 45.00 = 3 * (10 + 5)
        $this->assertEquals(3, $this->runner->totals($all)['orders']);
        $this->assertEqualsWithDelta(45.0, $this->runner->totals($all)['total_sales'], 0.0001);
        $sum = 0.0;
        foreach ($this->runner->rows($perStore) as $row) {
            $sum += (float) $row['total_sales'];
        }
        $this->assertEqualsWithDelta(45.0, $sum, 0.0001);
    }

    public function testItProvisionsTheExtraStoreViewAndSecondWebsiteIdempotently(): void
    {
        $provisioner = new StoreProvisioner();

        $this->assertSame($provisioner->ensureStoreView(), $provisioner->ensureStoreView());
        $this->assertSame(self::$secondWebsiteStore, $provisioner->ensureSecondWebsiteStore());
        $this->assertNotSame(self::$secondWebsiteStore['store_id'], $provisioner->ensureStoreView());
    }

    private function moveOrderFourToSecondStore(): void
    {
        $order = DataFixtureStorageManager::getStorage()->get('order4');
        Bootstrap::getObjectManager()->get(SetOrderStore::class)->apply([
            'order_id' => (int) $order->getId(),
            'store_id' => self::$secondWebsiteStore['store_id'],
        ]);
    }

    private function defaultStoreId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)
            ->getDefaultStoreView()->getId();
    }
}
