<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneSupportDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimezoneSupportDetector::class)]
final class TimezoneSupportDetectorTest extends TestCase
{
    public function testItReportsConvertTzStrategyWhenTheDatabaseConvertsNamedTimezones(): void
    {
        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('load')->willReturn(false);

        self::assertSame('convert_tz', $this->build('1', $cache)->detect());
    }

    public function testItReportsTheFallbackStrategyWhenConversionReturnsNull(): void
    {
        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('load')->willReturn(false);

        self::assertSame('utc_hour_fallback', $this->build('0', $cache)->detect());
    }

    public function testItCachesTheDetectionResult(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects(self::once())->method('save')
            ->with('convert_tz', self::anything(), self::anything(), 86400);

        $this->build('1', $cache)->detect();
    }

    public function testItUsesTheCachedResultWithoutQueryingTheServer(): void
    {
        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('load')->willReturn('utc_hour_fallback');

        self::assertSame('utc_hour_fallback', $this->build('1', $cache, true)->detect());
    }

    private function build(string $value, FrontendInterface $cache, bool $neverQuery = false): TimezoneSupportDetector
    {
        if ($neverQuery) {
            $adapter = $this->createMock(AdapterInterface::class);
            $adapter->expects(self::never())->method('fetchOne');
        } else {
            $adapter = $this->createStub(AdapterInterface::class);
            $adapter->method('fetchOne')->willReturn($value);
        }
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($adapter);
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn('default');

        return new TimezoneSupportDetector($resource, $config, $cache);
    }
}
