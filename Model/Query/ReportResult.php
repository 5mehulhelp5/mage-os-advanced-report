<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateTimeImmutable;
use DateTimeInterface;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;

/**
 * The outcome of running a report request: columns, rows, totals, the optional comparison
 * period and everything the UI needs to render the report. Built by the report runner
 * (task 044) from a {@see ResolvedQuery} execution.
 */
final readonly class ReportResult implements ReportResultInterface
{
    /**
     * @param array<int,array{
     *     code: string,
     *     label: string,
     *     type: string,
     *     format: ?string,
     *     description: ?string,
     *     nonAdditive: bool,
     *     lowerIsBetter: bool
     * }> $columns
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $totals
     * @param string[] $warnings
     * @param array<string,mixed> $meta
     */
    public function __construct(
        private array $columns,
        private array $rows,
        private array $totals,
        private int $totalRowCount,
        private ?ResolvedDateRange $range,
        private ?ResolvedDateRange $comparisonRange,
        private string $currencyCode,
        private array $warnings,
        private string $source,
        private DateTimeImmutable $generatedAt,
        private bool $cached,
        private array $meta = [],
    ) {
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getRows(): array
    {
        return $this->rows;
    }

    public function getTotals(): array
    {
        return $this->totals;
    }

    public function getTotalRowCount(): int
    {
        return $this->totalRowCount;
    }

    public function getRange(): ?ResolvedDateRange
    {
        return $this->range;
    }

    public function getComparisonRange(): ?ResolvedDateRange
    {
        return $this->comparisonRange;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getGeneratedAt(): DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function isCached(): bool
    {
        return $this->cached;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'columns' => $this->columns,
            'rows' => $this->rows,
            'totals' => $this->totals,
            'total_row_count' => $this->totalRowCount,
            'range' => $this->formatRange($this->range),
            'comparison_range' => $this->formatRange($this->comparisonRange),
            'currency_code' => $this->currencyCode,
            'warnings' => $this->warnings,
            'source' => $this->source,
            'generated_at' => $this->generatedAt->format(DateTimeInterface::ATOM),
            'cached' => $this->cached,
            'meta' => $this->meta,
        ];
    }

    /**
     * @return array{from: string, to: string}|null
     */
    private function formatRange(?ResolvedDateRange $range): ?array
    {
        if ($range === null) {
            return null;
        }

        return ['from' => $range->from->format('Y-m-d'), 'to' => $range->to->format('Y-m-d')];
    }
}
