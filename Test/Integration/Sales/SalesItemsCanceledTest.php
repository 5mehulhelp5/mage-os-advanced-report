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
 * `sales_items` gross sales and discounts are net of the canceled quantity and of the item discount tax
 * compensation. One guest order (2 x ar-sic-a, created 2026-03-05 12:00 UTC); the item columns are transcribed
 * straight into `sales_order_item` (row total 100.00, unit cost 15.00).
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-sic-a', 'price' => 50, 'tax_class_id' => 0], 'product'),
    DataFixture(GuestCart::class, as: 'cart'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$', 'qty' => 2]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'ar-sic@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart.id$'], 'order'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order.id$', 'created_at' => '2026-03-05 12:00:00']),
]
final class SalesItemsCanceledTest extends TestCase
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
    public function testItReportsItemGrossSalesNetOfTheCanceledQuantity(): void
    {
        $this->setItem(['base_discount_amount' => 0.0, 'qty_canceled' => 1.0]);

        $this->assertEqualsWithDelta(50.0, $this->items(['gross_sales'])['gross_sales'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsItemDiscountsNetOfTheCanceledQuantity(): void
    {
        $this->setItem(['base_discount_amount' => 10.0, 'qty_canceled' => 1.0]);

        $this->assertEqualsWithDelta(5.0, $this->items(['discounts'])['discounts'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsItemGrossMarginOf15AfterAPartialCancellation(): void
    {
        $this->setItem(['base_discount_amount' => 10.0, 'qty_canceled' => 1.0, 'base_cost' => 30.0]);

        // net sales 50 - 5 = 45, cost 30 * 1 kept unit
        $this->assertEqualsWithDelta(
            15.0,
            $this->items(['gross_margin'])['gross_margin'],
            0.0001
        );
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsItemDiscountsNetOfDiscountTaxCompensationOnTaxInclusiveOrders(): void
    {
        $this->setItem(['base_discount_amount' => -12.2, 'base_discount_tax_compensation_amount' => 2.2]);

        $this->assertEqualsWithDelta(10.0, $this->items(['discounts'])['discounts'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItSumsItemNetSalesToTheOrderNetSalesForAPartiallyCanceledTaxInclusiveOrderWithoutShippingDiscount(): void
    {
        $this->setItem([
            'base_discount_amount' => -12.2,
            'base_discount_tax_compensation_amount' => 2.2,
            'qty_canceled' => 1.0,
        ]);
        $this->setOrder([
            'base_subtotal' => 100.0,
            'base_subtotal_canceled' => 50.0,
            'base_discount_amount' => -12.2,
            'base_discount_canceled' => -6.1,
            'base_discount_tax_compensation_amount' => 2.2,
            'base_discount_tax_compensation_invoiced' => 1.1,
            'base_shipping_discount_tax_compensation_amnt' => 0.0,
        ]);

        $order = $this->runner->totals($this->runner->run('sales_orders', ['net_sales'], [], [], self::FROM, self::TO));
        $this->assertEqualsWithDelta(45.0, $order['net_sales'], 0.0001);
        $this->assertEqualsWithDelta($order['net_sales'], $this->items(['net_sales'])['net_sales'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsZeroItemNetSalesAfterAFullRefundOfATaxInclusiveDiscountedItem(): void
    {
        $this->setItem([
            'base_discount_amount' => -12.2,
            'base_discount_tax_compensation_amount' => 2.2,
            'base_amount_refunded' => 100.0,
            'base_discount_refunded' => -12.2,
            'base_discount_tax_compensation_refunded' => 2.2,
        ]);

        $this->assertEqualsWithDelta(0.0, $this->items(['net_sales'])['net_sales'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsZeroGrossSalesForAFullyCanceledItemWithZeroOrderedQuantityGuard(): void
    {
        $this->setItem([
            'base_row_total' => 0.0,
            'base_discount_amount' => 0.0,
            'qty_ordered' => 0.0,
            'qty_canceled' => 0.0,
        ]);

        $totals = $this->items(['gross_sales', 'discounts']);
        $this->assertEqualsWithDelta(0.0, $totals['gross_sales'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $totals['discounts'], 0.0001);
    }

    /**
     * @param array<string,float|null> $columns
     */
    private function setItem(array $columns): void
    {
        $this->update('sales_order_item', 'order_id', array_merge([
            'qty_ordered' => 2.0,
            'qty_canceled' => 0.0,
            'qty_refunded' => 0.0,
            'base_row_total' => 100.0,
            'base_cost' => 15.0,
            'base_discount_amount' => 0.0,
            'base_discount_tax_compensation_amount' => null,
            'base_discount_tax_compensation_refunded' => null,
            'base_amount_refunded' => null,
            'base_discount_refunded' => null,
        ], $columns));
    }

    /**
     * @param array<string,float|null> $columns
     */
    private function setOrder(array $columns): void
    {
        $this->update('sales_order', 'entity_id', array_merge([
            'base_tax_amount' => 0.0,
            'base_shipping_amount' => 0.0,
            'base_discount_canceled' => null,
            'base_subtotal_canceled' => null,
        ], $columns));
    }

    /**
     * @param array<string,float|null> $columns
     */
    private function update(string $table, string $orderColumn, array $columns): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $orderId = (int) $objectManager->get(DataFixtureStorageManager::class)->getStorage()->get('order')->getId();
        $resource = $objectManager->get(ResourceConnection::class);
        $resource->getConnection()->update(
            $resource->getTableName($table),
            $columns,
            [$orderColumn . ' = ?' => $orderId]
        );
    }

    /**
     * @param string[] $metrics
     * @return array<string,mixed>
     */
    private function items(array $metrics): array
    {
        return $this->runner->totals($this->runner->run('sales_items', $metrics, [], [], self::FROM, self::TO));
    }
}
