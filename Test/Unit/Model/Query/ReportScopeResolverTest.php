<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Query\ReportScopeResolver;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportScopeResolver::class)]
final class ReportScopeResolverTest extends TestCase
{
    /**
     * @param array<int,string> $storeTimezones
     */
    private function resolver(array $storeTimezones, ?string $defaultTimezone = 'America/Chicago'): ReportScopeResolver
    {
        $map = $this->createStub(StoreTimezoneMap::class);
        $map->method('getTimezonesForStores')->willReturnCallback(
            static fn (array $ids): array => $ids === [] ? $storeTimezones : array_intersect_key($storeTimezones, array_flip($ids))
        );

        $defaultStore = $this->createStub(StoreInterface::class);
        $defaultStore->method('getId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultStore);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($defaultTimezone);

        $config = $this->createStub(Config::class);
        $config->method('getFirstDayOfWeek')->willReturnCallback(static fn (?int $id): int => $id ?? 0);

        return new ReportScopeResolver($map, $storeManager, $scopeConfig, $config);
    }

    public function testItUsesTheFirstSelectedStoreAsReferenceWhenAllSelectedStoresShareOneTimezone(): void
    {
        $scope = $this->resolver([1 => 'Europe/Rome', 3 => 'Europe/Rome', 4 => 'Asia/Tokyo'])->resolve([3, 1]);

        self::assertSame(3, $scope->referenceStoreId);
        self::assertSame('Europe/Rome', $scope->timezone);
        self::assertSame(3, $scope->firstDayOfWeek);
    }

    public function testItFallsBackToTheDefaultScopeTimezoneAndFirstDayWhenTheSelectedStoresSpanSeveralTimezones(): void
    {
        $scope = $this->resolver([1 => 'Europe/Rome', 3 => 'Asia/Tokyo'])->resolve([1, 3]);

        self::assertNull($scope->referenceStoreId);
        self::assertSame('America/Chicago', $scope->timezone);
        self::assertSame(0, $scope->firstDayOfWeek);
    }

    public function testItUsesTheSharedTimezoneAndTheDefaultStoreViewFirstDayWhenAllStoresAreSelectedAndShareOneTimezone(): void
    {
        $scope = $this->resolver([1 => 'Europe/Rome', 2 => 'Europe/Rome'])->resolve([]);

        self::assertSame(2, $scope->referenceStoreId);
        self::assertSame('Europe/Rome', $scope->timezone);
        self::assertSame(2, $scope->firstDayOfWeek);
    }

    public function testItFallsBackToTheDefaultScopeTimezoneAndFirstDayWhenAllStoresAreSelectedAndSpanSeveralTimezones(): void
    {
        $scope = $this->resolver([1 => 'Europe/Rome', 2 => 'Asia/Tokyo'], null)->resolve([]);

        self::assertNull($scope->referenceStoreId);
        self::assertSame('UTC', $scope->timezone);
        self::assertSame(0, $scope->firstDayOfWeek);
    }

    public function testItListsTheDistinctTimezonesOfTheStoresInScope(): void
    {
        $scope = $this->resolver([1 => 'Europe/Rome', 2 => 'Asia/Tokyo', 3 => 'Europe/Rome'])->resolve([]);

        self::assertSame(['Asia/Tokyo', 'Europe/Rome'], $scope->timezones);
    }
}
