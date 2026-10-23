<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Currency;

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
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Model\Export\CsvExporter;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Base currency (EUR) vs transaction currency (USD) and decimal precision of sales_orders.
 *
 * Products (no tax class, no tax): p1 10.10, p2 20.20, p3 30.30 (orders 1-3, 2026-06-10) and p4 100.00
 * (order 4, 2026-07-10). Flat rate 5.00 per unit. Orders are placed before method-level config, so their
 * stored base_currency_code is the default one; each test pins base EUR and rewrites the columns it needs.
 *
 * Precision rule asserted: sums come from MySQL DECIMAL SUM and are exact (assertSame on the float literal,
 * 60.6); ratios are plain float quotients without server-side rounding (60.6 / 3 is compared with a delta only
 * because it is not exactly representable). The CSV exports the raw unrounded value of the result.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-bc-1', 'price' => 10.10, 'tax_class_id' => 0], 'p1'),
    DataFixture(Product::class, ['sku' => 'ar-bc-2', 'price' => 20.20, 'tax_class_id' => 0], 'p2'),
    DataFixture(Product::class, ['sku' => 'ar-bc-3', 'price' => 30.30, 'tax_class_id' => 0], 'p3'),
    DataFixture(Product::class, ['sku' => 'ar-bc-4', 'price' => 100, 'tax_class_id' => 0], 'p4'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$p1.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-bc-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-06-10 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$p2.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-bc-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-06-10 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart3'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart3.id$', 'product_id' => '$p3.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart3.id$', 'email' => 'ar-bc-3@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart3.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart3.id$'], 'order3'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order3.id$', 'created_at' => '2026-06-10 12:00:00']),
    DataFixture(GuestCart::class, as: 'cart4'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart4.id$', 'product_id' => '$p4.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart4.id$', 'email' => 'ar-bc-4@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart4.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart4.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart4.id$'], 'order4'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order4.id$', 'created_at' => '2026-07-10 12:00:00']),
]
final class BaseCurrencyAndPrecisionTest extends TestCase
{
    private const array STORE_IDS = [1];

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
        Config('currency/options/base', 'EUR'),
        Config('currency/options/base', 'EUR', 'website', 'base'),
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsBaseCurrencyAmountsForAnOrderPlacedInAnotherDisplayCurrency(): void
    {
        $this->turnOrderIntoUsdDisplayOrder();

        $totals = $this->runner->totals($this->usdOrderResult(['gross_sales', 'discounts', 'shipping', 'total_sales']));

        // base amounts: 100.00 subtotal, 5.00 shipping; the USD amounts (120.00 / 6.00) must not leak
        $this->assertSame(100.0, $totals['gross_sales']);
        $this->assertSame(5.0, (float) $totals['shipping']);
        $this->assertSame(105.0, $totals['total_sales']);
    }

    #[
        Config('currency/options/base', 'EUR'),
        Config('currency/options/base', 'EUR', 'website', 'base'),
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReportsTheBaseCurrencyCodeForAnOrderPlacedInAnotherDisplayCurrency(): void
    {
        $this->turnOrderIntoUsdDisplayOrder();

        $this->assertSame('EUR', $this->usdOrderResult(['gross_sales'])->getCurrencyCode());
    }

    #[
        Config('currency/options/base', 'EUR'),
        Config('currency/options/base', 'EUR', 'website', 'base'),
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItSumsDecimalAmountsExactly(): void
    {
        $totals = $this->runner->totals($this->precisionResult(['gross_sales']));

        // 10.10 + 20.20 + 30.30, exact DECIMAL sum
        $this->assertSame(60.6, $totals['gross_sales']);
    }

    #[
        Config('currency/options/base', 'EUR'),
        Config('currency/options/base', 'EUR', 'website', 'base'),
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItReturnsDerivedRatiosUnrounded(): void
    {
        $totals = $this->runner->totals($this->precisionResult(['aov']));

        // 60.60 / 3 = 20.2 not exactly representable, and not rounded to cents or integers
        $this->assertEqualsWithDelta(20.2, (float) $totals['aov'], 0.0000001);

        $thirds = $this->runner->totals(
            $this->runner->run('sales_orders', ['aov'], [], [], '2026-06-01', '2026-07-31', self::STORE_IDS)
        );
        // (60.60 + 100.00) / 4 = 40.15
        $this->assertEqualsWithDelta(40.15, (float) $thirds['aov'], 0.0000001);
    }

