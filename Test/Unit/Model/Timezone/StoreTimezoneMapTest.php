<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Timezone;

use DateTimeImmutable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StoreTimezoneMap::class)]
final class StoreTimezoneMapTest extends TestCase
{
    /**
     * @param array<int,string> $storeTimezones store id => timezone (website id equals store id)
     * @param string $defaultTimezone Timezone configured at default scope
     */
    private function map(array $storeTimezones, string $defaultTimezone = 'UTC'): StoreTimezoneMap
    {
        $stores = [];
        foreach (array_keys($storeTimezones) as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getWebsiteId')->willReturn($id);
            $stores[$id] = $store;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(static fn ($id): StoreInterface => $stores[(int)$id]);
        $storeManager->method('getStores')->willReturn($stores);
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            static fn ($path, $scope = 'default', $scopeId = null) => match (true) {
                $path !== 'general/locale/timezone' => null,
                $scope === ScopeInterface::SCOPE_WEBSITES => $storeTimezones[(int)$scopeId],
                $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT => $defaultTimezone,
                default => null,
            }
        );

        return new StoreTimezoneMap($storeManager, $config);
    }

    private function range(string $from, string $to): ResolvedDateRange
    {
        $a = new DateTimeImmutable($from);
        $b = new DateTimeImmutable($to);

        return new ResolvedDateRange($a, $b, false, (int)$a->diff($b)->days + 1);
    }

    public function testItResolvesStoreTimezoneFromItsWebsiteScope(): void
    {
        $map = $this->map([1 => 'Europe/Rome', 2 => 'America/New_York']);

        self::assertSame('America/New_York', $map->getTimezone(2));
        self::assertSame([1 => 'Europe/Rome', 2 => 'America/New_York'], $map->getTimezonesForStores([]));
        self::assertSame([2 => 'America/New_York'], $map->getTimezonesForStores([2]));
    }

    public function testItDetectsMultipleTimezonesAmongSelectedStores(): void
    {
        $map = $this->map([1 => 'Europe/Rome', 2 => 'Europe/Rome', 3 => 'Asia/Tokyo']);

        self::assertFalse($map->hasMultipleTimezones([1, 2]));
        self::assertTrue($map->hasMultipleTimezones([1, 3]));
        self::assertTrue($map->hasMultipleTimezones([]));
    }

    public function testItConvertsASingleTimezoneLocalRangeIntoAUtcRange(): void
    {
        $map = $this->map([1 => 'Europe/Rome']);

        self::assertSame(
            ['from' => '2025-01-09 23:00:00', 'to' => '2025-01-11 23:00:00'],
            $map->toUtcRange($this->range('2025-01-10', '2025-01-11'), [1])
        );
    }

    public function testItWidensTheUtcRangeToCoverStoresInDifferentTimezones(): void
    {
        $map = $this->map([1 => 'Europe/Rome', 2 => 'America/New_York']);

        self::assertSame(
            ['from' => '2025-01-09 23:00:00', 'to' => '2025-01-11 05:00:00'],
            $map->toUtcRange($this->range('2025-01-10', '2025-01-10'), [1, 2])
        );
    }

    public function testItReturnsOneExactUtcRangePerTimezoneGroup(): void
    {
        $map = $this->map([1 => 'Europe/Rome', 2 => 'America/New_York', 3 => 'Europe/Rome']);

        self::assertSame(
            [
                ['timezone' => 'Europe/Rome', 'storeIds' => [1, 3], 'from' => '2025-01-09 23:00:00', 'to' => '2025-01-10 23:00:00'],
                ['timezone' => 'America/New_York', 'storeIds' => [2], 'from' => '2025-01-10 05:00:00', 'to' => '2025-01-11 05:00:00'],
            ],
            $map->toUtcRangesByTimezone($this->range('2025-01-10', '2025-01-10'), [1, 2, 3])
        );
    }

