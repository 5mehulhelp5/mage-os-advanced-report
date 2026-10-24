<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Benchmark;

use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Api\ReportRunnerInterface;
use MageOS\AdvancedReport\Model\Benchmark\BenchmarkRunner;
use MageOS\AdvancedReport\Model\Benchmark\Measurement;
use MageOS\AdvancedReport\Model\Benchmark\Scenario;
use MageOS\AdvancedReport\Model\Benchmark\ScenarioResult;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Query\CompareMode;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\PresetRequestBuilder;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BenchmarkRunner::class)]
#[CoversClass(ScenarioResult::class)]
#[CoversClass(Measurement::class)]
final class BenchmarkRunnerTest extends TestCase
{
    /** @var ReportRequestInterface[] */
    private array $requests = [];

    public function testItMeasuresAColdRunWithTheCacheBypassedAndAWarmRunFromTheCache(): void
    {
        $runner = $this->runner([
            $this->reportResult(false, 4, 12.5),
            $this->reportResult(true, 0, 0.0),
        ]);

        $result = $runner->run($this->scenario());

        self::assertCount(1, $result->cold);
        self::assertCount(1, $result->warm);
        self::assertTrue($this->requests[0]->isBypassCache());
        self::assertFalse($this->requests[1]->isBypassCache());
        self::assertFalse($result->cold[0]->cached);
        self::assertTrue($result->warm[0]->cached);
        self::assertGreaterThanOrEqual(0.0, $result->cold[0]->wallMs);
    }

    public function testItRecordsTheSqlStatementCountAndSqlTimeFromTheResultMeta(): void
    {
        $result = $this->runner([$this->reportResult(false, 4, 12.5), $this->reportResult(true, 0, 0.0)])
            ->run($this->scenario());

        self::assertSame(4, $result->cold[0]->sqlQueryCount);
        self::assertSame(12.5, $result->cold[0]->sqlMs);
        self::assertSame(7, $result->cold[0]->rowCount);
    }

    public function testItRecordsZeroSqlStatementsForAWarmRunServedFromCache(): void
    {
        $result = $this->runner([$this->reportResult(false, 4, 12.5), $this->reportResult(true, 0, 0.0)])
            ->run($this->scenario());

        self::assertSame(0, $result->warm[0]->sqlQueryCount);
        self::assertSame(0.0, $result->warm[0]->sqlMs);
    }

    public function testItComputesRowsExaminedFromSessionHandlerCounterDeltas(): void
    {
        $zero = $this->snapshot(0, 0, 0, 0, 0, 0, 0);
        $result = $this->runner(
            [$this->reportResult(false, 4, 1.0), $this->reportResult(true, 0, 0.0)],
            [
                $zero,
                $this->snapshot(1, 0, 1, 0, 0, 0, 0),
                $zero,
                $this->snapshot(11, 5, 101, 50, 4, 10, 200),
                $zero,
                $zero,
            ],
        )->run($this->scenario());

        $cold = $result->cold[0];
        self::assertSame(10, $cold->questions);
        self::assertSame(5 + 100 + 50 + 4 + 10, $cold->rowsExamined);
        self::assertSame(200, $cold->innodbRowsRead);
    }

    public function testItReportsNullCountersWhenTheServerDoesNotExposeAStatusVariable(): void
    {
        $result = $this->runner(
            [$this->reportResult(false, 4, 1.0), $this->reportResult(true, 0, 0.0)],
            [[], [], ['Questions' => '5'], ['Questions' => '9'], [], []],
        )->run($this->scenario());

        $cold = $result->cold[0];
        self::assertSame(4, $cold->questions);
        self::assertNull($cold->rowsExamined);
        self::assertNull($cold->innodbRowsRead);
    }

    public function testItComputesMinMedianP95AndMaxWallTimeOverRepeatedRuns(): void
    {
        $measurements = array_map(
            static fn (float $wall): Measurement => new Measurement($wall, 0.0, 0, null, null, null, 0, false, 0),
            [50.0, 10.0, 40.0, 20.0, 30.0],
        );
        $result = new ScenarioResult($this->scenario(), $measurements, []);

        self::assertSame(
            ['min' => 10.0, 'median' => 30.0, 'p95' => 50.0, 'max' => 50.0],
            $result->coldStats(),
        );
        self::assertNull($result->warmStats());
    }

