<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use MageOS\AdvancedReport\Console\Command\BenchmarkCommand;
use MageOS\AdvancedReport\Model\Benchmark\BarrierClock;
use MageOS\AdvancedReport\Model\Benchmark\BenchmarkRunner;
use MageOS\AdvancedReport\Model\Benchmark\ConcurrencySummary;
use MageOS\AdvancedReport\Model\Benchmark\ConcurrentRunner;
use MageOS\AdvancedReport\Model\Benchmark\Measurement;
use MageOS\AdvancedReport\Model\Benchmark\Scenario;
use MageOS\AdvancedReport\Model\Benchmark\ScenarioProvider;
use MageOS\AdvancedReport\Model\Benchmark\ScenarioResult;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\Query\CompareMode;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Security\TrustedCliContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BenchmarkCommand::class)]
#[AllowMockObjectsWithoutExpectations]
final class BenchmarkCommandTest extends TestCase
{
    private ScenarioProvider&MockObject $provider;
    private BenchmarkRunner&MockObject $runner;
    private AdapterInterface&MockObject $connection;
    private Filesystem&MockObject $filesystem;
    private ConcurrentRunner&MockObject $concurrentRunner;
    private BarrierClock&MockObject $barrierClock;
    private TrustedCliContext $trustedContext;
    private CommandTester $tester;