    public function testItConvertsRangesSpanningADstChangeCorrectly(): void
    {
        $map = $this->map([1 => 'Europe/Rome']);

        // DST starts 2025-03-30 in Europe/Rome: start is UTC+1, end (exclusive, 04-01) is UTC+2.
        self::assertSame(
            ['from' => '2025-03-28 23:00:00', 'to' => '2025-03-31 22:00:00'],
            $map->toUtcRange($this->range('2025-03-29', '2025-03-31'), [1])
        );
    }

    public function testItEndsTheLastDayOfARangeAtTheNextLocalMidnightWhenDstStartsAtMidnight(): void
    {
        $map = $this->map([1 => 'America/Santiago']);

        // Santiago: 2025-09-07 00:00 does not exist (clocks jump to 01:00, UTC-4 -> UTC-3).
        // The last day (09-06) must end at the next local midnight 09-08 00:00 (-03) = 03:00 UTC.
        self::assertSame(
            ['from' => '2025-09-06 04:00:00', 'to' => '2025-09-08 03:00:00'],
            $map->toUtcRange($this->range('2025-09-06', '2025-09-07'), [1])
        );
    }

    public function testItStartsARangeAt0100LocalTimeWhenTheLocalMidnightDoesNotExist(): void
    {
        $map = $this->map([1 => 'America/Santiago']);

        // 2025-09-07 01:00 -03 = 04:00 UTC
        self::assertSame(
            ['from' => '2025-09-07 04:00:00', 'to' => '2025-09-08 03:00:00'],
            $map->toUtcRange($this->range('2025-09-07', '2025-09-07'), [1])
        );
    }

    public function testItResolvesTheDefaultScopeTimezone(): void
    {
        $map = $this->map([1 => 'Europe/Rome'], 'America/New_York');

        self::assertSame('America/New_York', $map->getDefaultTimezone());
    }

    public function testItWidensTheAllStoresUtcRangeToCoverTheDefaultTimezone(): void
    {
        $map = $this->map([1 => 'Europe/Rome', 2 => 'UTC'], 'Pacific/Auckland');

        // Auckland (+12 in January) starts earliest: 2025-01-09 11:00 UTC
        self::assertSame(
            ['from' => '2025-01-09 11:00:00', 'to' => '2025-01-11 00:00:00'],
            $map->toUtcRange($this->range('2025-01-10', '2025-01-10'), [])
        );
        self::assertSame(
            ['from' => '2025-01-09 23:00:00', 'to' => '2025-01-11 00:00:00'],
            $map->toUtcRange($this->range('2025-01-10', '2025-01-10'), [1, 2])
        );
    }

    public function testItConvertsTheRangeInTheDefaultTimezone(): void
    {
        $map = $this->map([1 => 'Europe/Rome'], 'America/New_York');

        self::assertSame(
            ['from' => '2025-01-10 05:00:00', 'to' => '2025-01-11 05:00:00'],
            $map->toUtcRangeInDefaultTimezone($this->range('2025-01-10', '2025-01-10'))
        );
    }

    public function testItRequiresQuarterHourSlotsOnlyWhenARangeOffsetIsNotWholeHours(): void
    {
        $map = $this->map([1 => 'Europe/Rome', 2 => 'Asia/Kolkata', 3 => 'Australia/Lord_Howe']);
        $range = $this->range('2025-01-10', '2025-01-12');

        self::assertFalse($map->requiresQuarterHourSlots([1], $range));
        self::assertTrue($map->requiresQuarterHourSlots([1, 2], $range));
        self::assertFalse($map->requiresQuarterHourSlots([3], $range));
        self::assertTrue($map->requiresQuarterHourSlots([3], $this->range('2025-04-01', '2025-04-10')));
        self::assertFalse($map->requiresQuarterHourSlots([1], null));
        self::assertTrue($map->requiresQuarterHourSlots([2], null));
    }
}
