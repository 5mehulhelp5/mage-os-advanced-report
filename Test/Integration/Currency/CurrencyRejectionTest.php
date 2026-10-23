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
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Data\Form\FormKey;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractBackendController;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderStore;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use MageOS\AdvancedReport\Test\Integration\Helper\StoreProvisioner;

/**
 * Currency rejection: no report silently adds amounts of different base currencies.
 *
 * Base currencies are pinned per test method (default and website `base` to EUR, `ar_website_2` to USD or EUR);
 * the price scope is website so the base currency is read per website.
 *
 * Inputs (product 10.00, flat rate 5.00 per order, guests, all placed on the default store):
 *  - order 1: created 2026-06-10 12:00:00, EUR
 *  - order 2: created 2026-06-11 12:00:00, EUR (the tests change its store, currency or date)
 * Expected: gross_sales of one order = 10.00; range 2026-06-08..2026-06-14.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-cur-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-cur-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-cur-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order1.id$', 'created_at' => '2026-06-10 12:00:00']),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2026-06-11 12:00:00']),
]
final class CurrencyRejectionTest extends AbstractBackendController
{
    private const string FROM = '2026-06-08';
    private const string TO = '2026-06-14';
    private const string DATA_URI = 'backend/advancedreport/report/data';

    /** @var array{website_id:int, store_id:int} */
    private static array $secondWebsiteStore;

    private ReportTestRunner $runner;

    public static function setUpBeforeClass(): void
    {
        self::$secondWebsiteStore = (new StoreProvisioner())->ensureSecondWebsiteStore();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new ReportTestRunner();
    }

    #[
        Config('catalog/price/scope', 1),
        Config('currency/options/base', 'EUR'),
        Config('currency/options/base', 'EUR', ScopeInterface::SCOPE_WEBSITE, 'base'),
        Config('currency/options/base', 'USD', ScopeInterface::SCOPE_WEBSITE, 'ar_website_2'),
        Config('currency/options/allow', 'EUR,USD'),
        Config('currency/options/allow', 'EUR,USD', ScopeInterface::SCOPE_WEBSITE, 'base'),
        Config('currency/options/allow', 'EUR,USD', ScopeInterface::SCOPE_WEBSITE, 'ar_website_2'),
    ]
    public function testItRejectsAMoneyReportForAllStoresWhenWebsitesHaveDifferentBaseCurrencies(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage(
            'The selected stores use different base currencies (EUR, USD): '
            . 'select stores that share one base currency to report amounts.'
        );

        $this->runner->run('sales_orders', ['gross_sales'], [], [], self::FROM, self::TO);
    }

    #[
        Config('catalog/price/scope', 1),
        Config('currency/options/base', 'EUR'),
        Config('currency/options/base', 'EUR', ScopeInterface::SCOPE_WEBSITE, 'base'),
        Config('currency/options/base', 'USD', ScopeInterface::SCOPE_WEBSITE, 'ar_website_2'),
        Config('currency/options/allow', 'EUR,USD'),
        Config('currency/options/allow', 'EUR,USD', ScopeInterface::SCOPE_WEBSITE, 'base'),
        Config('currency/options/allow', 'EUR,USD', ScopeInterface::SCOPE_WEBSITE, 'ar_website_2'),
    ]
    public function testItReturns400FromTheReportDataEndpointForAMixedCurrencyMoneyReport(): void
    {
        $this->httpRequest()->setMethod('POST')->setPostValue([
            'form_key' => Bootstrap::getObjectManager()->get(FormKey::class)->getFormKey(),
            'request' => (string) json_encode([
                'dataset' => 'sales_orders',
                'metrics' => ['gross_sales'],
                'dimensions' => [],
                'filters' => [],
                'dateRange' => ['type' => 'custom', 'from' => self::FROM, 'to' => self::TO],
                'storeIds' => [],
                'bypassCache' => true,
            ]),
        ]);

        $this->dispatch(self::DATA_URI);

        $response = $this->getResponse();
        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertSame(400, $response->getHttpResponseCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['error']);
        $this->assertSame(
            'The selected stores use different base currencies (EUR, USD): '
            . 'select stores that share one base currency to report amounts.',
            $body['message']
        );
    }

    #[
        Config('catalog/price/scope', 1),
        Config('currency/options/base', 'EUR'),
        Config('currency/options/base', 'EUR', ScopeInterface::SCOPE_WEBSITE, 'base'),
        Config('currency/options/base', 'USD', ScopeInterface::SCOPE_WEBSITE, 'ar_website_2'),
        Config('currency/options/allow', 'EUR,USD'),
        Config('currency/options/allow', 'EUR,USD', ScopeInterface::SCOPE_WEBSITE, 'base'),
        Config('currency/options/allow', 'EUR,USD', ScopeInterface::SCOPE_WEBSITE, 'ar_website_2'),
    ]
    public function testItRunsAnOrdersAndUnitsReportAcrossWebsitesWithDifferentBaseCurrencies(): void
    {
        $this->placeOrdersInEur();
        $this->moveOrderTwoToSecondWebsite();
        $this->runner->login();

        $result = $this->runner->run('sales_orders', ['orders', 'units'], [], [], self::FROM, self::TO);

        $totals = $this->runner->totals($result);
        $this->assertEquals(2, $totals['orders']);
        $this->assertEquals(2, $totals['units']);
        $this->runner->logout();
    }

    #[
        Config('catalog/price/scope', 1),
        Config('currency/options/base', 'EUR'),
        Config('currency/options/base', 'EUR', ScopeInterface::SCOPE_WEBSITE, 'base'),
        Config('currency/options/base', 'EUR', ScopeInterface::SCOPE_WEBSITE, 'ar_website_2'),
    ]
    public function testItRejectsAMoneyReportWhenOrdersInThePeriodCarryDifferentBaseCurrencies(): void
    {
        $this->placeOrdersInEur();
        $this->runner->login();
        $this->setOrderBaseCurrency(2, 'USD');

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage(
            'The orders in the report period use different base currencies (EUR, USD): '
            . 'amounts cannot be added without conversion.'
        );

        $this->runner->run('sales_orders', ['gross_sales'], [], [], self::FROM, self::TO);
    }

    #[
        Config('catalog/price/scope', 1),
        Config('currency/options/base', 'EUR'),
        Config('currency/options/base', 'EUR', ScopeInterface::SCOPE_WEBSITE, 'base'),
        Config('currency/options/base', 'EUR', ScopeInterface::SCOPE_WEBSITE, 'ar_website_2'),
    ]
    public function testItRejectsAMoneyReportWhenTheComparisonPeriodUsesAnotherBaseCurrency(): void
    {
        $this->placeOrdersInEur();
        $this->runner->login();
        $this->setOrderBaseCurrency(2, 'USD');
        // order 2 moves into the previous period 2026-06-01..2026-06-07
        Bootstrap::getObjectManager()->get(SetOrderCreatedAt::class)->apply([
            'order_id' => $this->orderId(2),
            'created_at' => '2026-06-03 12:00:00',
        ]);

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage(
            'The orders in the report period use different base currencies (EUR, USD): '
            . 'amounts cannot be added without conversion.'
        );

        $this->runner->runWith('sales_orders', ['gross_sales'], [], [], true, [
            'dateRange' => ['type' => 'custom', 'from' => self::FROM, 'to' => self::TO],
            'compareTo' => 'previous_period',
        ]);
    }

    #[
        Config('catalog/price/scope', 1),
        Config('currency/options/base', 'EUR'),
        Config('currency/options/base', 'EUR', ScopeInterface::SCOPE_WEBSITE, 'base'),
        Config('currency/options/base', 'USD', ScopeInterface::SCOPE_WEBSITE, 'ar_website_2'),
        Config('currency/options/allow', 'EUR,USD'),
        Config('currency/options/allow', 'EUR,USD', ScopeInterface::SCOPE_WEBSITE, 'base'),
        Config('currency/options/allow', 'EUR,USD', ScopeInterface::SCOPE_WEBSITE, 'ar_website_2'),
    ]
    public function testItReportsTheWebsiteBaseCurrencyCodeForASingleWebsiteMoneyReport(): void
    {
        $this->placeOrdersInEur();
        $this->moveOrderTwoToSecondWebsite();
        $this->setOrderBaseCurrency(2, 'USD');
        $this->runner->login();

        $main = $this->runner->run(
            'sales_orders',
            ['gross_sales'],
            [],
            [],
            self::FROM,
            self::TO,
            [$this->defaultStoreId()]
        );
        $second = $this->runner->run(
            'sales_orders',
            ['gross_sales'],
            [],
            [],
            self::FROM,
            self::TO,
            [self::$secondWebsiteStore['store_id']]
        );

        $this->assertSame('EUR', $main->getCurrencyCode());
        $this->assertEqualsWithDelta(10.0, $this->runner->totals($main)['gross_sales'], 0.0001);
        $this->assertSame('USD', $second->getCurrencyCode());
        $this->assertEqualsWithDelta(10.0, $this->runner->totals($second)['gross_sales'], 0.0001);
    }

    /**
     * The fixture orders carry the base currency of the install default at placement time: force EUR.
     */
    private function placeOrdersInEur(): void
    {
        $this->setOrderBaseCurrency(1, 'EUR');
        $this->setOrderBaseCurrency(2, 'EUR');
    }

    private function moveOrderTwoToSecondWebsite(): void
    {
        Bootstrap::getObjectManager()->get(SetOrderStore::class)->apply([
            'order_id' => $this->orderId(2),
            'store_id' => self::$secondWebsiteStore['store_id'],
        ]);
    }

    private function setOrderBaseCurrency(int $orderNumber, string $currency): void
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $resource->getConnection()->update(
            $resource->getTableName('sales_order'),
            ['base_currency_code' => $currency],
            ['entity_id = ?' => $this->orderId($orderNumber)]
        );
    }

    private function orderId(int $orderNumber): int
    {
        return (int) DataFixtureStorageManager::getStorage()->get('order' . $orderNumber)->getId();
    }

    private function defaultStoreId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)
            ->getDefaultStoreView()->getId();
    }

    private function httpRequest(): HttpRequest
    {
        $request = $this->getRequest();
        $this->assertInstanceOf(HttpRequest::class, $request);

        return $request;
    }
}
