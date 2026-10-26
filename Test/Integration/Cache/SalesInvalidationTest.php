<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Cache;

use Magento\Catalog\Test\Fixture\Product;
use Magento\Checkout\Test\Fixture\PlaceOrder;
use Magento\Checkout\Test\Fixture\SetBillingAddress;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod;
use Magento\Checkout\Test\Fixture\SetGuestEmail;
use Magento\Checkout\Test\Fixture\SetPaymentMethod;
use Magento\Checkout\Test\Fixture\SetShippingAddress;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Sales\Test\Fixture\Creditmemo;
use Magento\Sales\Test\Fixture\Invoice;
use Magento\Sales\Test\Fixture\Shipment;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Cache;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\ReportRunner;
use MageOS\AdvancedReport\Model\Query\ResultCache;
use MageOS\AdvancedReport\Test\Integration\Fixture\CancelOrder;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Documented TTL-only freshness for sales changes made after a report was cached.
 *
 * Order 1 is placed now and not invoiced (target of the cancellation and the shipment: a fully invoiced order cannot
 * be canceled). Order 2 is placed now and invoiced (target of the full refund).
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-inval-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-inval-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-inval-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(Invoice::class, ['order_id' => '$order2.id$']),
]
final class SalesInvalidationTest extends TestCase
{
    private ReportTestRunner $reportTestRunner;

    protected function setUp(): void
    {
        $this->reportTestRunner = new ReportTestRunner();
        $this->reportTestRunner->login();
        Bootstrap::getObjectManager()->get(CacheType::class)->clean();
    }

    protected function tearDown(): void
    {
        $this->reportTestRunner->logout();
    }

    #[Cache('advanced_report', true)]
    #[Config('mageos_advancedreport/sales/counted_statuses', '')]
    public function testItKeepsServingThePreCancellationValuesUntilTheCacheIsBypassed(): void
    {
        $runner = $this->buildRunner();
        $this->assertEquals(2, $runner->run($this->request())->getTotals()['metrics']['orders']);

        Bootstrap::getObjectManager()->get(CancelOrder::class)->apply(['order_id' => $this->orderId('order1')]);

        $stale = $runner->run($this->request());
        $this->assertTrue($stale->isCached());
        $this->assertEquals(2, $stale->getTotals()['metrics']['orders']);

        $fresh = $runner->run($this->request(['bypassCache' => true]));
        $this->assertFalse($fresh->isCached());
        $this->assertEquals(1, $fresh->getTotals()['metrics']['orders']);
    }

    #[Cache('advanced_report', true)]
    #[Config('mageos_advancedreport/sales/counted_statuses', '')]
    public function testItKeepsServingThePreRefundReturnsUntilTheCacheIsBypassed(): void
    {
        $runner = $this->buildRunner();
        $request = $this->request(['metrics' => ['returns']]);
        $this->assertEquals(0, $runner->run($request)->getTotals()['metrics']['returns']);

        Bootstrap::getObjectManager()->get(Creditmemo::class)->apply(['order_id' => $this->orderId('order2')]);

        $stale = $runner->run($request);
        $this->assertTrue($stale->isCached());
        $this->assertEquals(0, $stale->getTotals()['metrics']['returns']);

        $fresh = $runner->run($this->request(['metrics' => ['returns'], 'bypassCache' => true]));
        $this->assertFalse($fresh->isCached());
        $this->assertEquals(10, $fresh->getTotals()['metrics']['returns']);
    }

    #[Cache('advanced_report', true)]
    #[Config('mageos_advancedreport/sales/counted_statuses', '')]
    public function testItKeepsServingThePreShipmentUnitsShippedUntilTheCacheIsBypassed(): void
    {
        $runner = $this->buildRunner();
        $request = $this->request(['dataset' => 'sales_items', 'metrics' => ['units_shipped']]);
        $this->assertEquals(0, $runner->run($request)->getTotals()['metrics']['units_shipped']);

        Bootstrap::getObjectManager()->get(Shipment::class)->apply(['order_id' => $this->orderId('order1')]);

        $stale = $runner->run($request);
        $this->assertTrue($stale->isCached());
        $this->assertEquals(0, $stale->getTotals()['metrics']['units_shipped']);

        $fresh = $runner->run(
            $this->request(['dataset' => 'sales_items', 'metrics' => ['units_shipped'], 'bypassCache' => true])
        );
        $this->assertFalse($fresh->isCached());
        $this->assertEquals(1, $fresh->getTotals()['metrics']['units_shipped']);
    }

