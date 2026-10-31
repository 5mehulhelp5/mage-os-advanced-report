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
 * Dynamic-price bundles in `sales_items`: discounts, refunds and canceled quantities live on the children and are
 * rolled up to the bundle line. One guest order (2 x ar-sdb-a, created 2026-03-05 12:00 UTC); the item row is turned into a
 * bundle parent (2 bundles, row total 100.00) and child rows are inserted straight into `sales_order_item`.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-sdb-a', 'price' => 50, 'tax_class_id' => 0], 'product'),
    DataFixture(GuestCart::class, as: 'cart'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$product.id$', 'qty' => 2]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'ar-sdb@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart.id$'], 'order'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order.id$', 'created_at' => '2026-03-05 12:00:00']),
]
final class DynamicPriceBundleTest extends TestCase
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
    public function testItReportsTheSummedChildDiscountsOnADynamicPriceBundleLine(): void
    {
        $this->makeBundle(0, []);
        $this->addChild(['base_discount_amount' => -6.0]);
        $this->addChild(['base_discount_amount' => -4.0]);

        $this->assertEqualsWithDelta(10.0, $this->items(['discounts'])['discounts'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsTheSummedChildRefundsAsReturnsOnADynamicPriceBundleLine(): void
    {
        $this->makeBundle(0, []);
        $this->addChild(['base_amount_refunded' => 30.0]);
        $this->addChild(['base_amount_refunded' => 20.0]);

        $this->assertEqualsWithDelta(50.0, $this->items(['returns'])['returns'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsBundleUnitsSoldNetOfCanceledBundles(): void
    {
        $this->makeBundle(0, []);
        $this->addChild(['qty_canceled' => 1.0]);
        $this->addChild(['qty_canceled' => 1.0]);

        $this->assertEqualsWithDelta(1.0, $this->items(['units_sold'])['units_sold'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsDynamicBundleGrossSalesNetOfTheChildrensCanceledShare(): void
    {
        $this->makeBundle(0, []);
        $this->addChild(['base_row_total' => 60.0, 'qty_canceled' => 1.0]);
        $this->addChild(['base_row_total' => 40.0, 'qty_canceled' => 1.0]);

        $this->assertEqualsWithDelta(50.0, $this->items(['gross_sales'])['gross_sales'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItDoesNotCountTheDiscountTaxCompensationOfADynamicBundleTwice(): void
    {
        // the tax collector stores the aggregated compensation on the parent as well
        $this->makeBundle(0, ['base_discount_tax_compensation_amount' => 2.2]);
        $this->addChild(['base_discount_amount' => -6.1, 'base_discount_tax_compensation_amount' => 1.1]);
        $this->addChild(['base_discount_amount' => -6.1, 'base_discount_tax_compensation_amount' => 1.1]);

        $this->assertEqualsWithDelta(10.0, $this->items(['discounts'])['discounts'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItKeepsParentValuesForFixedPriceBundles(): void
    {
        $this->makeBundle(1, ['base_discount_amount' => -10.0, 'qty_canceled' => 1.0, 'base_amount_refunded' => 5.0]);
        $this->addChild(['base_discount_amount' => -99.0, 'base_amount_refunded' => 99.0, 'qty_canceled' => 2.0]);

        $totals = $this->items(['discounts', 'returns', 'units_sold', 'gross_sales']);
        $this->assertEqualsWithDelta(5.0, $totals['discounts'], 0.0001);
        $this->assertEqualsWithDelta(5.0, $totals['returns'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $totals['units_sold'], 0.0001);
        $this->assertEqualsWithDelta(50.0, $totals['gross_sales'], 0.0001);
    }

    #[
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItDoesNotMultiplyBundleRowsByTheNumberOfChildren(): void
    {
        $this->makeBundle(0, []);
        $this->addChild(['base_row_total' => 20.0]);
        $this->addChild(['base_row_total' => 30.0]);
        $this->addChild(['base_row_total' => 50.0]);

        $totals = $this->items(['gross_sales', 'units_sold', 'orders']);
        $this->assertEqualsWithDelta(100.0, $totals['gross_sales'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $totals['units_sold'], 0.0001);
        $this->assertEquals(1, (int) $totals['orders']);
    }

    /**
     * Turns the order item into a bundle parent: 2 bundles, row total 100.00, no discount, no cancellation.
     *
     * @param array<string,float|null> $columns
     */
    private function makeBundle(int $productCalculations, array $columns): void
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $resource->getConnection()->update(
            $resource->getTableName('sales_order_item'),
            array_merge([
                'product_type' => 'bundle',
                'product_options' => json_encode(['product_calculations' => $productCalculations]),
                'qty_ordered' => 2.0,
                'qty_canceled' => 0.0,
                'qty_refunded' => 0.0,
                'base_row_total' => 100.0,
                'base_discount_amount' => 0.0,
            ], $columns),
            ['order_id = ?' => $this->orderId()]
        );
    }

    /**
     * Inserts a simple child of the bundle (2 units ordered, row total 50.00, nothing canceled or refunded).
     *
     * @param array<string,float|null> $columns
     */
    private function addChild(array $columns): void
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $table = $resource->getTableName('sales_order_item');
        $parent = $connection->fetchRow(
            $connection->select()->from($table)->where('order_id = ?', $this->orderId())->where('parent_item_id IS NULL')
        );
        $parentId = $parent['item_id'];
        unset($parent['item_id']);
        $connection->insert($table, array_merge($parent, [
            'parent_item_id' => $parentId,
            'product_type' => 'simple',
            'product_options' => null,
            'qty_ordered' => 2.0,
            'qty_canceled' => 0.0,
            'qty_refunded' => 0.0,
            'base_row_total' => 50.0,
            'base_discount_amount' => 0.0,
            'base_discount_tax_compensation_amount' => null,
            'base_amount_refunded' => null,
        ], $columns));
    }

    private function orderId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(DataFixtureStorageManager::class)
            ->getStorage()->get('order')->getId();
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
