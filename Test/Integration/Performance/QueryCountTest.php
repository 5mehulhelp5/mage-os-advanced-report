<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Performance;

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
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Number of SQL statements per report shape (result meta `sql_query_count`) and exactness of the total row count.
 *
 * Inputs: one guest order created 2026-06-10 12:00:00 with three products (ar-qc-a, ar-qc-b, ar-qc-c, one unit
 * each), so `sales_items` grouped by `sku` has exactly 3 groups in June 2026. The cache is bypassed.
 *
 * Expected statement counts (page + totals, plus one currency probe for monetary metrics):
 *  | shape                                            | statements                                    |
 *  | incomplete first page, units_sold                | 2 (page, totals)                              |
 *  | incomplete first page, gross_sales               | 3 (+ currency probe)                          |
 *  | full page (limit 3 = 3 groups)                   | 3 (page, row count, totals)                   |
 *  | comparison, both periods                         | 4 (main 2 + comparison 2, no comparison count) |
 *  | date bucket request                              | 2 (page, totals)                              |
 *  | dead_stock preset (stock anti join)              | 2 (page, totals incl. row count)              |
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-qc-a', 'price' => 10, 'tax_class_id' => 0], 'productA'),
    DataFixture(Product::class, ['sku' => 'ar-qc-b', 'price' => 20, 'tax_class_id' => 0], 'productB'),
    DataFixture(Product::class, ['sku' => 'ar-qc-c', 'price' => 30, 'tax_class_id' => 0], 'productC'),
    DataFixture(GuestCart::class, as: 'cart'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$productA.id$', 'qty' => 1]),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$productB.id$', 'qty' => 1]),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart.id$', 'product_id' => '$productC.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart.id$', 'email' => 'ar-qc@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart.id$'], 'order'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order.id$', 'created_at' => '2026-06-10 12:00:00']),
]
final class QueryCountTest extends TestCase
{
    private const array JUNE = ['dateRange' => ['type' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-30']];
    private const array BY_SKU = [['code' => 'sku', 'granularity' => null]];

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

    public function testItRunsPageAndTotalsOnlyWhenTheFirstPageIsIncomplete(): void
    {
        $plain = $this->bySku(['units_sold'], ['limit' => 50]);
        $monetary = $this->bySku(['gross_sales'], ['limit' => 50]);

        $this->assertSame(3, $plain->getTotalRowCount());
        $this->assertSame(2, $plain->getMeta()['sql_query_count'], 'page + totals, no row count');
        $this->assertSame(3, $monetary->getMeta()['sql_query_count'], 'page + totals + currency probe');
    }

    public function testItRunsTheRowCountWhenThePageIsFull(): void
    {
        $result = $this->bySku(['units_sold'], ['limit' => 3]);

        $this->assertSame(3, $result->getTotalRowCount());
        $this->assertSame(3, $result->getMeta()['sql_query_count'], 'page + row count + totals');
    }

    // max_rows 3 makes a comparison page of 3 groups "full": only the countRows=false rule avoids the count
    #[Config('mageos_advancedreport/performance/max_rows', '3')]
    public function testItRunsNoRowCountForTheComparisonPeriod(): void
    {
        // main period (06-11..06-30) has no order; the previous period (05-22..06-10) holds the 3 groups
        $result = $this->bySku(
            ['units_sold'],
            [
                'limit' => 3,
                'compareTo' => 'previous_period',
                'dateRange' => ['type' => 'custom', 'from' => '2026-06-11', 'to' => '2026-06-30'],
            ]
        );

        $this->assertNotNull($result->getComparisonRange());
        $this->assertSame(4, $result->getMeta()['sql_query_count'], 'main page + totals, comparison page + totals');
    }

    public function testItRunsPageAndTotalsOnlyForADateBucketRequest(): void
    {
        $result = $this->runner->runWith(
            'sales_orders',
            ['orders'],
            [['code' => 'order_date', 'granularity' => 'day']],
            [],
            true,
            array_merge(self::JUNE, ['limit' => 50])
        );

        $this->assertSame(2, $result->getMeta()['sql_query_count']);
    }

    public function testItCountsTheStatementsOfAStockAntiJoinPresetQuery(): void
    {
        $result = $this->runner->runPreset('dead_stock', '2026-06-01', '2026-06-30');

        $this->assertSame(2, $result->getMeta()['sql_query_count'], 'page + totals (row count comes from the totals)');
    }

    public function testItKeepsTheTotalRowCountExactForIncompleteAndFullPages(): void
    {
        $firstIncomplete = $this->bySku(['units_sold'], ['limit' => 50]);
        $laterIncomplete = $this->bySku(['units_sold'], ['limit' => 2, 'offset' => 2]);
        $full = $this->bySku(['units_sold'], ['limit' => 2]);

        $this->assertSame(3, $firstIncomplete->getTotalRowCount());
        $this->assertCount(3, $firstIncomplete->getRows());
        $this->assertSame(3, $laterIncomplete->getTotalRowCount());
        $this->assertCount(1, $laterIncomplete->getRows());
        $this->assertSame(3, $full->getTotalRowCount());
        $this->assertCount(2, $full->getRows());
        $this->assertEqualsWithDelta(3.0, $this->runner->totals($full)['units_sold'], 0.0001, 'totals unchanged');
    }

    public function testItLimitsReturnedRowsToTheRequestedPageSize(): void
    {
        $result = $this->bySku(['units_sold'], ['limit' => 2]);

        $this->assertCount(2, $result->getRows());
        $this->assertSame(3, $result->getTotalRowCount());
    }

    /**
     * @param string[] $metrics
     * @param array<string,mixed> $extra
     */
    private function bySku(array $metrics, array $extra): ReportResultInterface
    {
        return $this->runner->runWith(
            'sales_items',
            $metrics,
            self::BY_SKU,
            [['by' => 'sku', 'direction' => 'asc']],
            true,
            array_merge(
                self::JUNE,
                ['filters' => [['field' => 'sku', 'operator' => 'in', 'value' => ['ar-qc-a', 'ar-qc-b', 'ar-qc-c']]]],
                $extra
            )
        );
    }
}
