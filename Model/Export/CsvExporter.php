<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Export;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Api\ReportRunnerInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\ResultCacheWriteGuard;

/**
 * Exports a report request as CSV: translated headers, raw unformatted values, optional totals row.
 * The report is run once through the runner, capped by the `max_rows` setting.
 */
class CsvExporter
{
    private const string EXPORT_DIRECTORY = 'export/mageos_advancedreport';
    private const string FORMULA_PREFIXES = '=+-@';

    /**
     * @param ReportRunnerInterface $reportRunner
     * @param ReportRequestFactory $requestFactory
     * @param Config $config
     * @param ResultCacheWriteGuard $writeGuard
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly ReportRunnerInterface $reportRunner,
        private readonly ReportRequestFactory $requestFactory,
        private readonly Config $config,
        private readonly ResultCacheWriteGuard $writeGuard,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * Writes the CSV for the given request to a temporary file and returns its path relative to the
     * var directory; the caller streams it and deletes it.
     *
     * The report runs once (capped by `max_rows`) without storing its result in the result cache.
     * The report runs before the file is opened and a partially written file is removed on failure.
     */
    public function exportToFile(ReportRequestInterface $request, bool $includeTotals): string
    {
        $result = $this->writeGuard->runWithoutStore(
            fn (): ReportResultInterface => $this->reportRunner->run($this->buildExportRequest($request))
        );
        $rows = $result->getRows();
        $layout = $this->buildLayout($result, $rows);

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $directory->create(self::EXPORT_DIRECTORY);
        $path = self::EXPORT_DIRECTORY . '/' . bin2hex(random_bytes(8)) . '.csv';
        $file = $directory->openFile($path, 'w');

        try {
            $file->writeCsv($layout['headers'], ',', '"');
            foreach ($rows as $row) {
                $file->writeCsv($this->buildRow($layout, $row), ',', '"');
            }
            if ($includeTotals) {
                $file->writeCsv($this->buildTotalsRow($layout, $result->getTotals()), ',', '"');
            }
        } catch (\Throwable $e) {
            $file->close();
            $directory->delete($path);

            throw $e;
        }
        $file->close();

        return $path;
    }

    private function buildExportRequest(ReportRequestInterface $request): ReportRequestInterface
    {
        $payload = $this->requestFactory->toArray($request);
        $payload['limit'] = $this->config->getMaxRows();
        $payload['offset'] = 0;
        $payload['bypassCache'] = false;

        return $this->requestFactory->fromArray($payload);
    }

    /**
     * Column layout derived from the first page: cells per column plus the header line.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{headers: string[], cells: array<int,array{type: string, code: string, kind: string}>}
     */
    private function buildLayout(ReportResultInterface $result, array $rows): array
    {
        $comparison = $result->getComparisonRange() !== null;
        $headers = [];
        $cells = [];
        foreach ($result->getColumns() as $column) {
            $code = $column['code'];
            $label = (string) $column['label'];
            $headers[] = $label;
            $cells[] = ['type' => $column['type'], 'code' => $code, 'kind' => 'value'];
            if ($column['type'] === 'dimension' && $this->hasDistinctRawValue($code, $rows)) {
                $headers[] = (string) __('%1 (ID)', $label);
                $cells[] = ['type' => 'dimension', 'code' => $code, 'kind' => 'raw'];
            }
            if ($column['type'] === 'metric' && $comparison) {
                $headers[] = (string) __('%1 (previous)', $label);
                $cells[] = ['type' => 'metric', 'code' => $code, 'kind' => 'value_previous'];
                $headers[] = (string) __('%1 Δ%', $label);
                $cells[] = ['type' => 'metric', 'code' => $code, 'kind' => 'delta_pct'];
            }
        }

        return ['headers' => $headers, 'cells' => $cells];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function hasDistinctRawValue(string $code, array $rows): bool
    {
        foreach ($rows as $row) {
            $raw = $row['dims_raw'][$code] ?? null;
            if ($raw !== null && (string) $raw !== (string) ($row['dims'][$code] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{cells: array<int,array{type: string, code: string, kind: string}>} $layout
     * @param array<string,mixed> $row
     * @return string[]
     */
    private function buildRow(array $layout, array $row): array
    {
        $line = [];
        foreach ($layout['cells'] as $cell) {
            $line[] = match ($cell['kind']) {
                'raw' => $this->text($row['dims_raw'][$cell['code']] ?? null),
                'value' => $cell['type'] === 'dimension'
                    ? $this->text($row['dims'][$cell['code']] ?? null)
                    : $this->scalar($row['metrics'][$cell['code']] ?? null),
                'value_previous' => $this->scalar($row['comparison'][$cell['code']]['value'] ?? null),
                default => $this->scalar($row['comparison'][$cell['code']]['delta_pct'] ?? null),
            };
        }

        return $line;
    }

    /**
     * @param array{cells: array<int,array{type: string, code: string, kind: string}>} $layout
     * @param array<string,mixed> $totals
     * @return string[]
     */
    private function buildTotalsRow(array $layout, array $totals): array
    {
        $line = [];
        foreach ($layout['cells'] as $index => $cell) {
            if ($cell['type'] === 'dimension') {
                $line[] = $index === 0 ? (string) __('Total') : '';
                continue;
            }
            $line[] = match ($cell['kind']) {
                'value' => $this->scalar($totals['metrics'][$cell['code']] ?? null),
                'value_previous' => $this->scalar($totals['comparison'][$cell['code']]['value'] ?? null),
                default => $this->scalar($totals['comparison'][$cell['code']]['delta_pct'] ?? null),
            };
        }

        return $line;
    }

    private function scalar(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /**
     * Text value with spreadsheet formula prefixes neutralized.
     */
    private function text(mixed $value): string
    {
        $text = $this->scalar($value);
        if ($text !== '' && str_contains(self::FORMULA_PREFIXES, $text[0])) {
            return "'" . $text;
        }

        return $text;
    }
}
