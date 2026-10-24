<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Console\Command\ExplainCommand;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\DataSource\Mysql\SelectBuilder;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavour;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavourDetector;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneStrategy;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneSupportDetector;
use MageOS\AdvancedReport\Model\DataSource\Mysql\UtcHourFallbackSelect;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Preset\AbstractStockAntiJoinQuery;
use MageOS\AdvancedReport\Model\Preset\PresetQueryPool;
use MageOS\AdvancedReport\Model\Query\MonetaryRequestDetector;
use MageOS\AdvancedReport\Model\Query\PresetRequestBuilder;
use MageOS\AdvancedReport\Model\Query\QueryPlanner;
use MageOS\AdvancedReport\Model\Query\ReportScope;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ExplainCommand::class)]
#[AllowMockObjectsWithoutExpectations]
final class ExplainCommandTest extends TestCase
{
    private DefinitionRegistry&MockObject $registry;
    private PresetRequestBuilder&MockObject $requestBuilder;
    private QueryPlanner&MockObject $planner;
    private SelectBuilder&MockObject $selectBuilder;
    private AdapterInterface&MockObject $connection;
    private TimezoneStrategy&MockObject $timezoneStrategy;
    private MonetaryRequestDetector&MockObject $monetaryDetector;
    private ServerFlavourDetector&MockObject $flavourDetector;
    private Config&MockObject $config;
    private ResourceConnection&MockObject $resource;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(DefinitionRegistry::class);
        $this->requestBuilder = $this->createMock(PresetRequestBuilder::class);
        $this->planner = $this->createMock(QueryPlanner::class);
        $this->selectBuilder = $this->createMock(SelectBuilder::class);
        $this->connection = $this->createMock(AdapterInterface::class);

        $this->timezoneStrategy = $this->createMock(TimezoneStrategy::class);
        $this->monetaryDetector = $this->createMock(MonetaryRequestDetector::class);
        $this->flavourDetector = $this->createMock(ServerFlavourDetector::class);
        $this->flavourDetector->method('detect')->willReturn(ServerFlavour::Mysql);
        $config = $this->createMock(Config::class);
        $config->method('getConnectionName')->willReturn('report');
        $config->method('getQueryTimeoutMs')->willReturn(30000);
        $this->config = $config;
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($this->connection);

