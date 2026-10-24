<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Benchmark;

use DateTimeImmutable;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AdvancedReport\Model\Benchmark\Scenario;
use MageOS\AdvancedReport\Model\Benchmark\ScenarioProvider;
use MageOS\AdvancedReport\Model\Query\ClockInterface;
use MageOS\AdvancedReport\Model\Query\CompareMode;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\ReportScope;
use MageOS\AdvancedReport\Model\Query\ReportScopeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScenarioProvider::class)]
#[CoversClass(Scenario::class)]
final class ScenarioProviderTest extends TestCase
{
    public function testItBuildsTheDefaultScenarioMatrixFromTheConfiguredPresets(): void
    {
        $scenarios = $this->provider()->getScenarios();

        $codes = array_map(static fn (Scenario $scenario): string => $scenario->code, $scenarios);
        self::assertSame(
            [
                'sales_overview:last_30_days:all_stores:none',
                'sales_overview:last_30_days:all_stores:previous_period',
                'sales_overview:last_30_days:store_3:none',
                'sales_overview:last_30_days:store_3:previous_period',
                'sales_overview:last_12_months:all_stores:none',
                'sales_overview:last_12_months:all_stores:previous_period',
                'sales_overview:last_12_months:store_3:none',
                'sales_overview:last_12_months:store_3:previous_period',
                'stock_value:all_stores',
                'stock_value:store_3',
                'new_vs_returning_12m',
            ],
            $codes,
        );
        $first = $scenarios[1];
        self::assertSame('sales_overview', $first->preset);
        self::assertSame([], $first->storeIds);
        self::assertSame(CompareMode::PREVIOUS_PERIOD, $first->compareTo);
        self::assertSame([3], $scenarios[2]->storeIds);
        self::assertSame(['dataset' => 'sales_orders'], $scenarios[10]->request);
    }

    public function testItBuildsTheLast12MonthsScenarioAsACustomDateRange(): void
    {
        $scenarios = $this->provider()->getScenarios();

        $thirty = $scenarios[0]->dateRange;
        self::assertSame(DateRangeType::LAST_30_DAYS, $thirty->type);
        $year = $scenarios[4]->dateRange;
        self::assertSame(DateRangeType::CUSTOM, $year->type);
        self::assertSame('2025-03-16', $year->from);
        self::assertSame('2026-03-15', $year->to);
    }

    private function provider(): ScenarioProvider
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([3 => $store, 5 => $this->createStub(StoreInterface::class)]);
        $scopeResolver = $this->createStub(ReportScopeResolver::class);
        $scopeResolver->method('resolve')->willReturn(new ReportScope(1, 'Europe/Rome', 1, ['Europe/Rome']));
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('today')->willReturn(new DateTimeImmutable('2026-03-15'));

        return new ScenarioProvider(
            $storeManager,
            $scopeResolver,
            $clock,
            ['sales_overview'],
            ['stock_value'],
            ['last_30_days', 'last_12_months'],
            [
                'new_vs_returning_12m' => [
                    'request' => ['dataset' => 'sales_orders'],
                    'dateRange' => 'last_12_months',
                ],
            ],
        );
    }
}