    #[
        Config('currency/options/base', 'EUR'),
        Config('currency/options/base', 'EUR', 'website', 'base'),
        Config('carriers/flatrate/active', '1'),
        Config('carriers/flatrate/type', 'O'),
        Config('carriers/flatrate/price', '5'),
        Config('payment/checkmo/active', '1'),
        Config('mageos_advancedreport/sales/counted_statuses', ''),
        Config('general/locale/timezone', 'UTC'),
        Config('general/locale/timezone', 'UTC', 'website', 'base'),
    ]
    public function testItExportsMoneyValuesMatchingTheReportResult(): void
    {
        $totals = $this->runner->totals($this->precisionResult(['gross_sales', 'aov']));

        $request = Bootstrap::getObjectManager()->get(ReportRequestFactory::class)->fromArray([
            'dataset' => 'sales_orders',
            'metrics' => ['gross_sales', 'aov'],
            'dimensions' => [],
            'filters' => [],
            'dateRange' => ['type' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-30'],
            'storeIds' => self::STORE_IDS,
        ]);
        $csv = Bootstrap::getObjectManager()->get(CsvExporter::class)->export($request, true);

        $lines = array_values(array_filter(explode("\n", $csv), static fn (string $line): bool => $line !== ''));
        // last line is the totals row; the two metric cells are its last two columns
        $values = str_getcsv((string) end($lines), ',', '"', '');
        $count = \count($values);
        $this->assertSame((string) $totals['gross_sales'], $values[$count - 2]);
        $this->assertSame((string) $totals['aov'], $values[$count - 1]);
        $this->assertSame('60.6', $values[$count - 2]);
    }

    /**
     * @param string[] $metrics
     */
    private function precisionResult(array $metrics): ReportResultInterface
    {
        $this->setBaseCurrency('2026-06-10');

        return $this->runner->run('sales_orders', $metrics, [], [], '2026-06-01', '2026-06-30', self::STORE_IDS);
    }

    /**
     * @param string[] $metrics
     */
    private function usdOrderResult(array $metrics): ReportResultInterface
    {
        return $this->runner->run('sales_orders', $metrics, [], [], '2026-07-01', '2026-07-31', self::STORE_IDS);
    }

    private function setBaseCurrency(string $day): void
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $resource->getConnection()->update(
            $resource->getTableName('sales_order'),
            ['base_currency_code' => 'EUR', 'order_currency_code' => 'EUR'],
            ['created_at LIKE ?' => $day . '%']
        );
    }

    /**
     * Rewrites order 4 as a USD display-currency order (rate 1.2): non-base amounts x1.2, base columns untouched.
     */
    private function turnOrderIntoUsdDisplayOrder(): void
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $orders = $resource->getTableName('sales_order');
        $items = $resource->getTableName('sales_order_item');
        $where = ['created_at LIKE ?' => '2026-07-10%'];
        $connection->update($orders, [
            'base_currency_code' => 'EUR',
            'order_currency_code' => 'USD',
            'grand_total' => new \Zend_Db_Expr('base_grand_total * 1.2'),
            'subtotal' => new \Zend_Db_Expr('base_subtotal * 1.2'),
            'discount_amount' => new \Zend_Db_Expr('base_discount_amount * 1.2'),
            'shipping_amount' => new \Zend_Db_Expr('base_shipping_amount * 1.2'),
            'tax_amount' => new \Zend_Db_Expr('base_tax_amount * 1.2'),
        ], $where);
        $connection->update($items, [
            'price' => new \Zend_Db_Expr('base_price * 1.2'),
            'row_total' => new \Zend_Db_Expr('base_row_total * 1.2'),
        ], ['order_id IN (SELECT entity_id FROM ' . $orders . ' WHERE created_at LIKE \'2026-07-10%\')']);
    }
}
