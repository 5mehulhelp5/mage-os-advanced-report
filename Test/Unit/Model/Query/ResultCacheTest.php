<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Cache\Type as CacheType;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\ResultCache;
use MageOS\AdvancedReport\Model\Query\ResultCacheWriteGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResultCache::class)]
final class ResultCacheTest extends TestCase
{
    private FrontendInterface $cache;

    private Config $config;

    private ReportRequestFactory $requestFactory;

    private ResultCache $resultCache;

    protected function setUp(): void
    {
        $this->cache = $this->createStub(FrontendInterface::class);
        $this->config = $this->createStub(Config::class);
        $this->requestFactory = new ReportRequestFactory();

        $this->resultCache = new ResultCache(
            $this->cache,
            new Json(),
            $this->config,
            $this->requestFactory,
            new ResultCacheWriteGuard(),
        );
    }

    public function testItBuildsTheSameKeyForEquivalentRequestsWithDifferentKeyOrder(): void
    {
        $requestA = $this->buildRequest(['storeIds' => [1, 2]]);
        $requestB = $this->buildRequest(['storeIds' => [2, 1]]);

        $keyA = $this->resultCache->buildKey(
            $requestA,
            'mysql',
            'config-v1',
            ['complete', 'processing'],
            ['main' => ['from' => '2026-09-01', 'to' => '2026-09-26']],
            'en_US',
        );
        $keyB = $this->resultCache->buildKey(
            $requestB,
            'mysql',
            'config-v1',
            ['processing', 'complete'],
            ['main' => ['from' => '2026-09-01', 'to' => '2026-09-26']],
            'en_US',
        );

        self::assertSame($keyA, $keyB);
    }

    public function testItBuildsDifferentKeysWhenSourceStoreScopeOrConfigVersionDiffer(): void
    {
        $request = $this->buildRequest(['storeIds' => [1, 2]]);
        $requestWithDifferentStores = $this->buildRequest(['storeIds' => [1, 3]]);

        $baseline = $this->resultCache->buildKey(
            $request,
            'mysql',
            'config-v1',
            ['complete'],
            ['main' => ['from' => '2026-09-01', 'to' => '2026-09-26']],
            'en_US',
        );
        $differentSource = $this->resultCache->buildKey(
            $request,
            'opensearch',
            'config-v1',
            ['complete'],
            ['main' => ['from' => '2026-09-01', 'to' => '2026-09-26']],
            'en_US',
        );
        $differentStoreScope = $this->resultCache->buildKey(
            $requestWithDifferentStores,
            'mysql',
            'config-v1',
            ['complete'],
            ['main' => ['from' => '2026-09-01', 'to' => '2026-09-26']],
            'en_US',
        );
        $differentConfigVersion = $this->resultCache->buildKey(
            $request,
            'mysql',
            'config-v2',
            ['complete'],
            ['main' => ['from' => '2026-09-01', 'to' => '2026-09-26']],
            'en_US',
        );

        self::assertNotSame($baseline, $differentSource);
        self::assertNotSame($baseline, $differentStoreScope);
        self::assertNotSame($baseline, $differentConfigVersion);
    }

    public function testItBuildsDifferentCacheKeysForDifferentAclContexts(): void
    {
        $request = $this->buildRequest();
        $build = fn (string $acl): string => $this->resultCache->buildKey(
            $request,
            'mysql',
            'config-v1',
            ['complete'],
            ['main' => ['from' => '2026-09-01', 'to' => '2026-09-26']],
            'en_US',
            $acl,
        );

        self::assertNotSame($build('acl-a'), $build('acl-b'));
        self::assertSame($build('acl-a'), $build('acl-a'));
    }

    public function testItBuildsDifferentKeysForTheSameRelativeRangeResolvedToDifferentDatesOrForDifferentLocales(): void
    {
        $request = $this->buildRequest(['dateRange' => ['type' => 'last_month']]);

        $resolvedInSeptember = $this->resultCache->buildKey(
            $request,
            'mysql',
            'config-v1',
            ['complete'],
            ['main' => ['from' => '2026-08-01', 'to' => '2026-08-31']],
            'en_US',
        );
        $resolvedInOctober = $this->resultCache->buildKey(
            $request,
            'mysql',
            'config-v1',
            ['complete'],
            ['main' => ['from' => '2026-09-01', 'to' => '2026-09-30']],
            'en_US',
        );
        $resolvedForItalianLocale = $this->resultCache->buildKey(
            $request,
            'mysql',
            'config-v1',
            ['complete'],
            ['main' => ['from' => '2026-08-01', 'to' => '2026-08-31']],
            'it_IT',
        );

        self::assertNotSame($resolvedInSeptember, $resolvedInOctober);
        self::assertNotSame($resolvedInSeptember, $resolvedForItalianLocale);
    }

