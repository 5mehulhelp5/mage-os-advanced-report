<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Export;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface as DirectoryWrite;
use Magento\Framework\Filesystem\File\WriteInterface as FileWrite;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Api\ReportRunnerInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Export\CsvExporter;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use MageOS\AdvancedReport\Model\Query\ResultCacheWriteGuard;
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
    private ResultCacheWriteGuard $guard;
    private DirectoryWrite&Stub $directory;
    /** @var array<int,array<int,string>> */
    private array $written = [];
    private ?string $openedPath = null;
    /** @var string[] */
    private array $deleted = [];
    private bool $closed = false;
    private ?\Throwable $writeFailure = null;

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
        $this->guard = new ResultCacheWriteGuard();
        $this->directory = $this->createStub(DirectoryWrite::class);
        $file = $this->createStub(FileWrite::class);
        $file->method('writeCsv')->willReturnCallback(function (array $line): int {
            if ($this->writeFailure !== null) {
                throw $this->writeFailure;
            }
            $this->written[] = $line;

            return 1;
        });
        $file->method('close')->willReturnCallback(function (): bool {
            $this->closed = true;

            return true;
        });
        $this->directory->method('openFile')->willReturnCallback(
            function (string $path) use ($file): FileWrite {
                $this->openedPath = $path;

                return $file;
            }
        );
        $this->directory->method('delete')->willReturnCallback(
            function (string $path): bool {
                $this->deleted[] = $path;

                return true;
            }
        );
    }

    public function testItWritesTranslatedHeadersFollowedByRawMetricValues(): void
    {
        $this->runner->method('run')->willReturn($this->buildResult([
            $this->row('Pending', 'pending', 3, 1234.5),
        ]));

        $this->exporter()->exportToFile($this->request, false);
        $csv = $this->written;

        self::assertSame(['Status', 'Status (ID)', 'Orders', 'Net sales'], $csv[0]);
        self::assertSame(['Pending', 'pending', '3', '1234.5'], $csv[1]);
        self::assertCount(2, $csv);
    }

    public function testItRunsTheReportExactlyOnceForAnExport(): void
    {
        $runner = $this->createMock(ReportRunnerInterface::class);
        $runner->expects(self::once())->method('run')->willReturn(
            $this->buildResult(array_fill(0, 1500, $this->row('A', 'a', 1, 1.0)))
        );

        $this->exporter($runner)->exportToFile($this->request, false);

        self::assertCount(1501, $this->written);
    }

    public function testItRequestsAtMostTheConfiguredMaximumNumberOfRows(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('getMaxRows')->willReturn(1500);
        $seen = [];
        $runner = $this->createStub(ReportRunnerInterface::class);
        $runner->method('run')->willReturnCallback(
            function (ReportRequestInterface $request) use (&$seen): ReportResultInterface {
                $seen = [$request->getLimit(), $request->getOffset(), $request->isBypassCache()];

                return $this->buildResult([]);
            }
        );

        $this->exporter($runner)->exportToFile($this->request, false);

        self::assertSame([1500, 0, false], $seen);
    }

    public function testItWritesTheCsvToATemporaryFileInTheVarDirectory(): void
    {
        $this->runner->method('run')->willReturn($this->buildResult([]));
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('getDirectoryWrite')
            ->with(DirectoryList::VAR_DIR)->willReturn($this->directory);

        $path = $this->exporter(null, $filesystem)->exportToFile($this->request, false);

        self::assertMatchesRegularExpression('#^export/mageos_advancedreport/[0-9a-f]{16}\.csv$#', $path);
        self::assertSame($path, $this->openedPath);
        self::assertTrue($this->closed);
    }

    public function testItDoesNotSaveTheExportResultToTheResultCache(): void
    {
        $suppressed = null;
        $runner = $this->createStub(ReportRunnerInterface::class);
        $runner->method('run')->willReturnCallback(
            function () use (&$suppressed): ReportResultInterface {
                $suppressed = $this->guard->isStoreSuppressed();

                return $this->buildResult([]);
            }
        );

        $this->exporter($runner)->exportToFile($this->request, false);

        self::assertTrue($suppressed);
        self::assertFalse($this->guard->isStoreSuppressed());
    }

    public function testItRemovesTheTemporaryFileWhenWritingTheCsvFails(): void
    {
        $this->runner->method('run')->willReturn($this->buildResult([$this->row('A', 'a', 1, 1.0)]));
        $this->writeFailure = new \RuntimeException('disk full');

        try {
            $this->exporter()->exportToFile($this->request, false);
            self::fail('The write failure must be rethrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('disk full', $e->getMessage());
        }

        self::assertSame([$this->openedPath], $this->deleted);
        self::assertTrue($this->closed);
    }

    public function testItDoesNotCreateAFileWhenTheReportFails(): void
    {
        $runner = $this->createStub(ReportRunnerInterface::class);
        $runner->method('run')->willThrowException(new \RuntimeException('boom'));

        try {
            $this->exporter($runner)->exportToFile($this->request, false);
            self::fail('The report failure must be rethrown.');
        } catch (\RuntimeException) {
            self::assertNull($this->openedPath);
            self::assertFalse($this->guard->isStoreSuppressed());
        }
    }

    public function testItAppendsTheTotalsRowWhenRequested(): void
    {
        $this->runner->method('run')->willReturn($this->buildResult(
            [$this->row('Pending', 'pending', 3, 10.0)],
            ['metrics' => ['orders' => 3, 'net_sales' => 10.0]],
        ));

        $this->exporter()->exportToFile($this->request, true);
        $csv = $this->written;

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

        $this->exporter()->exportToFile($this->request, false);
        $csv = $this->written;

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

        $this->exporter()->exportToFile($this->request, false);
        $csv = $this->written;

        self::assertSame("'=SUM(A1)", $csv[1][0]);
        self::assertSame("'@cmd", $csv[2][0]);
    }

    public function testItNeutralizesStringCellsStartingWithATab(): void
    {
        $this->runner->method('run')->willReturn($this->buildResult([$this->row("\t12345", 'x', 1, 1.0)]));

        $this->exporter()->exportToFile($this->request, false);

        self::assertSame("'\t12345", $this->written[1][0]);
    }

    public function testItNeutralizesStringCellsStartingWithACarriageReturn(): void
    {
        $this->runner->method('run')->willReturn($this->buildResult([$this->row("\r12345", 'x', 1, 1.0)]));

        $this->exporter()->exportToFile($this->request, false);

        self::assertSame("'\r12345", $this->written[1][0]);
    }

    public function testItNeutralizesHeaderLabelsStartingWithAFormulaCharacter(): void
    {
        $columns = [
            ['code' => 'status', 'label' => '=HYPERLINK("x")', 'type' => 'dimension'],
            ['code' => 'orders', 'label' => '@Orders', 'type' => 'metric'],
        ];
        $this->runner->method('run')->willReturn($this->buildResult([$this->row('A', 'a', 1, 1.0)], [], false, $columns));

        $this->exporter()->exportToFile($this->request, false);

        self::assertSame(["'=HYPERLINK(\"x\")", "'=HYPERLINK(\"x\") (ID)", "'@Orders"], $this->written[0]);
    }

    public function testItLeavesNegativeNumericCellsUnchanged(): void
    {
        $this->runner->method('run')->willReturn($this->buildResult([$this->row('A', 'a', -3, -12.5)]));

        $this->exporter()->exportToFile($this->request, false);

        self::assertSame(['A', 'a', '-3', '-12.5'], $this->written[1]);
    }

    public function testItLeavesPlainHeaderLabelsUnchanged(): void
    {
        $this->runner->method('run')->willReturn($this->buildResult([]));

        $this->exporter()->exportToFile($this->request, false);

        self::assertSame(['Status', 'Orders', 'Net sales'], $this->written[0]);
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
     * @param array<int,array<string,string>>|null $columns
     */
    private function buildResult(array $rows, array $totals = [], bool $comparison = false, ?array $columns = null): ReportResultInterface
    {
        $result = $this->createStub(ReportResultInterface::class);
        $result->method('getColumns')->willReturn($columns ?? self::COLUMNS);
        $result->method('getRows')->willReturn($rows);
        $result->method('getTotals')->willReturn($totals);
        $result->method('getComparisonRange')->willReturn(
            $comparison ? new ResolvedDateRange(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'), false, 31) : null
        );

        return $result;
    }

    private function exporter(?ReportRunnerInterface $runner = null, ?Filesystem $filesystem = null): CsvExporter
    {
        if ($filesystem === null) {
            $filesystem = $this->createStub(Filesystem::class);
            $filesystem->method('getDirectoryWrite')->willReturn($this->directory);
        }

        return new CsvExporter($runner ?? $this->runner, $this->factory, $this->config, $this->guard, $filesystem);
    }
}
