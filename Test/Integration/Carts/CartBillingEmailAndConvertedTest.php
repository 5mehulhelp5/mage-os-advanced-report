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
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\Query\Clock;
use MageOS\AdvancedReport\Model\Query\ClockInterface;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetQuoteUpdatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\FixedClock;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Guest billing email fallback and converted precedence. "Now" is pinned to 2026-03-15 12:00:00 UTC, thresholds 24h.
 * Product ar-cb-a costs 10.00 (no tax).
 *
 *  | cart | kind                                                         | updated_at (UTC)    | expected   |
 *  | 1    | guest, email only on the billing address, idle               | 2026-03-10 05:00:00 | abandoned  |
 *  | 2    | guest, no email anywhere, idle                               | 2026-03-10 10:00:00 | unrecoverable |
 *  | 3    | guest, quote email, billing address with a blank email, idle | 2026-03-10 06:00:00 | abandoned  |
 *  | 4    | order placed, quote reactivated, idle                        | 2026-03-12 12:00:00 | converted  |
 *  | 5    | order placed, quote reactivated, updated recently            | 2026-03-15 08:00:00 | converted  |
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
    DataFixture(Product::class, ['sku' => 'ar-cb-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(
        SetBillingAddress::class,
        ['cart_id' => '$cart1.id$', 'address' => ['email' => 'ar-cb-1@example.com']]
    ),
    DataFixture(
        SetQuoteUpdatedAt::class,
        ['cart_id' => '$cart1.id$', 'updated_at' => '2026-03-10 05:00:00', 'customer_email' => null]
    ),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(
        SetQuoteUpdatedAt::class,
        ['cart_id' => '$cart2.id$', 'updated_at' => '2026-03-10 10:00:00', 'customer_email' => null]
    ),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$', 'address' => ['email' => '']]),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-cb-3@example.com']),
    DataFixture(SetQuoteUpdatedAt::class, ['cart_id' => '$cart3.id$', 'updated_at' => '2026-03-10 06:00:00']),
    DataFixture(GuestCart::class, as: 'cart4'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart4.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart4.id$', 'email' => 'ar-cb-4@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart4.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart4.id$'], 'order4'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order4.id$', 'created_at' => '2026-03-12 12:00:00']),
    DataFixture(
        SetQuoteUpdatedAt::class,
        ['cart_id' => '$cart4.id$', 'updated_at' => '2026-03-12 12:00:00', 'is_active' => 1]
    ),
    DataFixture(GuestCart::class, as: 'cart5'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart5.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart5.id$', 'email' => 'ar-cb-5@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart5.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart5.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart5.id$'], 'order5'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order5.id$', 'created_at' => '2026-03-12 12:00:00']),
    DataFixture(
        SetQuoteUpdatedAt::class,
        ['cart_id' => '$cart5.id$', 'updated_at' => '2026-03-15 08:00:00', 'is_active' => 1]
    ),
]
final class CartBillingEmailAndConvertedTest extends TestCase
{
    private const string PINNED_NOW = '2026-03-15 12:00:00';

    private ReportTestRunner $runner;

    protected function setUp(): void
    {
        $clock = new FixedClock(self::PINNED_NOW);
        $objectManager = Bootstrap::getObjectManager();
        $objectManager->addSharedInstance($clock, Clock::class);
        $objectManager->addSharedInstance($clock, ClockInterface::class);
        $this->runner = new ReportTestRunner();
        $this->runner->login();
    }

    protected function tearDown(): void
    {
        $this->runner->logout();
    }

    public function testItCountsAnIdleGuestCartWithOnlyABillingAddressEmailAsAbandoned(): void
    {
        // carts 1 (billing email only) and 3 (quote email)
        $this->assertEquals(2, $this->totals('carts', ['abandoned_carts'])['abandoned_carts']);
    }

    public function testItCountsAnIdleGuestCartWithoutAnyEmailAsAbandonedWithoutEmail(): void
    {
        $this->assertSame(1, $this->cartStates()['Abandoned without email']);
    }

    public function testItPrefersTheQuoteEmailOverTheBillingAddressEmail(): void
    {
        // cart 3 has a quote email and a blank billing email: still abandoned, not unrecoverable
        $states = $this->cartStates();

        $this->assertSame(2, $states['Abandoned']);
        $this->assertSame(1, $states['Abandoned without email']);
    }

    public function testItCountsAReactivatedQuoteThatHasAnOrderAsConvertedAndNotAbandoned(): void
    {
        $totals = $this->totals('carts', ['abandoned_carts', 'converted_carts']);

        // cart 4 is reactivated and idle past the threshold, yet converted only
        $this->assertEquals(2, $totals['converted_carts']);
        $this->assertEquals(2, $totals['abandoned_carts']);
    }

    public function testItReportsCartStateConvertedForAReactivatedQuoteWithAnOrder(): void
    {
        $states = $this->cartStates();

        $this->assertSame(2, $states['Converted']);
        $this->assertArrayNotHasKey('Active', $states);
    }

    public function testItDoesNotCountAReactivatedQuoteWithAnOrderAsARecentCart(): void
    {
        // cart 5 is reactivated and updated within the recent window
        $this->assertEquals(0, $this->totals('carts', ['recent_carts'])['recent_carts']);
    }

    public function testItAppliesTheBillingEmailFallbackToCartItemsAbandoned(): void
    {
        // one unit in each of carts 1 and 3
        $this->assertEqualsWithDelta(
            2.0,
            (float) $this->totals('cart_items', ['abandoned_units'])['abandoned_units'],
            0.0001
        );
    }

    public function testItShowsTheBillingAddressEmailInCartsCustomerEmailForGuestCartsWithoutAQuoteEmail(): void
    {
        $result = $this->runner->run(
            'carts',
            ['carts'],
            [['code' => 'customer_email', 'granularity' => null]],
            [],
            '2026-03-01',
            '2026-03-31'
        );
        $emails = [];
        foreach ($result->getRows() as $row) {
            $emails[] = $row['dims']['customer_email'];
        }

        // cart 1 has the email on the billing address only, cart 3 on the quote
        $this->assertContains('ar-cb-1@example.com', $emails);
        $this->assertContains('ar-cb-3@example.com', $emails);
    }

    /**
     * @param string[] $metrics
     * @return array<string,mixed>
     */
    private function totals(string $dataset, array $metrics): array
    {
        return $this->runner->totals($this->runner->run($dataset, $metrics, [], [], '2026-03-01', '2026-03-31'));
    }

    /**
     * @return array<string,int>
     */
    private function cartStates(): array
    {
        $result = $this->runner->run(
            'carts',
            ['carts'],
            [['code' => 'cart_state', 'granularity' => null]],
            [],
            '2026-03-01',
            '2026-03-31'
        );
        $states = [];
        foreach ($result->getRows() as $row) {
            $states[$row['dims']['cart_state']] = (int) $row['metrics']['carts'];
        }

        return $states;
    }
}
