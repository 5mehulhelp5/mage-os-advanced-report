<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\ViewModel;

use Magento\Framework\AuthorizationInterface;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\DataSourceInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavour;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavourDetector;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneStrategy;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\SourceResolver;
use MageOS\AdvancedReport\ViewModel\Diagnostics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Diagnostics::class)]
final class DiagnosticsTest extends TestCase
{
    public function testItExposesDiagnosticsOnlyToAdminsWithConfigPermission(): void
    {
        $allowed = $this->build(true);
        $denied = $this->build(false);

        self::assertTrue($allowed->isVisible());
        self::assertSame(
            [
                'Active source: sales_orders' => 'mysql',
                'Timezone strategy' => 'convert_tz',
                'Server flavour' => 'mariadb',
                'Max rows' => '100',
                'Query timeout (ms)' => '2000',
            ],
            $allowed->getRows()
        );
        self::assertFalse($denied->isVisible());
        self::assertSame([], $denied->getRows());
    }

    private function build(bool $isAllowed): Diagnostics
    {
        $dataset = new Dataset(
            'sales_orders',
            'Orders',
            'a',
            'sales',
            false,
            null,
            null,
            null,
            [],
            [],
            [],
            ['m' => new Metric('m', 'M', null, 'number', false, false, AggregateFunction::SUM)],
            [],
        );
        $registry = $this->createStub(DefinitionRegistry::class);
        $registry->method('getDatasets')->willReturn([$dataset]);
        $registry->method('getDataset')->willReturn($dataset);
        $source = $this->createStub(DataSourceInterface::class);
        $source->method('getCode')->willReturn('mysql');
        $resolver = $this->createStub(SourceResolver::class);
        $resolver->method('resolve')->willReturn($source);
        $factory = $this->createStub(ReportRequestFactory::class);
        $factory->method('fromArray')->willReturn($this->createStub(ReportRequestInterface::class));
        $timezone = $this->createStub(TimezoneStrategy::class);
        $timezone->method('getName')->willReturn('convert_tz');
        $flavour = $this->createStub(ServerFlavourDetector::class);
        $flavour->method('detect')->willReturn(ServerFlavour::MariaDb);
        $config = $this->createStub(Config::class);
        $config->method('getMaxRows')->willReturn(100);
        $config->method('getQueryTimeoutMs')->willReturn(2000);
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn($isAllowed);

        return new Diagnostics($registry, $resolver, $factory, $timezone, $flavour, $config, $authorization);
    }
}
