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
use Magento\Customer\Test\Fixture\Customer;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\CustomerCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
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
 * The shipped cart presets on fixture quotes. "Now" is pinned to 2026-03-15 12:00:00 UTC; the abandoned and recent
 * thresholds are the 24 hour defaults. Product ar-cp-a costs 10.00, ar-cp-b 20.00 (no tax), flat rate 5.00 per order. Day buckets depend on the store timezone, pinned to UTC per method (class-level
 * `#[Config]` timezone values do not reach the website scope of the installed sandbox).
 *
 *  | cart | kind                               | updated_at (UTC)    | contents    | notes                       |
 *  | 1    | guest, email, active               | 2026-03-10 05:00:00 | a x1, b x1  | abandoned, value 30         |
 *  | 2    | guest, no email, active            | 2026-03-10 10:00:00 | a x1        | abandoned without email     |
 *  | 3    | guest, email, active               | 2026-03-15 08:00:00 | a x1        | recent, value 10            |
 *  | 4    | guest, email, made inactive        | 2026-03-10 11:00:00 | a x1        | inactive                    |
 *  | 5    | guest, email, order placed         | 2026-03-12 12:00:00 | a x1        | converted, value 15         |
 *  | 6    | guest, no items                    | 2026-03-10 10:00:00 | none        | excluded                    |
 *  | 7    | registered customer, email, active | 2026-03-10 13:00:00 | a x2        | abandoned, value 20         |
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    Config('carriers/flatrate/active', '1'),
    Config('carriers/flatrate/type', 'O'),
    Config('carriers/flatrate/price', '5'),
    Config('payment/checkmo/active', '1'),
    DataFixture(Product::class, ['sku' => 'ar-cp-a', 'price' => 10, 'tax_class_id' => 0], 'a'),
    DataFixture(Product::class, ['sku' => 'ar-cp-b', 'price' => 20, 'tax_class_id' => 0], 'b'),
    DataFixture(Customer::class, ['email' => 'ar-cp-c1@example.com', 'addresses' => [[]]], 'customer'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$a.id$', 'qty' => 1]),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$b.id$', 'qty' => 1]),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-cp-1@example.com']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart1.id$', 'updated_at' => '2026-03-10 05:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$a.id$', 'qty' => 1]),
    DataFixture(
        SetQuoteUpdatedAt::class,
        ['cart_id' => '$cart2.id$', 'updated_at' => '2026-03-10 10:00:00', 'customer_is_guest' => 0]
    ),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$a.id$', 'qty' => 1]),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-cp-3@example.com']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart3.id$', 'updated_at' => '2026-03-15 08:00:00']),
    DataFixture(GuestCart::class, as: 'cart4'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart4.id$', 'product_id' => '$a.id$', 'qty' => 1]),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart4.id$', 'email' => 'ar-cp-4@example.com']),
    DataFixture(
        SetQuoteUpdatedAt::class,
        ['cart_id' => '$cart4.id$', 'updated_at' => '2026-03-10 11:00:00', 'is_active' => 0]
    ),
    DataFixture(GuestCart::class, as: 'cart5'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart5.id$', 'product_id' => '$a.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart5.id$', 'email' => 'ar-cp-5@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart5.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart5.id$'], 'order5'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order5.id$', 'created_at' => '2026-03-12 12:00:00']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart5.id$', 'updated_at' => '2026-03-12 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart6'),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart6.id$', 'updated_at' => '2026-03-10 10:00:00']),
    DataFixture(CustomerCart::class, ['customer_id' => '$customer.id$'], 'cart7'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart7.id$', 'product_id' => '$a.id$', 'qty' => 2]),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart7.id$', 'updated_at' => '2026-03-10 13:00:00']),
]
final class CartPresetsTest extends TestCase
{
    private const string PINNED_NOW = '2026-03-15 12:00:00';

    private const array CART_PRESETS = [
        'recent_carts',
        'recent_cart_items',
        'abandoned_carts_trend',
        'abandoned_carts_list',
        'top_abandoned_products',
        'products_added_to_cart',
        'cart_conversion',
    ];

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

    public function testItRegistersEveryCartPresetInTheCartsAreaWithoutValidationErrors(): void
    {
        $registry = Bootstrap::getObjectManager()->get(DefinitionRegistry::class);

        $codes = array_keys($registry->getPresets('carts'));
        sort($codes);
        $expected = self::CART_PRESETS;
        sort($expected);

        $this->assertSame($expected, $codes);
        foreach (self::CART_PRESETS as $code) {
            $this->assertSame('mysql', $registry->getPreset($code)->sources[0]);
        }
    }

    public function testItListsOnlyCartsUpdatedWithinTheRecentCartHoursInRecentCarts(): void
    {
        $rows = $this->runner->rows($this->runner->runPreset('recent_carts', '2026-03-09', '2026-03-15'));

        $this->assertCount(1, $rows);
        $this->assertSame('ar-cp-3@example.com', $rows[0]['customer_email']);
        $this->assertEqualsWithDelta(10.0, (float) $rows[0]['cart_value'], 0.0001);
        $this->assertEqualsWithDelta(1.0, (float) $rows[0]['items_in_carts'], 0.0001);
    }