    /**
     * @var string[]
     */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ScenarioProvider::class);
        $this->provider->method('getScenarios')->willReturn([
            $this->scenario('orders:last_30_days:all:none', 'sales_overview', DateRangeType::LAST_30_DAYS),
            $this->scenario('orders:last_12_months:all:none', 'sales_overview', DateRangeType::CUSTOM),
            $this->scenario('product:last_30_days:all:none', 'product_performance', DateRangeType::LAST_30_DAYS),
        ]);

        $this->runner = $this->createMock(BenchmarkRunner::class);
        $this->runner->method('run')->willReturnCallback(function (Scenario $scenario, int $repeat): ScenarioResult {
            $this->ran[] = $scenario->code;

            return new ScenarioResult(
                $scenario,
                [$this->measurement(120.5, 7, 4000, false)],
                [$this->measurement(2.5, 0, 0, true)],
            );
        });

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('getTableName')->willReturnArgument(0);
        $this->connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql): string => match (true) {
                str_contains($sql, 'sales_order_item') => '4200',
                str_contains($sql, 'sales_order') => '1200',
                default => '8.4.1-MySQL',
            }
        );
        $reportConnection = $this->createMock(ReportConnection::class);
        $reportConnection->method('get')->willReturn($this->connection);
        $guardrails = $this->createMock(Guardrails::class);
        $guardrails->method('applyTimeout')->willReturnArgument(0);

        $this->filesystem = $this->createMock(Filesystem::class);
        $this->trustedContext = new TrustedCliContext();
        $this->concurrentRunner = $this->createMock(ConcurrentRunner::class);
        $this->barrierClock = $this->createMock(BarrierClock::class);

        $this->tester = new CommandTester(new BenchmarkCommand(
            $this->createStub(State::class),
            $this->trustedContext,
            $this->provider,
            $this->runner,
            $reportConnection,
            $guardrails,
            $this->filesystem,
            $this->concurrentRunner,
            $this->barrierClock,
        ));
    }

    public function testItRunsOnlyTheRequestedPresets(): void
    {
        $exit = $this->tester->execute(['--preset' => ['product_performance']]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['product:last_30_days:all:none'], $this->ran);
    }

    public function testItPrintsATableWithColdAndWarmMeasurementsPerScenario(): void
    {
        $this->tester->execute(['--preset' => ['product_performance']]);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Cold median', $display);
        self::assertStringContainsString('Warm median', $display);
        self::assertStringContainsString('product:last_30_days:all:none', $display);
        self::assertStringContainsString('120.5', $display);
        self::assertStringContainsString('2.5', $display);
    }

    public function testItPrintsMarkdownRowsReadyForThePerformanceDocument(): void
    {
        $this->tester->execute(['--preset' => ['product_performance'], '--format' => 'markdown']);

        self::assertStringContainsString(
            '| product:last_30_days:all:none | 120.5 | 120.5 | 7 | 4000 | 2.5 | 0 |',
            $this->tester->getDisplay()
        );
    }

    public function testItWritesJsonOutputToTheGivenFileUnderVar(): void
    {
        $directory = $this->createMock(WriteInterface::class);
        $this->filesystem->expects(self::once())->method('getDirectoryWrite')->with('var')->willReturn($directory);
        $directory->expects(self::once())->method('writeFile')->with(
            'benchmarks/run.json',
            self::callback(static function (string $content): bool {
                $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

                return $data['results'][0]['code'] === 'product:last_30_days:all:none';
            })
        );

        $exit = $this->tester->execute([
            '--preset' => ['product_performance'],
            '--format' => 'json',
            '--output' => 'benchmarks/run.json',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
    }

    public function testItExitsWithFailureWhenAColdMedianExceedsTheBudget(): void
    {
        $exit = $this->tester->execute(['--preset' => ['product_performance'], '--budget-ms' => '100']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Over budget', $this->tester->getDisplay());
        self::assertStringContainsString('product:last_30_days:all:none', $this->tester->getDisplay());
    }

    public function testItRejectsAnInvalidCustomDateRange(): void
    {
        $exit = $this->tester->execute(['--range' => 'custom', '--from' => '2026-02-30', '--to' => '2026-03-01']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame([], $this->ran);
    }

    public function testItIncludesTheProfileLabelOrderCountsAndServerVersionInTheOutputHeader(): void
    {
        $this->tester->execute(['--preset' => ['product_performance'], '--profile-label' => 'medium']);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('medium', $display);
        self::assertStringContainsString('1200', $display);
        self::assertStringContainsString('4200', $display);
        self::assertStringContainsString('8.4.1-MySQL', $display);
    }

    public function testItEnablesTheTrustedCliContextBeforeRunningScenarios(): void
    {
        $enabledWhenRun = null;
        $runner = $this->createMock(BenchmarkRunner::class);
        $runner->method('run')->willReturnCallback(
            function (Scenario $scenario) use (&$enabledWhenRun): ScenarioResult {
                $enabledWhenRun = $this->trustedContext->isEnabled();

                return new ScenarioResult($scenario, [], []);
            }
        );
        $reportConnection = $this->createStub(ReportConnection::class);
        $reportConnection->method('get')->willReturn($this->connection);
        $command = new BenchmarkCommand(
            $this->createStub(State::class),
            $this->trustedContext,
            $this->provider,
            $runner,
            $reportConnection,
            $this->createStub(Guardrails::class),
            $this->filesystem,
            $this->concurrentRunner,
            $this->barrierClock,
        );

        (new CommandTester($command))->execute(['--preset' => ['product_performance']]);

        self::assertTrue($enabledWhenRun);
    }

    public function testItRejectsAnOutputPathOutsideTheVarDirectory(): void
    {
        $this->filesystem->expects(self::never())->method('getDirectoryWrite');

        foreach (['/etc/passwd', '../escape.json', 'a/../../b.json'] as $path) {
            $exit = $this->tester->execute(['--output' => $path]);

            self::assertSame(Command::FAILURE, $exit, $path);
        }
        self::assertSame([], $this->ran);
    }

    public function testItRunsTheScenarioInConcurrentWorkersAndPrintsTheSummary(): void
    {
        $this->concurrentRunner->expects(self::once())->method('run')
            ->with(['--preset=product_performance', '--range=last_30_days'], 4)
            ->willReturn(new ConcurrencySummary([
                ['wall_ms' => 900.0, 'cached' => false, 'sql_statements' => 7, 'measured_at_ms' => 1, 'late' => false],
                ['wall_ms' => 920.0, 'cached' => true, 'sql_statements' => 0, 'measured_at_ms' => 1, 'late' => false],
            ], 1));

        $exit = $this->tester->execute([
            '--preset' => ['product_performance'],
            '--range' => 'last_30_days',
            '--concurrency' => '4',
        ]);

        $display = $this->tester->getDisplay();
        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame([], $this->ran);
        self::assertStringContainsString('all cached reports were dropped', $display);
        self::assertStringContainsString('Executed SQL: 1', $display);
        self::assertStringContainsString('Served from cache: 1', $display);
    }

    public function testItRequiresExactlyOneScenarioForTheConcurrencyMode(): void
    {
        $this->concurrentRunner->expects(self::never())->method('run');

        $exit = $this->tester->execute(['--concurrency' => '2']);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testItRejectsAnInvalidConcurrency(): void
    {
        $this->concurrentRunner->expects(self::never())->method('run');

        foreach (['0', '33', 'abc'] as $value) {
            self::assertSame(
                Command::FAILURE,
                $this->tester->execute(['--preset' => ['product_performance'], '--concurrency' => $value]),
                $value
            );
        }
    }

    public function testItReportsAFailingWorker(): void
    {
        $this->concurrentRunner->method('run')->willThrowException(new \RuntimeException('Worker 1 failed'));

        $exit = $this->tester->execute(['--preset' => ['product_performance'], '--concurrency' => '2']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Worker 1 failed', $this->tester->getDisplay());
    }

    public function testItRunsOneMeasurementAfterWaitingForTheStartInstantInWorkerMode(): void
    {
        $events = [];
        $this->barrierClock->method('nowMs')->willReturn(1_700_000_005_100);
        $this->barrierClock->expects(self::once())->method('waitUntil')->with(1_700_000_005_000)
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'wait';
            });
        $this->runner->expects(self::never())->method('run');
        $this->runner->expects(self::once())->method('runOnce')
            ->willReturnCallback(function (Scenario $scenario) use (&$events): Measurement {
                $events[] = 'measure';

                return $this->measurement(55.5, 0, null, true);
            });

        $exit = $this->tester->execute([
            '--preset' => ['product_performance'],
            '--range' => 'last_30_days',
            '--worker' => true,
            '--start-at' => '1700000005000',
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['wait', 'measure'], $events);
        self::assertSame(
            ['wall_ms' => 55.5, 'cached' => true, 'sql_statements' => 0, 'measured_at_ms' => 1_700_000_005_100],
            json_decode(trim($this->tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR)
        );
    }

    private function scenario(string $code, string $preset, DateRangeType $type): Scenario
    {
        return new Scenario($code, $preset, [], new DateRange($type), [], CompareMode::NONE);
    }

    private function measurement(float $wallMs, int $statements, ?int $rowsExamined, bool $cached): Measurement
    {
        return new Measurement($wallMs, 1.0, $statements, $rowsExamined, 10, 20, 1048576, $cached, 5);
    }
}
