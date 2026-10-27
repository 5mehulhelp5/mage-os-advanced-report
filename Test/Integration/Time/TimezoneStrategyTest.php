<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Time;

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneSupportDetector;
use PHPUnit\Framework\TestCase;

/**
 * The timezone strategy follows what the server can actually convert (loaded timezone tables), never the server
 * flavour: MySQL and MariaDB images both may or may not ship the tables.
 */
#[AppIsolation(true)]
final class TimezoneStrategyTest extends TestCase
{
    public function testItSelectsTheTimezoneStrategyFromDetectedTimezoneSupportNotFromTheFlavour(): void
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $supported = (int) $connection->fetchOne(
            "SELECT CONVERT_TZ('2000-01-01 00:00:00','UTC','Europe/Rome') IS NOT NULL"
        ) === 1;

        $this->assertSame(
            $supported ? TimezoneSupportDetector::STRATEGY_CONVERT_TZ : TimezoneSupportDetector::STRATEGY_UTC_HOUR_FALLBACK,
            Bootstrap::getObjectManager()->get(TimezoneSupportDetector::class)->detect()
        );
    }
}