    #[Config('general/locale/timezone', 'UTC')]
    #[Config('general/locale/timezone', 'UTC', 'website', 'base')]
    public function testItListsAbandonedCartsWithTheirCustomerEmailSortedByValue(): void
    {
        $rows = $this->runner->rows($this->runner->runPreset('abandoned_carts_list', '2026-03-01', '2026-03-31'));

        $this->assertCount(2, $rows);
        $this->assertSame('ar-cp-1@example.com', $rows[0]['customer_email']);
        $this->assertEqualsWithDelta(30.0, (float) $rows[0]['abandoned_value'], 0.0001);
        $this->assertSame('ar-cp-c1@example.com', $rows[1]['customer_email']);
        $this->assertEqualsWithDelta(20.0, (float) $rows[1]['abandoned_value'], 0.0001);
        $this->assertSame('2026-03-10', (string) $rows[0]['activity_date']);
    }

    public function testItRanksProductsLeftInAbandonedCartsByAbandonedValue(): void
    {
        $rows = $this->runner->rows($this->runner->runPreset('top_abandoned_products', '2026-03-01', '2026-03-31'));

        $this->assertCount(2, $rows);
        $this->assertSame('ar-cp-a', $rows[0]['parent_sku']);
        $this->assertEqualsWithDelta(3.0, (float) $rows[0]['abandoned_units'], 0.0001);
        $this->assertEqualsWithDelta(30.0, (float) $rows[0]['abandoned_items_value'], 0.0001);
        $this->assertEquals(2, $rows[0]['abandoned_carts']);
        $this->assertSame('ar-cp-b', $rows[1]['parent_sku']);
        $this->assertEqualsWithDelta(20.0, (float) $rows[1]['abandoned_items_value'], 0.0001);
        $this->assertEquals(1, $rows[1]['abandoned_carts']);
    }

    #[Config('general/locale/timezone', 'UTC')]
    #[Config('general/locale/timezone', 'UTC', 'website', 'base')]
    public function testItShowsAbandonedAndConvertedCartsPerDayComparedWithThePreviousPeriod(): void
    {
        $result = $this->runner->runPreset('abandoned_carts_trend', '2026-03-01', '2026-03-31');

        $byDay = [];
        foreach ($this->runner->rows($result) as $row) {
            $byDay[substr((string) $row['activity_date'], 0, 10)] = $row;
        }
        $this->assertEquals(2, $byDay['2026-03-10']['abandoned_carts']);
        $this->assertEqualsWithDelta(50.0, (float) $byDay['2026-03-10']['abandoned_value'], 0.0001);
        $this->assertEquals(1, $byDay['2026-03-12']['converted_carts']);
        $this->assertEquals(0, $byDay['2026-03-12']['abandoned_carts']);
        $this->assertNotNull($result->getComparisonRange());
        $this->assertArrayHasKey('comparison', $result->getTotals());
    }

    public function testItBreaksCartsDownByCartStateInTheCartConversionPreset(): void
    {
        $result = $this->runner->runPreset('cart_conversion', '2026-03-01', '2026-03-31');

        $byState = [];
        foreach ($result->getRows() as $row) {
            $byState[$row['dims']['cart_state']] = $row['metrics'];
        }
        ksort($byState);

        $this->assertSame(
            ['Abandoned', 'Abandoned without email', 'Active', 'Converted', 'Inactive'],
            array_keys($byState)
        );
        $this->assertEquals(2, $byState['Abandoned']['carts']);
        $this->assertEquals(2, $byState['Abandoned']['abandoned_carts']);
        $this->assertEquals(1, $byState['Converted']['carts']);
        $this->assertEquals(1, $byState['Converted']['converted_carts']);
        $this->assertEquals(0, $byState['Active']['converted_carts']);
    }

    public function testItListsProductsAddedToCartWithTheirConversionRate(): void
    {
        $rows = $this->runner->rows($this->runner->runPreset('products_added_to_cart', '2026-03-01', '2026-03-31'));

        $this->assertCount(2, $rows);
        $this->assertSame('ar-cp-a', $rows[0]['parent_sku']);
        $this->assertEqualsWithDelta(7.0, (float) $rows[0]['units_in_carts'], 0.0001);
        $this->assertEquals(6, $rows[0]['carts']);
        $this->assertEqualsWithDelta(1.0, (float) $rows[0]['converted_units'], 0.0001);
        $this->assertEqualsWithDelta(1 / 7, (float) $rows[0]['item_conversion_rate'], 0.0001);
        $this->assertSame('ar-cp-b', $rows[1]['parent_sku']);
        $this->assertEqualsWithDelta(0.0, (float) $rows[1]['item_conversion_rate'], 0.0001);
    }
}
