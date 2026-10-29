<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Carts;

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
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Query\Clock;
use MageOS\AdvancedReport\Model\Query\ClockInterface;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetQuoteUpdatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\FixedClock;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Cart metrics on fixture quotes. "Now" is pinned to 2026-03-15 12:00:00 UTC; the default abandoned and recent
 * thresholds are 24 hours, i.e. 2026-03-14 12:00:00. Product ar-ct-a costs 10.00 (no tax), flat rate 5.00 per order.
 *
 *  | cart | kind                                  | updated_at (UTC)    | notes                                      |
 *  | 1    | guest, email, active                  | 2026-03-10 05:00:00 | abandoned, value 10                        |
 *  | 2    | guest, no email, active               | 2026-03-10 10:00:00 | customer_is_guest forced to 0, unrecoverable |
 *  | 3    | guest, email, active                  | 2026-03-15 08:00:00 | recent                                     |
 *  | 4    | guest, email, made inactive           | 2026-03-10 11:00:00 | inactive                                   |
 *  | 5    | guest, email, order placed            | 2026-03-12 12:00:00 | converted, value 15 (10 + 5 shipping)      |
 *  | 6    | guest, no items                       | 2026-03-10 10:00:00 | excluded (empty quote)                     |
 *  | 7    | registered customer, email, active    | 2026-03-10 13:00:00 | abandoned, value 10                        |
 *
 * Expected values (hard-coded in the tests), range 2026-03-01..2026-03-31:
 *  | metric                        | value                                          |
 *  | carts                         | 6 (cart 6 excluded)                            |
 *  | cart_value                    | 65 = 5 * 10 + 15                               |
 *  | abandoned_carts / value       | 2 (carts 1, 7) / 20                            |
 *  | recent_carts                  | 1 (cart 3)                                     |
 *  | converted_carts / value       | 1 (cart 5) / 15                                |
 *  | abandonment_rate              | 2 / 3                                          |
 *  | conversion_rate               | 1 / 6                                          |
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    Config('carriers/flatrate/active', '1'),
    Config('carriers/flatrate/type', 'O'),
    Config('carriers/flatrate/price', '5'),
    Config('payment/checkmo/active', '1'),
    Config('general/locale/timezone', 'UTC'),
    Config('general/locale/timezone', 'UTC', 'website', 'base'),
    DataFixture(Product::class, ['sku' => 'ar-ct-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
    DataFixture(Customer::class, ['email' => 'ar-ct-c1@example.com', 'addresses' => [[]]], 'customer'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-ct-1@example.com']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart1.id$', 'updated_at' => '2026-03-10 05:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(
        SetQuoteUpdatedAt::class,
        ['cart_id' => '$cart2.id$', 'updated_at' => '2026-03-10 10:00:00', 'customer_is_guest' => 0]
    ),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-ct-3@example.com']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart3.id$', 'updated_at' => '2026-03-15 08:00:00']),
    DataFixture(GuestCart::class, as: 'cart4'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart4.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart4.id$', 'email' => 'ar-ct-4@example.com']),
    DataFixture(
        SetQuoteUpdatedAt::class,
        ['cart_id' => '$cart4.id$', 'updated_at' => '2026-03-10 11:00:00', 'is_active' => 0]
    ),
    DataFixture(GuestCart::class, as: 'cart5'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart5.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart5.id$', 'email' => 'ar-ct-5@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart5.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart5.id$'], 'order5'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order5.id$', 'created_at' => '2026-03-12 12:00:00']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart5.id$', 'updated_at' => '2026-03-12 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart6'),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart6.id$', 'updated_at' => '2026-03-10 10:00:00']),
    DataFixture(CustomerCart::class, ['customer_id' => '$customer.id$'], 'cart7'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart7.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart7.id$', 'updated_at' => '2026-03-10 13:00:00']),
]
final class CartMetricsTest extends TestCase
{
    private const string PINNED_NOW = '2026-03-15 12:00:00';

    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        $clock = new FixedClock(self::PINNED_NOW);
        $objectManager = Bootstrap::getObjectManager();
        // Before the runner exists: the cart providers are built when the runner is resolved.
        $objectManager->addSharedInstance($clock, Clock::class);
        $objectManager->addSharedInstance($clock, ClockInterface::class);
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    public function testItLoadsTheCartsDatasetWithoutValidationErrors(): void
    {
        $dataset = Bootstrap::getObjectManager()->get(DefinitionRegistry::class)->getDataset('carts');

        $this->assertSame('carts', $dataset->code);
        $this->assertTrue($dataset->hasField('is_abandoned'));
    }

    public function testItCountsCartsWithItemsAndExcludesEmptyQuotes(): void
    {
        $totals = $this->march(['carts', 'cart_value', 'avg_cart_value', 'items_in_carts']);

        $this->assertEquals(6, $totals['carts']);
        $this->assertEqualsWithDelta(65.0, (float) $totals['cart_value'], 0.0001);
        $this->assertEqualsWithDelta(65 / 6, (float) $totals['avg_cart_value'], 0.0001);
        $this->assertEqualsWithDelta(6.0, (float) $totals['items_in_carts'], 0.0001);
    }

    public function testItCountsAnActiveCartWithEmailIdleBeyondTheAbandonedThresholdAsAbandoned(): void
    {
        $totals = $this->march(['abandoned_carts', 'abandoned_value']);

        $this->assertEquals(2, $totals['abandoned_carts']);
        $this->assertEqualsWithDelta(20.0, (float) $totals['abandoned_value'], 0.0001);
    }

    public function testItDoesNotCountAnIdleActiveCartWithoutEmailAsAbandoned(): void
    {
        $state = $this->cartStates();

        $this->assertSame(1, $state['unrecoverable']);
        $this->assertSame(2, $state['abandoned']);
    }