        $this->resource = $resource;
        $this->tester = $this->makeTester(new PresetQueryPool());
    }

    private function makeTester(PresetQueryPool $pool): CommandTester
    {
        return new CommandTester(new ExplainCommand(
            $this->createStub(State::class),
            $this->registry,
            $this->requestBuilder,
            $this->planner,
            $this->selectBuilder,
            new ReportConnection($this->resource, $this->config),
            $pool,
            $this->timezoneStrategy,
            $this->monetaryDetector,
            $this->flavourDetector,
            $this->config,
        ));
    }

    public function testItPrintsSqlAndExplainRowsForASinglePreset(): void
    {
        $this->arrangePreset('sales_over_time');
        $this->connection->expects(self::exactly(3))
            ->method('fetchAll')
            ->with(self::stringStartsWith('EXPLAIN SELECT'), [':a' => 5])
            ->willReturn([['id' => '1', 'key' => 'IDX_CREATED_AT']]);

        $exit = $this->tester->execute(['--preset' => 'sales_over_time', '--from' => '2026-01-01', '--to' => '2026-01-31']);

        $display = $this->tester->getDisplay();
        self::assertSame(0, $exit);
        self::assertStringContainsString('sales_over_time', $display);
        self::assertStringContainsString('SELECT PAGE WHERE a = :a', $display);
        self::assertStringContainsString('IDX_CREATED_AT', $display);
    }

    public function testItIteratesAllPresetsWhenNoPresetOptionIsGiven(): void
    {
        $this->registry->method('getPresets')->willReturn([
            'one' => $this->preset('one'),
            'two' => $this->preset('two'),
        ]);
        $this->registry->method('getPreset')->willReturnCallback(fn (string $c): Preset => $this->preset($c));
        $this->arrangeQueries();
        $this->connection->expects(self::exactly(6))->method('fetchAll')->willReturn([['id' => '1']]);

        $exit = $this->tester->execute(['--from' => '2026-01-01', '--to' => '2026-01-31']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('one', $this->tester->getDisplay());
        self::assertStringContainsString('two', $this->tester->getDisplay());
    }

    public function testItFailsWithExitCode1OnUnknownPreset(): void
    {
        $this->registry->method('getPreset')->willThrowException(
            new NoSuchDefinitionException(__('Unknown'))
        );

        $exit = $this->tester->execute(['--preset' => 'nope', '--from' => '2026-01-01', '--to' => '2026-01-31']);

        self::assertSame(1, $exit);
    }

    public function testItFailsWithExitCode1OnInvalidDates(): void
    {
        $this->registry->expects(self::never())->method('getPreset');

        self::assertSame(1, $this->tester->execute(['--preset' => 'x', '--from' => '2026-13-01', '--to' => '2026-01-31']));
        self::assertSame(1, $this->tester->execute(['--preset' => 'x', '--from' => '2026-02-01', '--to' => '2026-01-31']));
        self::assertSame(1, $this->tester->execute(['--preset' => 'x']));
    }

    public function testItExplainsThePageRowCountAndTotalsStatementsOfAPreset(): void
    {
        $this->arrangePreset('sales_over_time');
        $sql = $this->captureFetchAll();

        $this->tester->execute(['--preset' => 'sales_over_time', '--from' => '2026-01-01', '--to' => '2026-01-31']);

        self::assertSame([
            'EXPLAIN SELECT PAGE WHERE a = :a',
            'EXPLAIN SELECT ROWCOUNT WHERE a = :a',
            'EXPLAIN SELECT TOTALS WHERE a = :a',
        ], $sql->getArrayCopy());
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('page', $display);
        self::assertStringContainsString('row_count (only when the page is full)', $display);
        self::assertStringContainsString('totals', $display);
    }

    public function testItExplainsTheComparisonPageAndTotalsWhenThePresetComparesPeriods(): void
    {
        $this->registry->method('getPreset')->willReturn($this->preset('sales_over_time'));
        $this->arrangeQueries($this->resolvedQuery());
        $sql = $this->captureFetchAll();

        $this->tester->execute(['--preset' => 'sales_over_time', '--from' => '2026-01-01', '--to' => '2026-01-31']);

        self::assertContains('EXPLAIN SELECT CMP PAGE WHERE a = :a', $sql->getArrayCopy());
        self::assertContains('EXPLAIN SELECT CMP TOTALS WHERE a = :a', $sql->getArrayCopy());
        self::assertStringContainsString('comparison_page', $this->tester->getDisplay());
        self::assertStringContainsString('comparison_totals', $this->tester->getDisplay());
    }

    public function testItOverridesTheComparisonModeWithTheCompareOption(): void
    {
        $this->registry->method('getPreset')->willReturn($this->preset('sales_over_time'));
        $this->planner->method('plan')->willReturn(['main' => $this->resolvedQuery(), 'comparison' => null]);
        $this->selectBuilder->method('buildPage')->willReturn($this->select('PAGE'));
        $this->selectBuilder->method('buildTotals')->willReturn($this->select('TOTALS'));
        $this->selectBuilder->method('buildRowCount')->willReturn($this->select('ROWCOUNT'));
        $this->connection->method('fetchAll')->willReturn([]);
        $this->requestBuilder->expects(self::once())
            ->method('build')
            ->with(
                self::anything(),
                ['dateRange' => ['type' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'], 'compareTo' => 'previous_year']
            )
            ->willReturn($this->createStub(ReportRequestInterface::class));

        $this->tester->execute([
            '--preset' => 'x',
            '--from' => '2026-01-01',
            '--to' => '2026-01-31',
            '--compare' => 'previous_year',
        ]);
    }

    public function testItExplainsTheCurrencyProbeForMonetaryRequests(): void
    {
        $this->registry->method('getPreset')->willReturn($this->preset('sales_over_time'));
        $dataset = new Dataset(
            'sales_orders',
            'Orders',
            'acl',
            'sales',
            false,
            null,
            null,
            'provider',
            [],
            [Dataset::FIELD_BASE_CURRENCY => new Field(Dataset::FIELD_BASE_CURRENCY, 'Currency', FieldType::STRING)],
            [],
            [],
            []
        );
        $this->arrangeQueries(null, $dataset);
        $this->monetaryDetector->method('isMonetary')->willReturn(true);
        $sql = $this->captureFetchAll();

        $this->tester->execute(['--preset' => 'sales_over_time', '--from' => '2026-01-01', '--to' => '2026-01-31']);

        self::assertContains('EXPLAIN SELECT PROBE WHERE a = :a', $sql->getArrayCopy());
        self::assertStringContainsString('currency_probe', $this->tester->getDisplay());
    }

    public function testItExplainsTheFallbackSelectInsteadOfThePageWhenTimezoneTablesAreMissing(): void
    {
        $this->registry->method('getPreset')->willReturn($this->preset('sales_over_time'));
        $this->arrangeQueries();
        $this->timezoneStrategy->method('getName')->willReturn(TimezoneSupportDetector::STRATEGY_UTC_HOUR_FALLBACK);
        $this->selectBuilder->method('hasDateBucketDimension')->willReturn(true);
        $this->selectBuilder->method('buildUtcHourFallback')->willReturn(
            new UtcHourFallbackSelect($this->select('FALLBACK'), [], 10, 'h', null, false, 60)
        );
        $sql = $this->captureFetchAll();

        $this->tester->execute(['--preset' => 'sales_over_time', '--from' => '2026-01-01', '--to' => '2026-01-31']);

        self::assertSame([
            'EXPLAIN SELECT FALLBACK WHERE a = :a',
            'EXPLAIN SELECT TOTALS WHERE a = :a',
        ], $sql->getArrayCopy());
        self::assertStringContainsString('fallback', $this->tester->getDisplay());
    }

    public function testItPrefixesStatementsWithExplainAnalyzeWhenTheAnalyzeOptionIsSet(): void
    {
        $this->arrangePreset('sales_over_time');
        $sql = $this->captureFetchAll();

        $this->tester->execute([
            '--preset' => 'sales_over_time',
            '--from' => '2026-01-01',
            '--to' => '2026-01-31',
            '--analyze' => true,
        ]);

        self::assertSame('EXPLAIN ANALYZE SELECT PAGE WHERE a = :a', $sql[0]);
    }

    public function testItPrintsThePlansAsJsonWhenTheJsonFormatIsRequested(): void
    {
        $this->arrangePreset('sales_over_time');
        $this->connection->method('fetchAll')->willReturn([['id' => '1', 'key' => 'IDX']]);

        $this->tester->execute([
            '--preset' => 'sales_over_time',
            '--from' => '2026-01-01',
            '--to' => '2026-01-31',
            '--format' => 'json',
        ]);

        $decoded = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['page', 'row_count', 'totals'], array_column($decoded, 'statement'));
        self::assertSame('sales_over_time', $decoded[0]['preset']);
        self::assertSame('SELECT PAGE WHERE a = :a', $decoded[0]['sql']);
        self::assertSame([['id' => '1', 'key' => 'IDX']], $decoded[0]['plan']);
    }

    public function testItExplainsTheSelectOfAStockAntiJoinPresetQuery(): void
    {
        $this->registry->method('getPreset')->willReturn(
            new Preset('dead_stock', 'catalog', 'Dead', 'stock', ['qty'], [], [], [], 'none', null, ['mysql'], 'DeadStockQuery')
        );
        $main = $this->arrangeQueries();
        $presetQuery = $this->createMock(AbstractStockAntiJoinQuery::class);
        $presetQuery->expects(self::once())->method('getSelect')->with($main)->willReturn($this->select('STOCK'));
        $sql = $this->captureFetchAll();
        $tester = $this->makeTester(new PresetQueryPool(['DeadStockQuery' => $presetQuery]));

        $exit = $tester->execute(['--preset' => 'dead_stock', '--from' => '2026-01-01', '--to' => '2026-01-31']);

        self::assertSame(0, $exit);
        self::assertSame(['EXPLAIN SELECT STOCK WHERE a = :a'], $sql->getArrayCopy());
        self::assertStringContainsString('preset_query', $tester->getDisplay());
    }

    public function testItBoundsAnalyzeWithTheConfiguredQueryTimeout(): void
    {
        $this->arrangePreset('sales_over_time');
        $statements = [];
        $this->connection->method('fetchOne')->willReturn('5');
        $this->connection->method('query')->willReturnCallback(
            function (string $sql) use (&$statements): Stub {
                $statements[] = $sql;

                return $this->createStub(\Zend_Db_Statement_Interface::class);
            }
        );
        $this->connection->method('fetchAll')->willReturnCallback(
            static function (string $sql) use (&$statements): array {
                $statements[] = $sql;

                return [['id' => '1']];
            }
        );

        $this->tester->execute([
            '--preset' => 'sales_over_time',
            '--from' => '2026-01-01',
            '--to' => '2026-01-31',
            '--analyze' => true,
        ]);

        self::assertSame('SET SESSION max_execution_time = 30000', $statements[0]);
        self::assertSame('EXPLAIN ANALYZE SELECT PAGE WHERE a = :a', $statements[1]);
        self::assertSame('SET SESSION max_execution_time = 5', end($statements));
    }

    private function arrangePreset(string $code): void
    {
        $this->registry->method('getPreset')->willReturn($this->preset($code));
        $this->arrangeQueries();
    }

    private function arrangeQueries(?ResolvedQuery $comparison = null, ?Dataset $dataset = null): ResolvedQuery
    {
        $this->requestBuilder->method('build')->willReturn($this->createStub(ReportRequestInterface::class));
        $main = $this->resolvedQuery($dataset);
        $this->planner->method('plan')->willReturn(['main' => $main, 'comparison' => $comparison]);
        $this->selectBuilder->method('buildPage')->willReturnCallback(
            fn (ResolvedQuery $q): Select => $this->select($q === $main ? 'PAGE' : 'CMP PAGE')
        );
        $this->selectBuilder->method('buildTotals')->willReturnCallback(
            fn (ResolvedQuery $q): Select => $this->select($q === $main ? 'TOTALS' : 'CMP TOTALS')
        );
        $this->selectBuilder->method('buildRowCount')->willReturn($this->select('ROWCOUNT'));
        $this->selectBuilder->method('buildCurrencyProbe')->willReturn($this->select('PROBE'));

        return $main;
    }

    private function resolvedQuery(?Dataset $dataset = null): ResolvedQuery
    {
        $dataset ??= new Dataset('sales_orders', 'Orders', 'acl', 'sales', false, null, null, 'provider', [], [], [], [], []);

        return new ResolvedQuery($dataset, [], [], [], null, [], [], null, 0, new ReportScope(null, 'UTC', 0, ['UTC']));
    }

    private function select(string $name): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('assemble')->willReturn('SELECT ' . $name . ' WHERE a = :a');
        $select->method('getBind')->willReturn([':a' => 5]);

        return $select;
    }

    /**
     * @return \ArrayObject<int,string> SQL of every fetchAll call, in order
     */
    private function captureFetchAll(): \ArrayObject
    {
        /** @var \ArrayObject<int,string> $captured */
        $captured = new \ArrayObject();
        $this->connection->method('fetchAll')->willReturnCallback(
            static function (string $sql) use ($captured): array {
                $captured[] = $sql;

                return [['id' => '1']];
            }
        );

        return $captured;
    }

    private function preset(string $code): Preset
    {
        return new Preset($code, 'sales', $code, 'sales_orders', ['net_sales'], [], [], [], 'none', null, ['mysql']);
    }
}
