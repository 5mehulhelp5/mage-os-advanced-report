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
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
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
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Api\DataSourceInterface;
use MageOS\AdvancedReport\Api\ReportRunnerInterface;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Model\DataSource\DataSourcePool;
use MageOS\AdvancedReport\Model\DataSource\QueryTimeoutException;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Preset\LowStockCoverageQuery;
use MageOS\AdvancedReport\Model\Preset\PresetQueryPool;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use MageOS\AdvancedReport\Model\Query\PresetRequestBuilder;
use MageOS\AdvancedReport\Model\Query\RawResult;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\ReportRunner;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use MageOS\AdvancedReport\Model\Query\ResultCache;
use MageOS\AdvancedReport\Model\Query\SourceResolver;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Failures are never cached; a truncated result is a complete answer and stays cached with its warning.
 *
 * Both orders are placed now, inside every test range. The system under test is always built with `create()` and explicit arguments: the
 * shared `ReportRunner`, `SourceResolver` and `DataSourcePool` instances ignore later object manager config.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-fail-a', 'price' => 10, 'tax_class_id' => 0], 'product'),
    DataFixture(Product::class, ['sku' => 'ar-fail-b', 'price' => 10, 'tax_class_id' => 0], 'product2'),
    DataFixture(GuestCart::class, as: 'cart1'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart1.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart1.id$', 'email' => 'ar-fail-1@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart1.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart1.id$'], 'order1'),
    DataFixture(GuestCart::class, as: 'cart2'),
    DataFixture(AddProductToCart::class, ['cart_id' => '$cart2.id$', 'product_id' => '$product.id$', 'qty' => 1]),
    DataFixture(SetBillingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetShippingAddress::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetGuestEmail::class, ['cart_id' => '$cart2.id$', 'email' => 'ar-fail-2@example.com']),
    DataFixture(SetDeliveryMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(SetPaymentMethod::class, ['cart_id' => '$cart2.id$']),
    DataFixture(PlaceOrder::class, ['cart_id' => '$cart2.id$'], 'order2'),
]
final class FailureCachingTest extends TestCase
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
    public function testItNeverCachesAResultWhoseComparisonPeriodFails(): void
    {
        $source = new class() implements DataSourceInterface {
            public int $calls = 0;

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
                if (++$this->calls > 1) {
                    throw new RuntimeException('comparison boom');
                }

                return new RawResult([], ['metrics' => ['orders' => 1]], 0, [], []);
            }
        };
        $spy = $this->spyFrontend();

        try {
            $this->runnerWithSource($source, $spy)->run($this->request(['compareTo' => 'previous_period']));
            $this->fail('The failing comparison period must propagate its exception');
        } catch (RuntimeException $exception) {
            $this->assertSame('comparison boom', $exception->getMessage());
        }

        $this->assertSame(2, $source->calls, 'The main period must have succeeded before the comparison failed');
        $this->assertSame([], $spy->saves);
    }

    #[Cache('advanced_report', true)]
    public function testItNeverCachesAReportThatHitsTheQueryTimeout(): void
    {
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
                throw new QueryTimeoutException(new Phrase('The report query timed out.'));
            }
        };
        $spy = $this->spyFrontend();

        try {
            $this->runnerWithSource($source, $spy)->run($this->request());
            $this->fail('The query timeout must propagate');
        } catch (QueryTimeoutException $exception) {
            $this->assertInstanceOf(LocalizedException::class, $exception);
        }

        $this->assertSame([], $spy->saves);
    }

    #[Cache('advanced_report', true)]
    #[Config('currency/options/base', 'EUR')]
    #[Config('currency/options/base', 'EUR', ScopeInterface::SCOPE_WEBSITE, 'base')]
    public function testItNeverCachesAReportRejectedBecauseTheOrdersOfThePeriodMixBaseCurrencies(): void
    {
        $this->setOrderBaseCurrency('order1', 'EUR');
        $this->setOrderBaseCurrency('order2', 'USD');
        $spy = $this->spyFrontend();
        $runner = $this->buildRunner(['resultCache' => $this->buildResultCache($spy)]);

        try {
            $runner->run($this->request(['metrics' => ['gross_sales'], 'storeIds' => [1]]));
            $this->fail('Mixed order base currencies must be rejected');
        } catch (InvalidRequestException $exception) {
            $this->assertStringContainsString(
                'The orders in the report period use different base currencies',
                $exception->getMessage()
            );
        }

        $this->assertSame([], $spy->saves);
    }

    #[Cache('advanced_report', true)]
    public function testItNeverCachesAPresetWhoseNestedReportRunFails(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $nestedRunner = new class() implements ReportRunnerInterface {
            public function run(ReportRequestInterface $request): ReportResultInterface
            {
                throw new RuntimeException('nested boom');
            }
        };
        $query = $objectManager->create(LowStockCoverageQuery::class, ['reportRunner' => $nestedRunner]);
        $pool = $objectManager->create(PresetQueryPool::class, ['presetQueries' => [LowStockCoverageQuery::class => $query]]);
        $spy = $this->spyFrontend();
        $runner = $this->buildRunner(['presetQueryPool' => $pool, 'resultCache' => $this->buildResultCache($spy)]);
        $request = $objectManager->get(PresetRequestBuilder::class)->build(
            $objectManager->get(DefinitionRegistry::class)->getPreset('low_stock_coverage'),
            ['dateRange' => $this->dateRange(), 'bypassCache' => false]
        );

        try {
            $runner->run($request);
            $this->fail('The failing nested run must propagate its exception');
        } catch (RuntimeException $exception) {
            $this->assertSame('nested boom', $exception->getMessage());
        }

        $this->assertSame([], $spy->saves);
    }

    #[Cache('advanced_report', true)]
    #[Config('mageos_advancedreport/performance/max_rows', '1')]
    public function testItKeepsTheTruncationWarningOnACachedTruncatedResult(): void
    {
        $runner = $this->buildRunner();
        $request = $this->request([
            'dataset' => 'catalog_stock',
            'metrics' => ['qty_on_hand'],
            'dimensions' => [['code' => 'sku', 'granularity' => null]],
        ]);

        $cold = $runner->run($request);
        $cached = $runner->run($request);

        $warning = 'Results were truncated to the first 1 rows. Narrow the date range or add filters.';
        $this->assertFalse($cold->isCached());
        $this->assertTrue($cached->isCached());
        $this->assertContains($warning, $cold->getWarnings());
        $this->assertSame([$warning], array_values(array_filter(
            $cached->getWarnings(),
            static fn (string $item): bool => str_starts_with($item, 'Results were truncated')
        )));
        $this->assertGreaterThan(1, $cold->getTotalRowCount());
        $this->assertSame($cold->getTotalRowCount(), $cached->getTotalRowCount());
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function buildRunner(array $arguments = []): ReportRunner
    {
        return Bootstrap::getObjectManager()->create(ReportRunner::class, $arguments);
    }

    private function runnerWithSource(DataSourceInterface $source, FrontendInterface $spy): ReportRunner
    {
        $objectManager = Bootstrap::getObjectManager();
        $pool = $objectManager->create(
            DataSourcePool::class,
            ['sources' => ['mysql' => ['source' => $source, 'sortOrder' => 100]]]
        );

        return $this->buildRunner([
            'resultCache' => $this->buildResultCache($spy),
            'sourceResolver' => $objectManager->create(SourceResolver::class, ['sourcePool' => $pool]),
        ]);
    }

    private function buildResultCache(FrontendInterface $frontend): ResultCache
    {
        return Bootstrap::getObjectManager()->create(ResultCache::class, ['cache' => $frontend]);
    }

    private function setOrderBaseCurrency(string $fixtureAlias, string $currency): void
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        $resource->getConnection()->update(
            $resource->getTableName('sales_order'),
            ['base_currency_code' => $currency],
            ['entity_id = ?' => (int) DataFixtureStorageManager::getStorage()->get($fixtureAlias)->getId()]
        );
    }

    /**
     * @return array{type: string, from: string, to: string}
     */
    private function dateRange(): array
    {
        return [
            'type' => 'custom',
            'from' => gmdate('Y-m-d', strtotime('-30 days')),
            'to' => gmdate('Y-m-d', strtotime('+1 day')),
        ];
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
            'dateRange' => $this->dateRange(),
            'storeIds' => [],
            'bypassCache' => false,
        ], $override));
    }
}
