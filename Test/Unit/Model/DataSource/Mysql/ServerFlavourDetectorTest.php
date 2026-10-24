<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavour;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavourDetector;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServerFlavourDetector::class)]
#[AllowMockObjectsWithoutExpectations]
final class ServerFlavourDetectorTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:ServerFlavour}>
     */
    public static function versionProvider(): array
    {
        return [
            'mariadb' => ['10.11.6-MariaDB-1:10.11.6+maria~ubu2204', ServerFlavour::MariaDb],
            'mysql' => ['8.4.4', ServerFlavour::Mysql],
        ];
    }

    #[DataProvider('versionProvider')]
    public function testItDetectsMariaDbAndMysqlFromTheServerVersion(string $version, ServerFlavour $expected): void
    {
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('fetchOne')->willReturn($version);
        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('load')->willReturn(false);

        self::assertSame($expected, $this->buildDetector($adapter, $cache)->detect());
    }

    public function testItUsesTheCachedFlavourWithoutQueryingTheServer(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects(self::never())->method('fetchOne');
        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('load')->willReturn('mariadb');

        self::assertSame(ServerFlavour::MariaDb, $this->buildDetector($adapter, $cache)->detect());
    }

    public function testItCachesTheDetectedFlavour(): void
    {
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('fetchOne')->willReturn('8.4.4');
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects(self::once())->method('save')->with('mysql', self::anything());

        $this->buildDetector($adapter, $cache)->detect();
    }

    private function buildDetector(AdapterInterface $adapter, FrontendInterface $cache): ServerFlavourDetector
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($adapter);
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn('default');

        return new ServerFlavourDetector(new ReportConnection($resource, $config), $cache);
    }
}
