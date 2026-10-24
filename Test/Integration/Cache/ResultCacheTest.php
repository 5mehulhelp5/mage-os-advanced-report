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
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Test\Fixture\AddProductToCart;
use Magento\Quote\Test\Fixture\GuestCart;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Cache;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\DataSourceInterface;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Model\DataSource\DataSourcePool;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Query\RawResult;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\ReportRunner;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use MageOS\AdvancedReport\Model\Query\ResultCache;
use MageOS\AdvancedReport\Model\Query\ResultCacheLock;
use MageOS\AdvancedReport\Model\Query\SourceResolver;
use MageOS\AdvancedReport\Test\Integration\Fixture\SetOrderCreatedAt;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Result cache correctness on the real `advanced_report` cache type and database.
 *
 * Order 1 is placed now (inside every test range), order 2 is parked in 2020 and moved into range by the tests
 * that need a data change. The system under test is always built with `create()` and explicit arguments: the
 * shared `ReportRunner`, `SourceResolver` and `DataSourcePool` instances ignore later object manager config.
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
final class ResultCacheTest extends TestCase
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
    public function testItServesTheIdenticalRequestFromCacheWithTheSameRowsAndTotals(): void
    {
        $runner = $this->buildRunner();
        $request = $this->request(['dimensions' => [['code' => 'order_date', 'granularity' => 'month']]]);

        $first = $runner->run($request);
        $second = $runner->run($request);

        $this->assertFalse($first->isCached());
        $this->assertTrue($second->isCached());
        $this->assertSame($first->getRows(), $second->getRows());
        $this->assertSame($first->getTotals(), $second->getTotals());
        $this->assertEquals(1, $second->getTotals()['metrics']['orders']);
    }

    #[Cache('advanced_report', true)]
    public function testItMissesTheCacheWhenFiltersDimensionsStoresOrComparisonChange(): void
    {
        $runner = $this->buildRunner();
        $this->assertFalse($runner->run($this->request())->isCached(), 'Warm up');
        $this->assertTrue($runner->run($this->request())->isCached(), 'Positive control');

        $variations = [
            'filter' => ['filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'pending']]],
            'dimension' => ['dimensions' => [['code' => 'order_date', 'granularity' => 'day']]],
            'granularity' => ['dimensions' => [['code' => 'order_date', 'granularity' => 'month']]],
            'stores' => ['storeIds' => [1]],
            'compareTo' => ['compareTo' => 'previous_period'],
            'metrics' => ['metrics' => ['orders', 'gross_sales']],
        ];
        foreach ($variations as $name => $override) {
            $this->assertFalse($runner->run($this->request($override))->isCached(), 'Miss on ' . $name);
            $this->assertTrue($runner->run($this->request($override))->isCached(), 'Hit on repeated ' . $name);
        }

        $localeResolver = Bootstrap::getObjectManager()->get(LocaleResolver::class);
        $originalLocale = $localeResolver->getLocale();
        try {
            $localeResolver->setLocale('it_IT');
            $this->assertFalse($runner->run($this->request())->isCached(), 'Miss on locale');
        } finally {
            $localeResolver->setLocale($originalLocale);
        }
        $this->assertTrue($runner->run($this->request())->isCached(), 'Original entry is still served');
    }

    #[Cache('advanced_report', true)]
    #[Config('general/locale/timezone', 'UTC', ScopeInterface::SCOPE_WEBSITE, 'base')]
    public function testItMissesTheCacheWhenTheStoreTimezoneConfigurationChanges(): void
    {
        $runner = $this->buildRunner();
        $request = $this->request(['storeIds' => [1]]);
        $this->assertFalse($runner->run($request)->isCached());
        $this->assertTrue($runner->run($request)->isCached(), 'Positive control');

        $scopeConfig = Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class);
        try {
            $scopeConfig->setValue('general/locale/timezone', 'Asia/Tokyo', ScopeInterface::SCOPE_WEBSITE, 'base');

            $this->assertFalse($runner->run($request)->isCached());
        } finally {
            $scopeConfig->setValue('general/locale/timezone', 'UTC', ScopeInterface::SCOPE_WEBSITE, 'base');
        }
    }

    #[Cache('advanced_report', true)]
    public function testItKeepsServingCachedValuesAfterAnOrderChangeUntilTheCacheIsBypassed(): void
    {
        $runner = $this->buildRunner();
        $request = $this->request();
        $this->assertEquals(1, $runner->run($request)->getTotals()['metrics']['orders']);

        $this->moveSecondOrderIntoRange();

        $stale = $runner->run($request);
        $this->assertTrue($stale->isCached());
        $this->assertEquals(1, $stale->getTotals()['metrics']['orders']);

        $fresh = $runner->run($this->request(['bypassCache' => true]));
        $this->assertFalse($fresh->isCached());
        $this->assertEquals(2, $fresh->getTotals()['metrics']['orders']);
    }

    #[Cache('advanced_report', true)]
    public function testItRefreshesTheCachedEntryWhenTheRequestBypassesTheCache(): void
    {
        $runner = $this->buildRunner();
        $this->assertEquals(1, $runner->run($this->request())->getTotals()['metrics']['orders']);
        $this->moveSecondOrderIntoRange();

        $runner->run($this->request(['bypassCache' => true]));
        $next = $runner->run($this->request());

        $this->assertTrue($next->isCached());
        $this->assertEquals(2, $next->getTotals()['metrics']['orders']);
    }

    #[Cache('advanced_report', true)]
    #[Config('mageos_advancedreport/performance/cache_ttl_open_period', '1')]
    public function testItStoresAnOpenPeriodResultWithTheConfiguredTtl(): void
    {
        $spy = $this->spyFrontend();
        $runner = $this->buildRunner(['resultCache' => $this->buildResultCache($spy)]);

        $runner->run($this->request());

        $this->assertCount(1, $spy->saves);
        $this->assertSame(1, $spy->saves[0]['lifetime']);
    }

    #[Cache('advanced_report', true)]
    public function testItNeverCachesAFailedReportExecution(): void
    {
        $spy = $this->spyFrontend();
        $source = new class() implements DataSourceInterface {
            public function getCode(): string
            {
                return 'mysql';
            }

            public function supports(Dataset $dataset, ReportRequestInterface $request): bool
            {
                return true;
            }

            public function execute(ReportRequestInterface $request, ResolvedQuery $resolvedQuery): RawResult
            {
                throw new RuntimeException('boom');
            }
        };
        $objectManager = Bootstrap::getObjectManager();
        $pool = $objectManager->create(
            DataSourcePool::class,
            ['sources' => ['mysql' => ['source' => $source, 'sortOrder' => 100]]]
        );
        $runner = $this->buildRunner([
            'resultCache' => $this->buildResultCache($spy),
            'sourceResolver' => $objectManager->create(SourceResolver::class, ['sourcePool' => $pool]),
        ]);

        try {
            $runner->run($this->request());
            $this->fail('The failing source must propagate its exception');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame([], $spy->saves);
        $this->assertFalse($this->buildRunner()->run($this->request())->isCached());
    }

    #[Cache('advanced_report', true)]
    public function testItReleasesTheCacheLockAfterASuccessfulRun(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $real = $objectManager->get(LockManagerInterface::class);
        $recording = new class($real) implements LockManagerInterface {
            /** @var string[] */
            public array $names = [];

            public function __construct(private readonly LockManagerInterface $inner)
            {
            }

            public function isLocked(string $name): bool
            {
                return $this->inner->isLocked($name);
            }

            public function lock(string $name, int $timeout = -1): bool
            {
                $this->names[] = $name;

                return $this->inner->lock($name, $timeout);
            }

            public function unlock(string $name): bool
            {
                return $this->inner->unlock($name);
            }
        };
        $runner = $this->buildRunner([
            'resultCacheLock' => $objectManager->create(ResultCacheLock::class, ['lockManager' => $recording]),
        ]);

        $result = $runner->run($this->request());

        $this->assertFalse($result->isCached());
        $this->assertCount(1, $recording->names);
        $this->assertStringStartsWith('mageos_advancedreport_', $recording->names[0]);
        $this->assertFalse($real->isLocked($recording->names[0]));
    }

    #[Cache('advanced_report', true)]
    public function testItExecutesUnlockedAndReturnsAResultWhenTheCacheLockCannotBeAcquired(): void
    {
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $runner = $this->buildRunner([
            'resultCacheLock' => Bootstrap::getObjectManager()->create(
                ResultCacheLock::class,
                ['lockManager' => $lockManager, 'waitSeconds' => 1]
            ),
        ]);

        $result = $runner->run($this->request());

        $this->assertFalse($result->isCached());
        $this->assertEquals(1, $result->getTotals()['metrics']['orders']);
    }

    #[Cache('advanced_report', true)]
    public function testItReportsZeroSqlStatementsForTheCachedResult(): void
    {
        $runner = $this->buildRunner();

        $cold = $runner->run($this->request());
        $cached = $runner->run($this->request());

        $this->assertGreaterThan(0, $cold->getMeta()['sql_query_count']);
        $this->assertTrue($cached->isCached());
        $this->assertSame(0, $cached->getMeta()['sql_query_count']);
        $this->assertSame($cold->getMeta()['sql_query_count'], $cached->getMeta()['cached_sql_query_count']);
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function buildRunner(array $arguments = []): ReportRunner
    {
        return Bootstrap::getObjectManager()->create(ReportRunner::class, $arguments);
    }

    private function buildResultCache(FrontendInterface $frontend): ResultCache
    {
        return Bootstrap::getObjectManager()->create(ResultCache::class, ['cache' => $frontend]);
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

    private function moveSecondOrderIntoRange(): void
    {
        Bootstrap::getObjectManager()->get(SetOrderCreatedAt::class)->apply([
            'order_id' => (int) DataFixtureStorageManager::getStorage()->get('order2')->getId(),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
