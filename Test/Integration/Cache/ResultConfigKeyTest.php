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
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Cache;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\ReportRunner;
use MageOS\AdvancedReport\Model\Query\ResultCache;
use MageOS\AdvancedReport\Model\Query\ResultConfigFingerprint;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * The result cache key covers the result-affecting settings, so a change that bypasses the admin save observer
 * (`config:set`, `app:config:import`) still misses the cache.
 *
 * The runner is built with `create()` because the shared `ReportRunner` ignores later object manager config.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-cache-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-cache-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-cache-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
    DataFixture(SetOrderCreatedAt::class, ['order_id' => '$order2.id$', 'created_at' => '2020-01-01 00:00:00']),
]
final class ResultConfigKeyTest extends TestCase
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
    public function testItMissesTheCacheWhenReportAttributesChangeWithoutTheAdminConfigSave(): void
    {
        $this->assertMissesAfterConfigChange('mageos_advancedreport/catalog/report_attributes', 'color,size');
    }

    #[Cache('advanced_report', true)]
    public function testItMissesTheCacheWhenDeadStockDaysChangeWithoutTheAdminConfigSave(): void
    {
        $this->assertMissesAfterConfigChange('mageos_advancedreport/catalog/dead_stock_days', '45');
    }

    #[Cache('advanced_report', true)]
    public function testItMissesTheCacheWhenMaxRowsChangeWithoutTheAdminConfigSave(): void
    {
        $this->assertMissesAfterConfigChange('mageos_advancedreport/performance/max_rows', '5');
    }

    #[Cache('advanced_report', true)]
    public function testItMissesTheCacheWhenTheInventoryMinQtyChanges(): void
    {
        $this->assertMissesAfterConfigChange('cataloginventory/item_options/min_qty', '7');
    }

    #[Cache('advanced_report', true)]
    public function testItBuildsADifferentCacheKeyWhenTheReportConnectionNameChanges(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $fingerprint = $objectManager->get(ResultConfigFingerprint::class);
        $resultCache = $objectManager->get(ResultCache::class);
        $request = $this->request();
        $path = 'mageos_advancedreport/performance/connection_name';
        $scopeConfig = $objectManager->get(MutableScopeConfigInterface::class);
        $original = $scopeConfig->getValue($path);
        $buildKey = static fn (): string => $resultCache->buildKey(
            $request,
            'mysql',
            '1',
            [],
            [],
            'en_US',
            '',
            [],
            $fingerprint->get()
        );

        $before = $buildKey();
        try {
            $scopeConfig->setValue($path, 'ar_other_connection');

            $this->assertNotSame($before, $buildKey());
        } finally {
            $scopeConfig->setValue($path, $original);
        }
        $this->assertSame($before, $buildKey());
    }

    #[Cache('advanced_report', true)]
    public function testItKeepsServingTheCachedEntryWhenOnlyTheQueryTimeoutChanges(): void
    {
        $this->assertHitsAfterConfigChange('mageos_advancedreport/performance/query_timeout_ms', '12345');
    }

    private function assertMissesAfterConfigChange(string $path, string $newValue): void
    {
        $this->assertCachedRunAfterConfigChange($path, $newValue, false);
    }

    private function assertHitsAfterConfigChange(string $path, string $newValue): void
    {
        $this->assertCachedRunAfterConfigChange($path, $newValue, true);
    }

    private function assertCachedRunAfterConfigChange(string $path, string $newValue, bool $expectCached): void
    {
        $runner = $this->buildRunner();
        $request = $this->request();
        $this->assertFalse($runner->run($request)->isCached(), 'Warm up');
        $this->assertTrue($runner->run($request)->isCached(), 'Positive control');

        $scopeConfig = Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class);
        $original = $scopeConfig->getValue($path);
        try {
            $scopeConfig->setValue($path, $newValue);

            $this->assertSame($expectCached, $runner->run($request)->isCached());
        } finally {
            $scopeConfig->setValue($path, $original);
        }
    }

    private function buildRunner(): ReportRunner
    {
        return Bootstrap::getObjectManager()->create(ReportRunner::class);
    }

    private function request(): ReportRequestInterface
    {
        return Bootstrap::getObjectManager()->get(ReportRequestFactory::class)->fromArray([
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
        ]);
    }
}
