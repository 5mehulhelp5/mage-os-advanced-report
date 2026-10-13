<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Export;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Api\ReportRunnerInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Export\CsvExporter;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvExporter::class)]
final class CsvExporterTest extends TestCase
{
    private const array COLUMNS = [
        ['code' => 'status', 'label' => 'Status', 'type' => 'dimension'],
        ['code' => 'orders', 'label' => 'Orders', 'type' => 'metric'],
        ['code' => 'net_sales', 'label' => 'Net sales', 'type' => 'metric'],
    ];

    private ReportRunnerInterface&Stub $runner;
    private ReportRequestFactory&Stub $factory;
    private Config&Stub $config;
    private ReportRequestInterface&Stub $request;

    protected function setUp(): void
    {
        $this->runner = $this->createStub(ReportRunnerInterface::class);
        $this->factory = $this->createStub(ReportRequestFactory::class);
        $this->factory->method('toArray')->willReturn(['dataset' => 'sales_orders']);
        $this->factory->method('fromArray')->willReturnCallback(
            function (array $data): ReportRequestInterface {
                $request = $this->createStub(ReportRequestInterface::class);
                $request->method('getLimit')->willReturn($data['limit']);
                $request->method('getOffset')->willReturn($data['offset']);
                $request->method('isBypassCache')->willReturn($data['bypassCache']);

                return $request;
            }
        );
        $this->config = $this->createStub(Config::class);
        $this->config->method('getMaxRows')->willReturn(50000);
        $this->request = $this->createStub(ReportRequestInterface::class);
    }

    public function testItWritesTranslatedHeadersFollowedByRawMetricValues(): void
    {
        $this->runner->method('run')->willReturn($this->buildResult([
            $this->row('Pending', 'pending', 3, 1234.5),
        ]));

        $csv = $this->parse($this->exporter()->export($this->request, false));

        self::assertSame(['Status', 'Status (ID)', 'Orders', 'Net sales'], $csv[0]);
        self::assertSame(['Pending', 'pending', '3', '1234.5'], $csv[1]);
        self::assertCount(2, $csv);
    }

    public function testItPagesThroughTheRunnerUntilMaxRows(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('getMaxRows')->willReturn(1500);
        $page = array_fill(0, 1000, $this->row('A', 'a', 1, 1.0));
        $half = array_fill(0, 500, $this->row('A', 'a', 1, 1.0));
        $seen = [];
        $runner = $this->createMock(ReportRunnerInterface::class);
        $runner->expects(self::exactly(2))->method('run')->willReturnCallback(
            function (ReportRequestInterface $request) use (&$seen, $page, $half): ReportResultInterface {
                $seen[] = [$request->getLimit(), $request->getOffset(), $request->isBypassCache()];

                return $this->buildResult(\count($seen) === 1 ? $page : $half);
            }
        );

        $csv = $this->parse($this->exporter($runner)->export($this->request, false));

        self::assertSame([[1000, 0, false], [500, 1000, false]], $seen);
        self::assertCount(1501, $csv);
    }

    public function testItAppendsTheTotalsRowWhenRequested(): void
    {
        $this->runner->method('run')->willReturn($this->buildResult(
            [$this->row('Pending', 'pending', 3, 10.0)],
            ['metrics' => ['orders' => 3, 'net_sales' => 10.0]],
        ));

        $csv = $this->parse($this->exporter()->export($this->request, true));

        self::assertSame(['Total', '', '3', '10'], $csv[2]);
    }

    public function testItAddsComparisonColumnsWhenComparisonIsActive(): void
    {
        $row = $this->row('Pending', 'pending', 3, 10.0);
        $row['comparison'] = [
            'orders' => ['value' => 2.0, 'delta' => 1.0, 'delta_pct' => 50.0],
            'net_sales' => ['value' => null, 'delta' => null, 'delta_pct' => null],
        ];
        $this->runner->method('run')->willReturn($this->buildResult([$row], [], true));

        $csv = $this->parse($this->exporter()->export($this->request, false));

        self::assertSame(
            ['Status', 'Status (ID)', 'Orders', 'Orders (previous)', 'Orders Δ%', 'Net sales',
                'Net sales (previous)', 'Net sales Δ%'],
            $csv[0]
        );
        self::assertSame(['Pending', 'pending', '3', '2', '50', '10', '', ''], \array_slice($csv[1], 0, 8));
    }

    public function testItNeutralizesSpreadsheetFormulaPrefixesInTextValues(): void
    {
        $this->runner->method('run')->willReturn($this->buildResult([
            $this->row('=SUM(A1)', 'x', 1, 1.0),
            $this->row('@cmd', 'y', 1, 1.0),
        ]));

        $csv = $this->parse($this->exporter()->export($this->request, false));

        self::assertSame("'=SUM(A1)", $csv[1][0]);
        self::assertSame("'@cmd", $csv[2][0]);
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $label, string $raw, int|float $orders, int|float $sales): array
    {
        return [
            'dims' => ['status' => $label],
            'dims_raw' => ['status' => $raw],
            'metrics' => ['orders' => $orders, 'net_sales' => $sales],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $totals
     */
    private function buildResult(array $rows, array $totals = [], bool $comparison = false): ReportResultInterface
    {
        $result = $this->createStub(ReportResultInterface::class);
        $result->method('getColumns')->willReturn(self::COLUMNS);
        $result->method('getRows')->willReturn($rows);
        $result->method('getTotals')->willReturn($totals);
        $result->method('getComparisonRange')->willReturn(
            $comparison ? new ResolvedDateRange(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'), false, 31) : null
        );

        return $result;
    }

    /**
     * @return array<int,array<int,string|null>>
     */
    private function parse(string $csv): array
    {
        $rows = [];
        foreach (explode("\n", rtrim($csv, "\n")) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        return $rows;
    }

    private function exporter(?ReportRunnerInterface $runner = null): CsvExporter
    {
        return new CsvExporter($runner ?? $this->runner, $this->factory, $this->config);
    }
}