    public function testItCountsAGuestCartWithEmailAsAbandoned(): void
    {
        $rows = $this->rowsKeyed(
            $this->runner->run(
                'carts',
                ['abandoned_carts'],
                [['code' => 'customer_type', 'granularity' => null]],
                [],
                '2026-03-01',
                '2026-03-31'
            ),
            'customer_type'
        );

        // cart 1 is the guest cart with email; cart 7 the registered one
        $this->assertEquals(1, $rows['1']['abandoned_carts']);
        $this->assertEquals(1, $rows['0']['abandoned_carts']);
    }

    public function testItLabelsAGuestCartWithoutCustomerIdAsGuestEvenWhenCustomerIsGuestIsZero(): void
    {
        $result = $this->runner->run(
            'carts',
            ['carts'],
            [['code' => 'customer_type', 'granularity' => null]],
            [],
            '2026-03-01',
            '2026-03-31'
        );

        $labels = [];
        $counts = [];
        foreach ($result->getRows() as $row) {
            $labels[(string) $row['dims_raw']['customer_type']] = $row['dims']['customer_type'];
            $counts[(string) $row['dims_raw']['customer_type']] = (int) $row['metrics']['carts'];
        }
        ksort($labels);
        ksort($counts);
        // cart 2 has customer_is_guest = 0 but no customer id: still a guest
        $this->assertSame([0 => 'Registered customer', 1 => 'Guest'], $labels);
        $this->assertSame([0 => 1, 1 => 5], $counts);
    }

    public function testItDoesNotCountACartAsAbandonedWhenOnlyIsActiveChangesAfterThePinnedUpdatedAt(): void
    {
        $cartId = (int) DataFixtureStorageManager::getStorage()->get('cart4')->getId();
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($connection->getTableName('quote'), ['updated_at', 'is_active'])
                ->where('entity_id = ?', $cartId)
        );

        // fixture ordering guard: the stored value is the pinned one, not the real time
        $this->assertSame('2026-03-10 11:00:00', $row['updated_at']);
        $this->assertSame('0', (string) $row['is_active']);
        $this->assertEquals(2, $this->march(['abandoned_carts'])['abandoned_carts']);
    }

    #[Config('mageos_advancedreport/carts/abandoned_after_hours', '120')]
    public function testItFollowsAChangedAbandonedAfterHoursSetting(): void
    {
        // threshold 2026-03-10 12:00:00: only cart 1 (05:00) is older
        $this->assertEquals(1, $this->march(['abandoned_carts'])['abandoned_carts']);
    }

    public function testItCountsCartsUpdatedWithinTheRecentCartHoursAsRecent(): void
    {
        $this->assertEquals(1, $this->march(['recent_carts'])['recent_carts']);
    }

    public function testItCountsCartsWithAnOrderAsConvertedAndSumsTheirValue(): void
    {
        $totals = $this->march(['converted_carts', 'converted_value', 'conversion_rate']);

        $this->assertEquals(1, $totals['converted_carts']);
        $this->assertEqualsWithDelta(15.0, (float) $totals['converted_value'], 0.0001);
        $this->assertEqualsWithDelta(1 / 6, (float) $totals['conversion_rate'], 0.0001);
    }

    public function testItComputesTheAbandonmentRateFromAbandonedAndConvertedCarts(): void
    {
        $this->assertEqualsWithDelta(2 / 3, (float) $this->march(['abandonment_rate'])['abandonment_rate'], 0.0001);
    }

    public function testItGroupsCartsByLabelledCartState(): void
    {
        $result = $this->runner->run(
            'carts',
            ['carts'],
            [['code' => 'cart_state', 'granularity' => null]],
            [],
            '2026-03-01',
            '2026-03-31'
        );

        $labelled = [];
        foreach ($result->getRows() as $row) {
            $labelled[$row['dims']['cart_state']] = (int) $row['metrics']['carts'];
        }
        ksort($labelled);

        $this->assertSame(
            ['Abandoned' => 2, 'Abandoned without email' => 1, 'Active' => 1, 'Converted' => 1, 'Inactive' => 1],
            $labelled
        );
    }

    #[Config('general/locale/timezone', 'America/Los_Angeles')]
    #[Config('general/locale/timezone', 'America/Los_Angeles', 'website', 'base')]
    public function testItFiltersCartsByLastActivityDateInTheStoreTimezone(): void
    {
        // cart 1 (2026-03-10 05:00 UTC) is 2026-03-09 22:00 in Los Angeles (PDT, UTC-7)
        $totals = $this->runner->totals($this->runner->run('carts', ['carts'], [], [], '2026-03-09', '2026-03-09'));

        $this->assertEquals(1, $totals['carts']);
    }

    /**
     * @param string[] $metrics
     * @return array<string,mixed>
     */
    private function march(array $metrics): array
    {
        return $this->runner->totals($this->runner->run('carts', $metrics, [], [], '2026-03-01', '2026-03-31'));
    }

    /**
     * @return array<string,int>
     */
    private function cartStates(): array
    {
        $rows = $this->rowsKeyed(
            $this->runner->run(
                'carts',
                ['carts'],
                [['code' => 'cart_state', 'granularity' => null]],
                [],
                '2026-03-01',
                '2026-03-31'
            ),
            'cart_state'
        );

        return array_map(static fn (array $row): int => (int) $row['carts'], $rows);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function rowsKeyed(\MageOS\AdvancedReport\Api\Data\ReportResultInterface $result, string $dimension): array
    {
        $keyed = [];
        foreach ($this->runner->rows($result) as $row) {
            $keyed[(string) $row[$dimension]] = $row;
        }

        return $keyed;
    }
}