    public function testItRunsRepeatedColdAndWarmMeasurements(): void
    {
        $result = $this->runner([
            $this->reportResult(false, 1, 1.0),
            $this->reportResult(false, 1, 1.0),
            $this->reportResult(true, 0, 0.0),
            $this->reportResult(true, 0, 0.0),
        ])->run($this->scenario(), 2);

        self::assertCount(2, $result->cold);
        self::assertCount(2, $result->warm);
        self::assertSame([true, true, false, false], array_map(
            static fn (ReportRequestInterface $request): bool => $request->isBypassCache(),
            $this->requests,
        ));
    }

    public function testItBuildsTheRequestOfAPresetScenarioWithTheScenarioOverrides(): void
    {
        $scenario = new Scenario(
            'p',
            'sales_overview',
            [],
            new DateRange(DateRangeType::LAST_7_DAYS),
            [2],
            CompareMode::PREVIOUS_PERIOD,
        );

        $this->runner([$this->reportResult(false, 1, 1.0), $this->reportResult(true, 0, 0.0)])->run($scenario);

        $request = $this->requests[0];
        self::assertSame('sales_overview', $request->getPreset());
        self::assertSame(DateRangeType::LAST_7_DAYS, $request->getDateRange()->type);
        self::assertSame([2], $request->getStoreIds());
        self::assertSame(CompareMode::PREVIOUS_PERIOD, $request->getCompareTo());
    }

    public function testItMeasuresASingleRunWithoutBypassingTheCache(): void
    {
        $measurement = $this->runner([$this->reportResult(true, 0, 0.0)])->runOnce($this->scenario());

        self::assertCount(1, $this->requests);
        self::assertFalse($this->requests[0]->isBypassCache());
        self::assertTrue($measurement->cached);
        self::assertSame(0, $measurement->sqlQueryCount);
    }

    private function scenario(): Scenario
    {
        return new Scenario(
            'sales',
            null,
            ['dataset' => 'sales_orders', 'metrics' => ['orders']],
            new DateRange(DateRangeType::LAST_30_DAYS),
            [],
            CompareMode::NONE,
        );
    }

    private function reportResult(bool $cached, int $queries, float $sqlMs): ReportResultInterface
    {
        $result = $this->createStub(ReportResultInterface::class);
        $result->method('isCached')->willReturn($cached);
        $result->method('getMeta')->willReturn(['sql_query_count' => $queries, 'sql_time_ms' => $sqlMs]);
        $result->method('getTotalRowCount')->willReturn(7);

        return $result;
    }

    /**
     * @return array<string,string>
     */
    private function snapshot(
        int $questions,
        int $first,
        int $key,
        int $next,
        int $rnd,
        int $rndNext,
        int $innodb,
    ): array {
        return [
            'Questions' => (string) $questions,
            'Handler_read_first' => (string) $first,
            'Handler_read_key' => (string) $key,
            'Handler_read_next' => (string) $next,
            'Handler_read_rnd' => (string) $rnd,
            'Handler_read_rnd_next' => (string) $rndNext,
            'Innodb_rows_read' => (string) $innodb,
        ];
    }

    /**
     * @param ReportResultInterface[] $results
     * @param array<int,array<string,string>>|null $snapshots
     */
    private function runner(array $results, ?array $snapshots = null): BenchmarkRunner
    {
        $this->requests = [];
        $reportRunner = $this->createStub(ReportRunnerInterface::class);
        $reportRunner->method('run')->willReturnCallback(
            function (ReportRequestInterface $request) use (&$results): ReportResultInterface {
                $this->requests[] = $request;

                return array_shift($results) ?? throw new \LogicException('No more results queued.');
            }
        );

        $zero = $this->snapshot(0, 0, 0, 0, 0, 0, 0);
        $snapshots ??= array_fill(0, 40, $zero);
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('fetchPairs')->willReturnCallback(
            static function () use (&$snapshots): array {
                return array_shift($snapshots) ?? [];
            }
        );
        $connection = $this->createStub(ReportConnection::class);
        $connection->method('get')->willReturn($adapter);

        $preset = new Preset('sales_overview', 'sales', 'Sales', 'sales_orders', ['orders'], [], [], [], 'none', null, []);
        $registry = $this->createStub(DefinitionRegistry::class);
        $registry->method('getPreset')->willReturn($preset);
        $factory = new ReportRequestFactory();

        return new BenchmarkRunner($reportRunner, $connection, new PresetRequestBuilder($factory), $factory, $registry);
    }
}