    #[Cache('advanced_report', true)]
    #[Config('mageos_advancedreport/sales/counted_statuses', '')]
    #[Config('mageos_advancedreport/performance/cache_ttl_open_period', '1')]
    public function testItServesFreshValuesOnceTheOpenPeriodTtlHasExpired(): void
    {
        $runner = $this->buildRunner();
        $this->assertFalse($runner->run($this->request())->isCached());

        Bootstrap::getObjectManager()->get(CancelOrder::class)->apply(['order_id' => $this->orderId('order1')]);
        sleep(2);

        $result = $runner->run($this->request());
        $this->assertFalse($result->isCached());
        $this->assertEquals(1, $result->getTotals()['metrics']['orders']);
    }

    #[Cache('advanced_report', true)]
    #[Config('mageos_advancedreport/sales/counted_statuses', '')]
    #[Config('mageos_advancedreport/performance/cache_ttl_closed_period', '7200')]
    public function testItStoresAClosedPeriodResultWithTheClosedPeriodTtl(): void
    {
        $spy = $this->spyFrontend();
        $runner = $this->buildRunner([
            'resultCache' => Bootstrap::getObjectManager()->create(ResultCache::class, ['cache' => $spy]),
        ]);

        $runner->run($this->request([
            'dateRange' => ['type' => 'custom', 'from' => '2020-01-01', 'to' => '2020-01-31'],
        ]));

        $this->assertCount(1, $spy->saves);
        $this->assertSame(7200, $spy->saves[0]['lifetime']);
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function buildRunner(array $arguments = []): ReportRunner
    {
        return Bootstrap::getObjectManager()->create(ReportRunner::class, $arguments);
    }

    /**
     * A frontend that records every save and delegates everything to the real `advanced_report` cache type.
     */
    private function spyFrontend(): FrontendInterface
    {
        return new class(Bootstrap::getObjectManager()->get(CacheType::class)) implements FrontendInterface {
            /** @var list<array{id: string, lifetime: ?int}> */
            public array $saves = [];

            public function __construct(private readonly FrontendInterface $inner)
            {
            }

            public function test($identifier)
            {
                return $this->inner->test($identifier);
            }

            public function load($identifier)
            {
                return $this->inner->load($identifier);
            }

            public function save($data, $identifier, array $tags = [], $lifeTime = null)
            {
                $this->saves[] = ['id' => (string) $identifier, 'lifetime' => $lifeTime];

                return $this->inner->save($data, $identifier, $tags, $lifeTime);
            }

            public function remove($identifier)
            {
                return $this->inner->remove($identifier);
            }

            public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
            {
                return $this->inner->clean($mode, $tags);
            }

            public function getBackend()
            {
                return $this->inner->getBackend();
            }

            public function getLowLevelFrontend()
            {
                return $this->inner->getLowLevelFrontend();
            }
        };
    }

    /**
     * @param array<string,mixed> $override
     */
    private function request(array $override = []): ReportRequestInterface
    {
        return Bootstrap::getObjectManager()->get(ReportRequestFactory::class)->fromArray(array_merge([
            'dataset' => 'sales_orders',
            'metrics' => ['orders'],
            'dimensions' => [],
            'filters' => [],
            'dateRange' => [
                'type' => 'custom',
                'from' => gmdate('Y-m-d', strtotime('-30 days')),
                'to' => gmdate('Y-m-d', strtotime('+1 day')),
            ],
            'storeIds' => [],
            'bypassCache' => false,
        ], $override));
    }

    private function orderId(string $alias): int
    {
        return (int) DataFixtureStorageManager::getStorage()->get($alias)->getId();
    }
}
