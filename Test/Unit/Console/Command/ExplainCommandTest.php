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
use MageOS\AdvancedReport\Model\DataSource\Mysql\SelectBuilder;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Preset\PresetQueryPool;
use MageOS\AdvancedReport\Model\Query\PresetRequestBuilder;
use MageOS\AdvancedReport\Model\Query\QueryPlanner;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
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
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(DefinitionRegistry::class);
        $this->requestBuilder = $this->createMock(PresetRequestBuilder::class);
        $this->planner = $this->createMock(QueryPlanner::class);
        $this->selectBuilder = $this->createMock(SelectBuilder::class);
        $this->connection = $this->createMock(AdapterInterface::class);

        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn('report');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($this->connection);

        $command = new ExplainCommand(
            $this->createStub(State::class),
            $this->registry,
            $this->requestBuilder,
            $this->planner,
            $this->selectBuilder,
            $resource,
            $config,
            new PresetQueryPool(),
        );
        $this->tester = new CommandTester($command);
    }

    public function testItPrintsSqlAndExplainRowsForASinglePreset(): void
    {
        $this->arrangePreset('sales_over_time');
        $this->connection->expects(self::once())
            ->method('fetchAll')
            ->with('EXPLAIN SELECT 1 WHERE a = :a', [':a' => 5])
            ->willReturn([['id' => '1', 'key' => 'IDX_CREATED_AT']]);

        $exit = $this->tester->execute(['--preset' => 'sales_over_time', '--from' => '2026-01-01', '--to' => '2026-01-31']);

        $display = $this->tester->getDisplay();
        self::assertSame(0, $exit);
        self::assertStringContainsString('sales_over_time', $display);
        self::assertStringContainsString('SELECT 1 WHERE a = :a', $display);
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
        $this->connection->expects(self::exactly(2))->method('fetchAll')->willReturn([['id' => '1']]);

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

    private function arrangePreset(string $code): void
    {
        $this->registry->method('getPreset')->willReturn($this->preset($code));
        $this->arrangeQueries();
    }

    private function arrangeQueries(): void
    {
        $this->requestBuilder->method('build')->willReturn($this->createStub(ReportRequestInterface::class));
        $dataset = new Dataset('sales_orders', 'Orders', 'acl', 'sales', false, null, null, 'provider', [], [], [], [], []);
        $main = new ResolvedQuery($dataset, [], [], [], null, [], [], null, 0);
        $this->planner->method('plan')->willReturn(['main' => $main, 'comparison' => null]);
        $select = $this->createStub(Select::class);
        $select->method('assemble')->willReturn('SELECT 1 WHERE a = :a');
        $select->method('getBind')->willReturn([':a' => 5]);
        $this->selectBuilder->method('buildPage')->willReturn($select);
    }

    private function preset(string $code): Preset
    {
        return new Preset($code, 'sales', $code, 'sales_orders', ['net_sales'], [], [], [], 'none', null, ['mysql']);
    }
}
