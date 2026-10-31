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
use Magento\Sales\Test\Fixture\Creditmemo;
use Magento\Sales\Test\Fixture\Invoice;
use Magento\Sales\Test\Fixture\Shipment;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetCreditmemoState;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Credit memo count by state: open (1) and canceled (3) memos are not counted, refunded (2) are; shipments all are.
 *
 * Inputs: order 1 (4 x ar-cs-a, invoiced, fully shipped in one shipment, created 2026-04-08 10:00 UTC), four
 * 1-unit memos forced to states open, canceled, refunded, refunded.
 * Expected: credit_memos = 2, shipments = 1.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    Config('carriers/flatrate/active', '1'),
    Config('payment/checkmo/active', '1'),
    Config('mageos_advancedreport/sales/counted_statuses', ''),
    DataFixture(Product::class, ['sku' => 'ar-cs-a', 'price' => 10, 'tax_class_id' => 0], 'productA'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$productA.id$', 'qty' => 4]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-cs-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(Invoice::class, ['order_id' => '$order1.id$']),
    DataFixture(Shipment::class, ['order_id' => '$order1.id$', 'items' => []]),
    DataFixture(Creditmemo::class, ['order_id' => '$order1.id$', 'items' => [['qty' => 1, 'product_id' => '$productA.id$']], 'arguments' => ['shipping_amount' => 0]], 'memo1'),
    DataFixture(SetCreditmemoState::class, ['creditmemo_id' => '$memo1.id$', 'state' => 1]),
    DataFixture(Creditmemo::class, ['order_id' => '$order1.id$', 'items' => [['qty' => 1, 'product_id' => '$productA.id$']], 'arguments' => ['shipping_amount' => 0]], 'memo2'),
    DataFixture(SetCreditmemoState::class, ['creditmemo_id' => '$memo2.id$', 'state' => 3]),
    DataFixture(Creditmemo::class, ['order_id' => '$order1.id$', 'items' => [['qty' => 1, 'product_id' => '$productA.id$']], 'arguments' => ['shipping_amount' => 0]], 'memo3'),
    DataFixture(SetCreditmemoState::class, ['creditmemo_id' => '$memo3.id$', 'state' => 2]),
    DataFixture(Creditmemo::class, ['order_id' => '$order1.id$', 'items' => [['qty' => 1, 'product_id' => '$productA.id$']], 'arguments' => ['shipping_amount' => 0]], 'memo4'),
    DataFixture(SetCreditmemoState::class, ['creditmemo_id' => '$memo4.id$', 'state' => 2]),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-04-08 10:00:00']),
]
final class CreditMemoStatesTest extends TestCase
{
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

    public function testItExcludesOpenCreditMemosFromTheCreditMemoCount(): void
    {
        $row = $this->row();

        // memos: open, canceled, refunded x2; open and canceled are not counted
        $this->assertEquals(2, (int) $row['credit_memos']);
    }

    public function testItExcludesCanceledCreditMemosFromTheCreditMemoCount(): void
    {
        $row = $this->row();

        $this->assertNotEquals(3, (int) $row['credit_memos']);
        $this->assertEquals(2, (int) $row['credit_memos']);
    }

    public function testItKeepsCountingAllShipments(): void
    {
        $this->assertEquals(1, (int) $this->row()['shipments']);
    }

    /**
     * @return array<string,mixed>
     */
    private function row(): array
    {
        $rows = $this->runner->rows($this->runner->run(
            'sales_items',
            ['credit_memos', 'shipments'],
            [['code' => 'sku', 'granularity' => null]],
            [],
            '2026-04-08',
            '2026-04-08'
        ));
        $this->assertCount(1, $rows);

        return $rows[0];
    }
}
