<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneStrategy;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneSupportDetector;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimezoneStrategy::class)]
final class TimezoneStrategyTest extends TestCase
{
    public function testItBuildsASingleTimezoneLocalDatetimeExpression(): void
    {
        $strategy = $this->build([1 => 'Europe/Rome', 2 => 'Europe/Rome']);

        self::assertSame(
            "CONVERT_TZ(main.created_at, 'UTC', 'Europe/Rome')",
            $strategy->localDateTime('main.created_at', 'main.store_id', [1, 2])
        );
    }

    public function testItBuildsAPerStoreCaseExpressionForMultipleTimezones(): void
    {
        $strategy = $this->build([1 => 'Europe/Rome', 2 => 'America/New_York']);

        self::assertSame(
            "CASE main.store_id WHEN 1 THEN CONVERT_TZ(main.created_at, 'UTC', 'Europe/Rome')"
            . " WHEN 2 THEN CONVERT_TZ(main.created_at, 'UTC', 'America/New_York') END",
            $strategy->localDateTime('main.created_at', 'main.store_id', [1, 2])
        );
    }

    public function testItUsesTheGivenDatetimeAndStoreExpressionsAndInlinesQuotedTimezoneNames(): void
    {
        $strategy = $this->build([3 => 'Europe/Rome', 4 => 'Asia/Tokyo']);
        $sql = $strategy->localDateTime('order.created_at', 'order.store_id', [3, 4]);

        self::assertStringContainsString('CASE order.store_id', $sql);
        self::assertStringContainsString("CONVERT_TZ(order.created_at, 'UTC', 'Asia/Tokyo')", $sql);
        self::assertStringNotContainsString('main.', $sql);
        self::assertStringNotContainsString(':', $sql);
    }

    public function testItBuildsWeekBucketsHonoringTheConfiguredFirstDayOfWeek(): void
    {
        $strategy = $this->build([1 => 'UTC'], '1');
        $x = "CONVERT_TZ(c, 'UTC', 'UTC')";

        self::assertSame(
            "DATE_SUB(DATE($x), INTERVAL (DAYOFWEEK($x) - 1 - 1 + 7) % 7 DAY)",
            $strategy->bucket('week', 'c', 's', [1])
        );
    }

    public function testItBuildsHourDayMonthYearHourOfDayAndWeekdayBucketExpressions(): void
    {
        $strategy = $this->build([1 => 'UTC']);
        $x = "CONVERT_TZ(c, 'UTC', 'UTC')";

        self::assertSame("DATE_FORMAT($x, '%Y-%m-%d %H:00')", $strategy->bucket('hour', 'c', 's', [1]));
        self::assertSame("DATE($x)", $strategy->bucket('day', 'c', 's', [1]));
        self::assertSame("DATE_FORMAT($x, '%Y-%m')", $strategy->bucket('month', 'c', 's', [1]));
        self::assertSame("YEAR($x)", $strategy->bucket('year', 'c', 's', [1]));
        self::assertSame("HOUR($x)", $strategy->bucket('hour_of_day', 'c', 's', [1]));
        self::assertSame("(DAYOFWEEK($x) - 1)", $strategy->bucket('weekday', 'c', 's', [1]));
        self::assertSame("DATE_FORMAT(c, '%Y-%m-%d %H:00:00')", $strategy->utcHour('c'));
    }

    /**
     * @param array<int,string> $timezones
     */
    private function build(array $timezones, string $firstDay = '0'): TimezoneStrategy
    {
        $map = $this->createStub(StoreTimezoneMap::class);
        $map->method('getTimezonesForStores')->willReturn($timezones);
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('quote')->willReturnCallback(static fn (string $v): string => "'" . $v . "'");
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($adapter);
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn('default');
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($firstDay);

        return new TimezoneStrategy(
            $map,
            $this->createStub(TimezoneSupportDetector::class),
            $resource,
            $config,
            $scopeConfig
        );
    }
}
