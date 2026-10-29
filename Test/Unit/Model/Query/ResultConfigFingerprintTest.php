<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Query\ResultConfigFingerprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResultConfigFingerprint::class)]
final class ResultConfigFingerprintTest extends TestCase
{
    public function testItIncludesAbandonedAfterHoursAndRecentCartHoursInTheResultConfigFingerprint(): void
    {
        $fingerprint = $this->buildFingerprint(['color', 'brand'], '2.50');

        self::assertSame(
            [
                'reportAttributes' => ['brand', 'color'],
                'deadStockDays' => 90,
                'maxRows' => 500,
                'connectionName' => 'default',
                'minQty' => '2.5',
                'abandonedAfterHours' => 24,
                'recentCartHours' => 48,
            ],
            $fingerprint->get(),
        );
    }

    public function testItReturnsTheSameFingerprintWhenReportAttributesAreConfiguredInADifferentOrder(): void
    {
        self::assertSame(
            $this->buildFingerprint(['brand', 'color'], '0')->get(),
            $this->buildFingerprint(['color', 'brand'], '0')->get(),
        );
    }

    public function testItKeepsTheFingerprintUnchangedWhenOnlyTheQueryTimeoutOrCacheTtlChanges(): void
    {
        self::assertSame(
            $this->buildFingerprint(['brand'], '0', 1000, 60, 600)->get(),
            $this->buildFingerprint(['brand'], '0', 9000, 5, 50)->get(),
        );
    }

    /**
     * @param string[] $attributes
     */
    private function buildFingerprint(
        array $attributes,
        string $minQty,
        int $timeoutMs = 1000,
        int $ttlOpen = 60,
        int $ttlClosed = 600,
    ): ResultConfigFingerprint {
        $config = $this->createStub(Config::class);
        $config->method('getReportAttributes')->willReturn($attributes);
        $config->method('getDeadStockDays')->willReturn(90);
        $config->method('getAbandonedAfterHours')->willReturn(24);
        $config->method('getRecentCartHours')->willReturn(48);
        $config->method('getMaxRows')->willReturn(500);
        $config->method('getConnectionName')->willReturn('default');
        $config->method('getQueryTimeoutMs')->willReturn($timeoutMs);
        $config->method('getCacheTtlOpenPeriod')->willReturn($ttlOpen);
        $config->method('getCacheTtlClosedPeriod')->willReturn($ttlClosed);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([['cataloginventory/item_options/min_qty', 'default', null, $minQty]]);

        return new ResultConfigFingerprint($config, $scopeConfig);
    }
}
