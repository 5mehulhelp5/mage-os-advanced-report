<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Cache;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Test\Fixture\Product;
use Magento\Framework\Cache\FrontendInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Cache;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\ReportRunner;
use MageOS\AdvancedReport\Model\Query\ResultCache;
use MageOS\AdvancedReport\Test\Integration\Helper\ReportTestRunner;
use PHPUnit\Framework\TestCase;

/**
 * Stock and product changes are not invalidated: a cached stock report keeps its quantity and product name label
 * until the cache is bypassed or expires.
 */
#[
    DbIsolation(true),
    AppIsolation(true),
    AppArea('adminhtml'),
    DataFixture(Product::class, ['sku' => 'ar-ci-a', 'name' => 'ar-ci-old-name', 'price' => 10], 'product'),
]
final class CatalogInvalidationTest extends TestCase
{
    private const string SKU = 'ar-ci-a';

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
    public function testItKeepsServingThePreviousStockQuantityUntilTheCacheIsBypassed(): void
    {
        $this->setDefaultSourceQuantity(10.0);
        $runner = $this->buildRunner();
        $request = $this->request(['dimensions' => [['code' => 'sku', 'granularity' => null]]]);
        $this->assertEqualsWithDelta(10.0, $this->quantity($runner->run($request)->getRows()), 0.0001);

        $this->setDefaultSourceQuantity(25.0);

        $stale = $runner->run($request);
        $this->assertTrue($stale->isCached());
        $this->assertEqualsWithDelta(10.0, $this->quantity($stale->getRows()), 0.0001);

        $fresh = $runner->run($this->request([
            'dimensions' => [['code' => 'sku', 'granularity' => null]],
            'bypassCache' => true,
        ]));
        $this->assertFalse($fresh->isCached());
        $this->assertEqualsWithDelta(25.0, $this->quantity($fresh->getRows()), 0.0001);
    }

    #[Cache('advanced_report', true)]
    public function testItKeepsServingThePreviousProductNameLabelUntilTheCacheIsBypassed(): void
    {
        $runner = $this->buildRunner();
        $dimensions = [['code' => 'product_id', 'granularity' => null]];
        $request = $this->request(['dimensions' => $dimensions]);
        $this->assertSame('ar-ci-old-name', $this->label($runner->run($request)->getRows()));

        $repository = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class);
        $product = $repository->get(self::SKU, true, 0, true);
        $product->setName('ar-ci-new-name');
        $repository->save($product);

        $stale = $runner->run($request);
        $this->assertTrue($stale->isCached());
        $this->assertSame('ar-ci-old-name', $this->label($stale->getRows()));

        $fresh = $runner->run($this->request(['dimensions' => $dimensions, 'bypassCache' => true]));
        $this->assertFalse($fresh->isCached());
        $this->assertSame('ar-ci-new-name', $this->label($fresh->getRows()));
    }

    #[Cache('advanced_report', true)]
    public function testItServesTheIdenticalStockRequestFromCache(): void
    {
        $this->setDefaultSourceQuantity(10.0);
        $runner = $this->buildRunner();
        $request = $this->request();

        $first = $runner->run($request);
        $second = $runner->run($request);

        $this->assertFalse($first->isCached());
        $this->assertTrue($second->isCached());
        $this->assertEquals($first->getRows(), $second->getRows());
        $this->assertEquals($first->getTotals(), $second->getTotals());
    }

    #[Cache('advanced_report', true)]
    #[Config('mageos_advancedreport/performance/cache_ttl_closed_period', '7200')]
    public function testItStoresAStockReportWithoutADateRangeWithTheClosedPeriodTtl(): void
    {
        $spy = $this->spyFrontend();
        $resultCache = Bootstrap::getObjectManager()->create(ResultCache::class, ['cache' => $spy]);
        $runner = $this->buildRunner(['resultCache' => $resultCache]);

        $runner->run($this->request());

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
     * @param array<string,mixed> $override
     */
    private function request(array $override = []): ReportRequestInterface
    {
        return Bootstrap::getObjectManager()->get(ReportRequestFactory::class)->fromArray(array_merge([
            'dataset' => 'catalog_stock',
            'metrics' => ['qty_on_hand'],
            'dimensions' => [],
            'filters' => [['field' => 'sku', 'operator' => 'in', 'value' => [self::SKU]]],
            'storeIds' => [],
            'bypassCache' => false,
        ], $override));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function quantity(array $rows): float
    {
        $this->assertCount(1, $rows);

        return (float) $rows[0]['metrics']['qty_on_hand'];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function label(array $rows): string
    {
        $this->assertCount(1, $rows);

        return (string) $rows[0]['dims']['product_id'];
    }

    private function setDefaultSourceQuantity(float $quantity): void
    {
        $objectManager = Bootstrap::getObjectManager();
        /** @var SourceItemInterface $item */
        $item = $objectManager->get(SourceItemInterfaceFactory::class)->create();
        $item->setSku(self::SKU);
        $item->setSourceCode($objectManager->get(DefaultSourceProviderInterface::class)->getCode());
        $item->setQuantity($quantity);
        $item->setStatus(SourceItemInterface::STATUS_IN_STOCK);
        $objectManager->get(SourceItemsSaveInterface::class)->execute([$item]);
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
}
