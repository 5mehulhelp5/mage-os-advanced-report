<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Benchmark;

use InvalidArgumentException;
use MageOS\AdvancedReport\Model\Benchmark\BarrierClock;
use MageOS\AdvancedReport\Model\Benchmark\ConcurrencySummary;
use MageOS\AdvancedReport\Model\Benchmark\ConcurrentRunner;
use MageOS\AdvancedReport\Model\Benchmark\WorkerProcessFactory;
use MageOS\AdvancedReport\Model\Cache\Type;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

#[CoversClass(ConcurrentRunner::class)]
#[CoversClass(ConcurrencySummary::class)]
#[AllowMockObjectsWithoutExpectations]
final class ConcurrentRunnerTest extends TestCase
{
    private const int NOW_MS = 1_700_000_000_000;

    private WorkerProcessFactory&MockObject $factory;
    private Type&MockObject $cache;
    private BarrierClock&MockObject $clock;

    /**
     * @var string[]
     */
    private array $events = [];

    /**
     * @var array<int,string[]>
     */
    private array $commands = [];

    /**
     * @var Process[]
     */
    private array $queue = [];

    protected function setUp(): void
    {
        $this->factory = $this->createMock(WorkerProcessFactory::class);
        $this->factory->method('create')->willReturnCallback(function (array $arguments): Process {
            $this->commands[] = $arguments;

            return array_shift($this->queue) ?? $this->worker($this->json(5.0, true, 0, self::NOW_MS + 5000));
        });
        $this->cache = $this->createMock(Type::class);
        $this->clock = $this->createMock(BarrierClock::class);
        $this->clock->method('nowMs')->willReturn(self::NOW_MS);
    }

    public function testItStartsTheRequestedNumberOfWorkerProcesses(): void
    {
        $this->runner()->run(['--preset=sales_overview'], 3);

        self::assertCount(3, $this->commands);
        foreach ($this->commands as $arguments) {
            self::assertContains('advanced-report:benchmark', $arguments);
            self::assertContains('--worker', $arguments);
            self::assertContains('--format=json', $arguments);
            self::assertContains('--preset=sales_overview', $arguments);
        }
    }

    public function testItAggregatesWorkerJsonResultsIntoAConcurrencySummary(): void
    {
        $this->queue = [
            $this->worker($this->json(900.0, false, 7, self::NOW_MS + 5000)),
            $this->worker($this->json(920.0, true, 0, self::NOW_MS + 5000)),
            $this->worker($this->json(100.0, true, 0, self::NOW_MS + 5000)),
        ];

        $summary = $this->runner()->run([], 3);

        self::assertCount(3, $summary->workers);
        self::assertSame(900.0, $summary->workers[0]['wall_ms']);
        self::assertSame(7, $summary->workers[0]['sql_statements']);
        self::assertSame(920.0, $summary->maxWallMs());
        self::assertSame(900.0, $summary->medianWallMs());
    }

    public function testItReportsHowManyWorkersExecutedSqlAndHowManyWereServedFromCache(): void
    {
        $this->queue = [
            $this->worker($this->json(900.0, false, 7, self::NOW_MS + 5000)),
            $this->worker($this->json(920.0, true, 0, self::NOW_MS + 5000)),
            $this->worker($this->json(930.0, true, 0, self::NOW_MS + 7000)),
        ];

        $summary = $this->runner()->run([], 3);

        self::assertSame(1, $summary->executedSql());
        self::assertSame(2, $summary->servedFromCache());
        self::assertSame(1, $summary->lateWorkers());
        self::assertSame(1, $summary->toArray()['summary']['executed_sql']);
        self::assertSame(1, $summary->toArray()['summary']['late_workers']);
    }

    public function testItFailsClearlyWhenAWorkerProcessExitsWithAnError(): void
    {
        $this->queue = [$this->worker('', false, 'boom')];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Worker 1 failed');

        $this->runner()->run([], 1);
    }

    #[DataProvider('invalidConcurrencyProvider')]
    public function testItRejectsAConcurrencyOutside1To32(int $concurrency): void
    {
        $this->factory->expects(self::never())->method('create');
        $this->cache->expects(self::never())->method('clean');

        $this->expectException(InvalidArgumentException::class);

        $this->runner()->run([], $concurrency);
    }

    /**
     * @return array<string,array{int}>
     */
    public static function invalidConcurrencyProvider(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'too many' => [33]];
    }

    public function testItCleansTheAdvancedReportCacheTypeBeforeStartingConcurrentWorkers(): void
    {
        $this->cache->expects(self::once())->method('clean')
            ->with(\Zend_Cache::CLEANING_MODE_MATCHING_TAG, [Type::CACHE_TAG])
            ->willReturnCallback(function (): bool {
                $this->events[] = 'clean';

                return true;
            });
        $this->factory = $this->createMock(WorkerProcessFactory::class);
        $this->factory->method('create')->willReturnCallback(function (): Process {
            $this->events[] = 'create';

            return $this->worker($this->json(5.0, true, 0, self::NOW_MS + 5000));
        });

        $this->runner()->run([], 2);

        self::assertSame(['clean', 'create', 'create'], $this->events);
    }

    public function testItPassesTheSameStartInstantToEveryWorkerAndWaitsForItBeforeMeasuring(): void
    {
        $this->runner()->run([], 2);

        self::assertContains('--start-at=' . (self::NOW_MS + 5000), $this->commands[0]);
        self::assertSame($this->commands[0], $this->commands[1]);
    }

    private function runner(): ConcurrentRunner
    {
        return new ConcurrentRunner($this->factory, $this->cache, $this->clock);
    }

    private function json(float $wallMs, bool $cached, int $statements, int $measuredAtMs): string
    {
        return "warning line\n" . json_encode([
            'wall_ms' => $wallMs,
            'cached' => $cached,
            'sql_statements' => $statements,
            'measured_at_ms' => $measuredAtMs,
        ], JSON_THROW_ON_ERROR) . "\n";
    }

    private function worker(string $output, bool $successful = true, string $error = ''): Process
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn($successful);
        $process->method('getOutput')->willReturn($output);
        $process->method('getErrorOutput')->willReturn($error);
        $process->method('getExitCode')->willReturn($successful ? 0 : 1);

        return $process;
    }
}
