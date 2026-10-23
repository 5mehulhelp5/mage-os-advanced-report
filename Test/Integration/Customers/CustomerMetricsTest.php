<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Customers;

use DateTimeImmutable;
use DateTimeZone;
use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Customer\Test\Fixture\Customer;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\CustomerCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Sales\Test\Fixture\Creditmemo;
use Magento\Sales\Test\Fixture\Invoice;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use MageOS\AdvancedReport\Test\Integration\Fixture\CancelOrder;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderStore;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use MageOS\AdvancedReport\Test\Integration\Helper\StoreProvisioner;
use PHPUnit\Framework\TestCase;

/**
 * Customer metrics: guest/registered, new/returning, identity across store views, lifetime value.
 *
 * Inputs: product ar-cm-a (10.00, no tax), flat rate 5.00 per order (one unit per order), default counted
 * statuses. Timezone pinned to UTC. All orders are created at 12:00:00 UTC on the given day:
 *  | order | customer                          | date       | notes                                        |
 *  | 1     | C1 ar-cm-c1@example.com           | 2026-01-10 | invoiced + credit memo (closed)              |
 *  | 2     | C1                                | 2026-03-10 | moved to the extra store view                |
 *  | 3     | C2 ar-cm-c2@example.com           | 2026-02-01 | canceled                                     |
 *  | 4     | C2                                | 2026-03-05 |                                              |
 *  | 5     | guest AR-CM-G1@Example.com        | 2026-03-11 |                                              |
 *  | 6     | guest ar-cm-g1@example.com        | 2026-03-12 | same person, different casing                |
 *  | 7     | guest ar-cm-g2@example.com        | 2026-03-13 |                                              |
 *  | 8     | C3 ar-cm-g2@example.com (account) | 2026-03-15 | account created 2026-03-14, same email as 7  |
 * Registrations: C1 2026-01-01, C2 2026-01-02, C3 2026-03-14 (customer_entity.created_at).
 *
 * Expected values (hard-coded in the tests):
 *  | report                                            | value                                              |
 *  | new/returning 2026-03-10..03-10                   | 0 new, 1 returning (order 2, first order in Jan)   |
 *  | new/returning 2026-03-05..03-05                   | 1 new (order 4; canceled order 3 is ignored)       |
 *  | new/returning 2026-03-11..03-12                   | 1 new (5), 1 returning (6)                         |
 *  | C1 over Jan..Mar                                  | 2 orders on 2 stores, 1 unique customer            |
 *  | new/returning 2026-03-13..03-15                   | 2 new (7 guest, 8 registered), 0 returning         |
 *  | total_spent C1 / C2 / g1 / g2                     | 15.00 / 15.00 / 30.00 / 30.00                      |
 *  |   C1: order 1 = 10 - 10 returns + (5 - 5 shipping refunded) = 0; order 2 = 10 + 5 = 15; taxes are 0     |
 *  | avg_spent C1                                      | 7.50 = 15 / 2                                      |
 *  | registrations 2026-01-01..03-31 / 2026-03-14      | 3 / 1                                              |
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-cm-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
    DataFixture(
        Customer::class,
        ['email' => 'ar-cm-c1@example.com', 'created_at' => '2026-01-01 08:00:00', 'addresses' => [[]]],
        'customer1'
    ),
    DataFixture(
        Customer::class,
        ['email' => 'ar-cm-c2@example.com', 'created_at' => '2026-01-02 08:00:00', 'addresses' => [[]]],
        'customer2'
    ),
    DataFixture(
        Customer::class,
        ['email' => 'ar-cm-g2@example.com', 'created_at' => '2026-03-14 08:00:00', 'addresses' => [[]]],
        'customer3'
    ),
    DataFixture(CustomerCart::class, ['customer_id' => '$customer1.id$'], 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(Invoice::class, ['order_id' => '$order1.id$']),
    DataFixture(Creditmemo::class, ['order_id' => '$order1.id$', 'items' => [['qty' => 1, 'product_id' => '$product.id$']]]),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-01-10 12:00:00']),
    DataFixture(CustomerCart::class, ['customer_id' => '$customer1.id$'], 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-03-10 12:00:00']),
    DataFixture(CustomerCart::class, ['customer_id' => '$customer2.id$'], 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
    DataFixture(CancelOrder::class, ['order_id' => '$order3.id$']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-02-01 12:00:00']),
    DataFixture(CustomerCart::class, ['customer_id' => '$customer2.id$'], 'cart4'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart4.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart4.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart4.id$'], 'order4'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order4.id$', 'created_at' => '2026-03-05 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart5'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart5.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart5.id$', 'email' => 'AR-CM-G1@Example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart5.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart5.id$'], 'order5'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order5.id$', 'created_at' => '2026-03-11 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart6'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart6.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart6.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart6.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart6.id$', 'email' => 'ar-cm-g1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart6.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart6.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart6.id$'], 'order6'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order6.id$', 'created_at' => '2026-03-12 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart7'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart7.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart7.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart7.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart7.id$', 'email' => 'ar-cm-g2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart7.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart7.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart7.id$'], 'order7'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order7.id$', 'created_at' => '2026-03-13 12:00:00']),
    DataFixture(CustomerCart::class, ['customer_id' => '$customer3.id$'], 'cart8'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart8.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart8.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart8.id$']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart8.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart8.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart8.id$'], 'order8'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order8.id$', 'created_at' => '2026-03-15 12:00:00']),
]
final class CustomerMetricsTest extends TestCase
{
    private const array NEW_RETURNING = ['orders', 'new_customer_orders', 'returning_customer_orders'];

    private static int $extraStoreId;

    private ReportTestRunner $runner;

    public static function setUpBeforeClass(): void
    {
        self::$extraStoreId = (new StoreProvisioner())->ensureStoreView();
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

    #[Config('general/locale/timezone', 'UTC')]
    #[Config('general/locale/timezone', 'UTC', 'website', 'base')]
    public function testItCountsACustomerSecondOrderAsReturningEvenWhenTheFirstIsOutsideTheRange(): void
    {
        $result = $this->runner->run('sales_orders', self::NEW_RETURNING, [], [], '2026-03-10', '2026-03-10');

        // order 2 only; order 1 (2026-01-10) is outside the range but makes it a returning order
        $totals = $this->runner->totals($result);
        $this->assertEquals(1, $totals['orders']);
        $this->assertEquals(0, $totals['new_customer_orders']);
        $this->assertEquals(1, $totals['returning_customer_orders']);
    }

    #[Config('general/locale/timezone', 'UTC')]
    #[Config('general/locale/timezone', 'UTC', 'website', 'base')]
    public function testItIgnoresCanceledOrdersWhenDecidingAFirstOrder(): void
    {
        $result = $this->runner->run('sales_orders', self::NEW_RETURNING, [], [], '2026-03-05', '2026-03-05');

        // order 4 of C2; the earlier order 3 (2026-02-01) is canceled, so order 4 is new
        $totals = $this->runner->totals($result);
        $this->assertEquals(1, $totals['orders']);
        $this->assertEquals(1, $totals['new_customer_orders']);
        $this->assertEquals(0, $totals['returning_customer_orders']);
    }

    #[Config('general/locale/timezone', 'UTC')]
    #[Config('general/locale/timezone', 'UTC', 'website', 'base')]
    public function testItTreatsGuestEmailsDifferingOnlyByCaseAsOneCustomer(): void
    {
        $storedEmails = $this->orderEmails(['order5', 'order6']);
        // sales_order.customer_email keeps the casing typed at checkout (no normalization)
        $this->assertSame(['AR-CM-G1@Example.com', 'ar-cm-g1@example.com'], $storedEmails);

        $result = $this->runner->run('sales_orders', self::NEW_RETURNING, [], [], '2026-03-11', '2026-03-12');
        // order 5 is the first order of the person, order 6 (other casing) is returning
        $totals = $this->runner->totals($result);
        $this->assertEquals(2, $totals['orders']);
        $this->assertEquals(1, $totals['new_customer_orders']);
        $this->assertEquals(1, $totals['returning_customer_orders']);

        $customers = $this->runner->run('customer_orders', ['orders', 'unique_customers'], [], [], '2026-03-11', '2026-03-12');
        $this->assertEquals(1, $this->runner->totals($customers)['unique_customers']);
    }

    #[Config('general/locale/timezone', 'UTC')]
    #[Config('general/locale/timezone', 'UTC', 'website', 'base')]
    public function testItCountsARegisteredCustomerOrderingOnTwoStoreViewsAsOneUniqueCustomer(): void
    {
        $order = DataFixtureStorageManager::getStorage()->get('order2');
        Bootstrap::getObjectManager()->get(SetOrderStore::class)->apply([
            'order_id' => (int) $order->getId(),
            'store_id' => self::$extraStoreId,
        ]);

        $byStore = $this->runner->run(
            'customer_orders',
            ['orders', 'unique_customers'],
            [['code' => 'store_id', 'granularity' => null]],
            [['field' => 'customer_id', 'operator' => 'eq', 'value' => $this->customerId('customer1')]],
            '2026-01-01',
            '2026-03-31'
        );
        $total = $this->runner->run(
            'customer_orders',
            ['orders', 'unique_customers'],
            [],
            [['field' => 'customer_id', 'operator' => 'eq', 'value' => $this->customerId('customer1')]],
            '2026-01-01',
            '2026-03-31'
        );

        // orders 1 (default store) and 2 (extra store): one row per store, a single unique customer overall
        $this->assertCount(2, $this->runner->rows($byStore));
        $this->assertEquals(2, $this->runner->totals($total)['orders']);
        $this->assertEquals(1, $this->runner->totals($total)['unique_customers']);
    }

    #[Config('general/locale/timezone', 'UTC')]
    #[Config('general/locale/timezone', 'UTC', 'website', 'base')]
    public function testItCountsAGuestWhoLaterRegistersAsNewTwiceAsDocumented(): void
    {
        $result = $this->runner->run('sales_orders', self::NEW_RETURNING, [], [], '2026-03-13', '2026-03-15');

        // order 7 (guest) and order 8 (registered, same email) are both first orders: R08, counted new twice
        $totals = $this->runner->totals($result);
        $this->assertEquals(2, $totals['orders']);
        $this->assertEquals(2, $totals['new_customer_orders']);
        $this->assertEquals(0, $totals['returning_customer_orders']);

        $customers = $this->runner->run('customer_orders', ['unique_customers'], [], [], '2026-03-13', '2026-03-15');
        // the identity by email still sees one person
        $this->assertEquals(1, $this->runner->totals($customers)['unique_customers']);
    }

    #[Config('general/locale/timezone', 'UTC')]
    #[Config('general/locale/timezone', 'UTC', 'website', 'base')]
    public function testItReportsTotalSpentPerCustomerAsLifetimeValueNetOfReturnsPlusShippingAndTaxes(): void
    {
        $result = $this->runner->run(
            'customer_orders',
            ['orders', 'total_spent', 'avg_spent', 'days_since_last_order'],
            [['code' => 'customer', 'granularity' => null]],
            [],
            '2026-01-01',
            '2026-03-31'
        );

        $byCustomer = [];
        foreach ($this->runner->rows($result) as $row) {
            $byCustomer[(string) $row['customer']] = $row;
        }
        ksort($byCustomer);
        $this->assertSame(
            ['ar-cm-c1@example.com', 'ar-cm-c2@example.com', 'ar-cm-g1@example.com', 'ar-cm-g2@example.com'],
            array_keys($byCustomer)
        );
        // C1: order 1 = 10 - 10 + 0 = 0 (shipping refunded by default), order 2 = 15 => 15.00 over 2 orders
        $this->assertEquals(2, $byCustomer['ar-cm-c1@example.com']['orders']);
        $this->assertEqualsWithDelta(15.0, $byCustomer['ar-cm-c1@example.com']['total_spent'], 0.0001);
        $this->assertEqualsWithDelta(7.5, $byCustomer['ar-cm-c1@example.com']['avg_spent'], 0.0001);
        // C2: canceled order 3 is not counted; order 4 = 15
        $this->assertEquals(1, $byCustomer['ar-cm-c2@example.com']['orders']);
        $this->assertEqualsWithDelta(15.0, $byCustomer['ar-cm-c2@example.com']['total_spent'], 0.0001);
        // g1: orders 5 and 6 = 2 * 15; g2: guest order 7 + registered order 8 = 2 * 15
        $this->assertEqualsWithDelta(30.0, $byCustomer['ar-cm-g1@example.com']['total_spent'], 0.0001);
        $this->assertEqualsWithDelta(30.0, $byCustomer['ar-cm-g2@example.com']['total_spent'], 0.0001);

        // computed in SQL from UTC_TIMESTAMP(): expected value derived with plain date arithmetic
        // (calendar days between today in UTC and the pinned date of the last order of g1, 2026-03-12)
        $today = new DateTimeImmutable(gmdate('Y-m-d'), new DateTimeZone('UTC'));
        $lastOrder = new DateTimeImmutable('2026-03-12', new DateTimeZone('UTC'));
        $this->assertEquals(
            (int) $today->diff($lastOrder)->days,
            $byCustomer['ar-cm-g1@example.com']['days_since_last_order']
        );
    }

    #[Config('general/locale/timezone', 'UTC')]
    #[Config('general/locale/timezone', 'UTC', 'website', 'base')]
    public function testItCountsRegistrationsCreatedInTheRange(): void
    {
        $all = $this->runner->run('customers', ['registrations'], [], [], '2026-01-01', '2026-03-31');
        $day = $this->runner->run('customers', ['registrations'], [], [], '2026-03-14', '2026-03-14');

        // C1 (01-01), C2 (01-02), C3 (03-14)
        $this->assertEquals(3, $this->runner->totals($all)['registrations']);
        $this->assertEquals(1, $this->runner->totals($day)['registrations']);
    }

    #[Config('general/locale/timezone', 'UTC')]
    #[Config('general/locale/timezone', 'UTC', 'website', 'base')]
    public function testItRejectsANewVersusReturningRequestWhoseRangeExceedsTwelveMonths(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->runner->run('sales_orders', self::NEW_RETURNING, [], [], '2026-01-01', '2027-02-01');
    }

    /**
     * @param string[] $storageKeys
     * @return string[]
     */
    private function orderEmails(array $storageKeys): array
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $emails = [];
        foreach ($storageKeys as $key) {
            $orderId = (int) DataFixtureStorageManager::getStorage()->get($key)->getId();
            $emails[] = (string) $connection->fetchOne(
                $connection->select()->from($connection->getTableName('sales_order'), 'customer_email')
                    ->where('entity_id = ?', $orderId)
            );
        }

        return $emails;
    }

    private function customerId(string $storageKey): int
    {
        return (int) DataFixtureStorageManager::getStorage()->get($storageKey)->getId();
    }
}
