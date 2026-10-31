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
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Discounts are reported net of discount tax compensation (tax inclusive catalog prices).
 *
 * One guest order created 2026-03-05 12:00 UTC; the tests transcribe the amounts of the review example straight
 * into the `sales_order` columns (122 incl. 22% VAT, 10% coupon): subtotal 100.00, discount -12.20, tax 19.80,
 * discount tax compensation 2.20, grand total 109.80.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-dtc-a', 'price' => 100, 'tax_class_id' => 0], 'product'),
    DataFixture(GuestCart::class, as: 'cart'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'ar-dtc@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart.id$'], 'order'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order.id$', 'created_at' => '2026-03-05 12:00:00']),
]
final class DiscountTaxCompensationTest extends TestCase
{
    private const string FROM = '2026-03-01';
    private const string TO = '2026-03-31';

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
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsDiscountsNetOfDiscountTaxCompensationOnTaxInclusiveOrders(): void
    {
        $this->setOrderAmounts([
            'base_discount_amount' => -12.2,
            'base_discount_tax_compensation_amount' => 2.2,
        ]);

        $this->assertEqualsWithDelta(10.0, $this->totals(['discounts'])['discounts'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsNetSalesOf90ForTheTaxInclusiveCouponExample(): void
    {
        $this->setOrderAmounts([
            'base_discount_amount' => -12.2,
            'base_discount_tax_compensation_amount' => 2.2,
        ]);

        $this->assertEqualsWithDelta(90.0, $this->totals(['net_sales'])['net_sales'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsTotalSalesEqualToTheOrderGrandTotalOf10980(): void
    {
        $this->setOrderAmounts([
            'base_discount_amount' => -12.2,
            'base_discount_tax_compensation_amount' => 2.2,
        ]);

        $this->assertEqualsWithDelta(109.8, $this->totals(['total_sales'])['total_sales'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItIncludesShippingDiscountTaxCompensationInDiscounts(): void
    {
        // 2.20 on the items and 1.00 on the shipping, discount 13.20 in total
        $this->setOrderAmounts([
            'base_discount_amount' => -13.2,
            'base_discount_tax_compensation_amount' => 2.2,
            'base_shipping_discount_tax_compensation_amnt' => 1.0,
        ]);

        $this->assertEqualsWithDelta(10.0, $this->totals(['discounts'])['discounts'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItClosesTheGapWhenDiscountIsAppliedOnPricesExcludingTax(): void
    {
        $this->setOrderAmounts([
            'base_discount_amount' => -11.8,
            'base_discount_tax_compensation_amount' => 1.8,
        ]);

        $totals = $this->totals(['discounts', 'net_sales', 'total_sales']);
        $this->assertEqualsWithDelta(10.0, $totals['discounts'], 0.0001);
        $this->assertEqualsWithDelta(90.0, $totals['net_sales'], 0.0001);
        $this->assertEqualsWithDelta(109.8, $totals['total_sales'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsZeroNetAndTotalSalesAfterAFullRefundOfATaxInclusiveDiscountedOrder(): void
    {
        $this->setOrderAmounts([
            'base_discount_amount' => -12.2,
            'base_discount_tax_compensation_amount' => 2.2,
            'base_subtotal_refunded' => 100.0,
            'base_discount_refunded' => -12.2,
            'base_discount_tax_compensation_refunded' => 2.2,
            'base_tax_refunded' => 19.8,
            'base_shipping_refunded' => 0.0,
        ]);

        $totals = $this->totals(['net_sales', 'total_sales']);
        $this->assertEqualsWithDelta(0.0, $totals['net_sales'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $totals['total_sales'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItAppliesTheSameDiscountDefinitionToCustomerOrders(): void
    {
        $this->setOrderAmounts([
            'base_discount_amount' => -12.2,
            'base_discount_tax_compensation_amount' => 2.2,
        ]);

        $result = $this->runner->run('customer_orders', ['total_spent'], [], [], self::FROM, self::TO);

        // 100 - 10 + shipping 0 + taxes 19.80
        $this->assertEqualsWithDelta(109.8, $this->runner->totals($result)['total_spent'], 0.0001);
    }

    /**
     * Overwrites the order columns with the transcribed amounts; subtotal 100, tax 19.80, no shipping and no
     * cancellation are the constants of the scenario.
     *
     * @param array<string,float> $columns
     */
    private function setOrderAmounts(array $columns): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $orderId = (int) $objectManager->get(DataFixtureStorageManager::class)->getStorage()->get('order')->getId();
        $resource = $objectManager->get(ResourceConnection::class);
        $resource->getConnection()->update(
            $resource->getTableName('sales_order'),
            array_merge([
                'base_subtotal' => 100.0,
                'base_tax_amount' => 19.8,
                'base_shipping_amount' => 0.0,
                'base_subtotal_canceled' => null,
                'base_discount_canceled' => null,
                'base_tax_canceled' => null,
                'base_shipping_canceled' => null,
                'base_grand_total' => 109.8,
            ], $columns),
            ['entity_id = ?' => $orderId]
        );
    }

    /**
     * @param string[] $metrics
     * @return array<string,mixed>
     */
    private function totals(array $metrics): array
    {
        return $this->runner->totals($this->runner->run('sales_orders', $metrics, [], [], self::FROM, self::TO));
    }
}