    /**
     * @param array<string,mixed> $scopeContext
     */
    private function buildKeyForScope(array $scopeContext): string
    {
        return $this->resultCache->buildKey(
            $this->buildRequest(),
            'mysql',
            'config-v1',
            ['complete'],
            ['main' => ['from' => '2026-09-01', 'to' => '2026-09-26']],
            'en_US',
            '',
            $scopeContext,
        );
    }

    public function testItProducesADifferentKeyWhenTheScopeTimezoneChanges(): void
    {
        self::assertNotSame(
            $this->buildKeyForScope(['timezone' => 'Europe/Rome']),
            $this->buildKeyForScope(['timezone' => 'America/Chicago']),
        );
    }

    public function testItProducesADifferentKeyWhenTheFirstDayOfWeekChanges(): void
    {
        self::assertNotSame(
            $this->buildKeyForScope(['firstDayOfWeek' => 0]),
            $this->buildKeyForScope(['firstDayOfWeek' => 1]),
        );
    }

    public function testItProducesADifferentKeyWhenTheScopeBaseCurrenciesChange(): void
    {
        self::assertNotSame(
            $this->buildKeyForScope(['baseCurrencies' => ['USD']]),
            $this->buildKeyForScope(['baseCurrencies' => ['EUR']]),
        );
    }

    public function testItProducesTheSameKeyForTheSameScopeTimezonesInADifferentOrder(): void
    {
        self::assertSame(
            $this->buildKeyForScope(['timezones' => ['Europe/Rome', 'America/Chicago']]),
            $this->buildKeyForScope(['timezones' => ['America/Chicago', 'Europe/Rome']]),
        );
    }

    public function testItUsesTheOpenPeriodTtlWhenTheRangeIncludesToday(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $config = $this->createStub(Config::class);
        $config->method('getCacheTtlOpenPeriod')->willReturn(300);
        $resultCache = new ResultCache($cache, new Json(), $config, $this->requestFactory, new ResultCacheWriteGuard());

        $cache->expects(self::once())
            ->method('save')
            ->with(self::anything(), 'cache-key', self::anything(), 300);

        $resultCache->save('cache-key', ['rows' => []], true);
    }

    public function testItUsesTheClosedPeriodTtlOtherwise(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $config = $this->createStub(Config::class);
        $config->method('getCacheTtlClosedPeriod')->willReturn(86400);
        $resultCache = new ResultCache($cache, new Json(), $config, $this->requestFactory, new ResultCacheWriteGuard());

        $cache->expects(self::once())
            ->method('save')
            ->with(self::anything(), 'cache-key', self::anything(), 86400);

        $resultCache->save('cache-key', ['rows' => []], false);
    }

    public function testItReturnsNullOnCacheMissAndTheUnserializedPayloadOnHit(): void
    {
        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('load')->willReturnMap([
            ['missing-key', false],
            ['present-key', '{"rows":[{"metrics":{"orders":5}}]}'],
        ]);
        $resultCache = new ResultCache($cache, new Json(), $this->config, $this->requestFactory, new ResultCacheWriteGuard());

        self::assertNull($resultCache->load('missing-key'));
        self::assertSame(['rows' => [['metrics' => ['orders' => 5]]]], $resultCache->load('present-key'));
    }

    public function testItTagsSavedEntriesWithTheModuleCacheTag(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $resultCache = new ResultCache($cache, new Json(), $this->config, $this->requestFactory, new ResultCacheWriteGuard());

        $cache->expects(self::once())
            ->method('save')
            ->with(self::anything(), self::anything(), [CacheType::CACHE_TAG], self::anything());

        $resultCache->save('cache-key', ['rows' => []], true);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function buildRequest(array $overrides = []): ReportRequestInterface
    {
        return $this->requestFactory->fromArray(array_replace([
            'dataset' => 'sales_orders',
            'metrics' => ['orders', 'net_sales'],
            'dateRange' => ['type' => 'last_30_days'],
            'storeIds' => [1, 2],
            'source' => 'mysql',
        ], $overrides));
    }

    public function testItDoesNotSaveTheExportResultToTheResultCache(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::never())->method('save');
        $guard = new ResultCacheWriteGuard();
        $resultCache = new ResultCache($cache, new Json(), $this->config, $this->requestFactory, $guard);

        $guard->runWithoutStore(static fn () => $resultCache->save('key', ['rows' => []], false));
    }

    public function testItReusesACachedResultWhenOneExistsForTheExportRequest(): void
    {
        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('load')->willReturn('{"rows":[1]}');
        $guard = new ResultCacheWriteGuard();
        $resultCache = new ResultCache($cache, new Json(), $this->config, $this->requestFactory, $guard);

        $loaded = $guard->runWithoutStore(static fn (): ?array => $resultCache->load('key'));

        self::assertSame(['rows' => [1]], $loaded);
    }
}
