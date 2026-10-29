<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\AdvancedReport\Model\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    public function testItReportsEnabledFlagFromGeneralEnabled(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('isSetFlag')
            ->with('mageos_advancedreport/general/enabled')
            ->willReturn(true);

        $config = new Config($scopeConfig);

        self::assertTrue($config->isEnabled());
    }

    public function testItReturnsAnEmptyCountedStatusListWhenConfigIsEmptyMeaningAllExceptCanceled(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('getValue')
            ->with('mageos_advancedreport/sales/counted_statuses')
            ->willReturn('');

        $config = new Config($scopeConfig);

        self::assertSame([], $config->getCountedStatuses());
    }

    public function testItReturnsConfiguredCountedStatusesAsATrimmedArrayWithoutEmptyValues(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('getValue')
            ->with('mageos_advancedreport/sales/counted_statuses')
            ->willReturn('processing, complete ,, pending');

        $config = new Config($scopeConfig);

        self::assertSame(['processing', 'complete', 'pending'], $config->getCountedStatuses());
    }

    public function testItReturnsReportAttributeCodesAsAnArrayAndEmptyArrayWhenUnset(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::exactly(2))
            ->method('getValue')
            ->with('mageos_advancedreport/catalog/report_attributes')
            ->willReturnOnConsecutiveCalls('color,size', '');

        $config = new Config($scopeConfig);

        self::assertSame(['color', 'size'], $config->getReportAttributes());
        self::assertSame([], $config->getReportAttributes());
    }

    /**
     * @param 'getMaxRows'|'getQueryTimeoutMs'|'getCacheTtlOpenPeriod'|'getCacheTtlClosedPeriod' $method
     */
    #[DataProvider('performanceNumericSettingsProvider')]
    public function testItCastsNumericPerformanceSettingsToPositiveIntegersFallingBackToDefaultsOnInvalidValues(
        string $path,
        string $method,
        int $default,
    ): void {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::exactly(4))
            ->method('getValue')
            ->with($path)
            ->willReturnOnConsecutiveCalls('42', 'not-a-number', '-5', '0');

        $config = new Config($scopeConfig);

        $results = [$config->$method(), $config->$method(), $config->$method(), $config->$method()];

        self::assertSame([42, $default, $default, $default], $results);
    }

    /**
     * @return array<string,array{string,string,int}>
     */
    public static function performanceNumericSettingsProvider(): array
    {
        return [
            'max_rows' => ['mageos_advancedreport/performance/max_rows', 'getMaxRows', 10000],
            'query_timeout_ms' => ['mageos_advancedreport/performance/query_timeout_ms', 'getQueryTimeoutMs', 30000],
            'cache_ttl_open_period' => [
                'mageos_advancedreport/performance/cache_ttl_open_period',
                'getCacheTtlOpenPeriod',
                300,
            ],
            'cache_ttl_closed_period' => [
                'mageos_advancedreport/performance/cache_ttl_closed_period',
                'getCacheTtlClosedPeriod',
                86400,
            ],
        ];
    }

    public function testItReturnsTheConfiguredAbandonedAfterHours(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('48');

        self::assertSame(48, (new Config($scopeConfig))->getAbandonedAfterHours());
    }

    #[DataProvider('invalidHoursProvider')]
    public function testItFallsBackTo24AbandonedAfterHoursWhenTheValueIsEmptyOrNotPositive(mixed $value): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($value);

        self::assertSame(24, (new Config($scopeConfig))->getAbandonedAfterHours());
    }

    public function testItReturnsTheConfiguredRecentCartHours(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('72');

        self::assertSame(72, (new Config($scopeConfig))->getRecentCartHours());
    }

    #[DataProvider('invalidHoursProvider')]
    public function testItFallsBackTo24RecentCartHoursWhenTheValueIsEmptyOrNotPositive(mixed $value): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($value);

        self::assertSame(24, (new Config($scopeConfig))->getRecentCartHours());
    }

    public function testItCapsRecentCartHoursAt120(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('168');

        self::assertSame(120, (new Config($scopeConfig))->getRecentCartHours());
    }

    /**
     * @return array<string,array{mixed}>
     */
    public static function invalidHoursProvider(): array
    {
        return ['empty' => [''], 'null' => [null], 'zero' => ['0'], 'negative' => ['-5'], 'text' => ['abc']];
    }

    public function testItReturnsTheConfiguredConnectionNameDefaultingToDefault(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::exactly(2))
            ->method('getValue')
            ->with('mageos_advancedreport/performance/connection_name')
            ->willReturnOnConsecutiveCalls('checkout', '');

        $config = new Config($scopeConfig);

        self::assertSame('checkout', $config->getConnectionName());
        self::assertSame('default', $config->getConnectionName());
    }

    public function testItReadsFirstDayOfWeekFromStoreScope(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('getValue')
            ->with('general/locale/firstday', ScopeInterface::SCOPE_STORE, 7)
            ->willReturn('1');

        $config = new Config($scopeConfig);

        self::assertSame(1, $config->getFirstDayOfWeek(7));
    }
}
