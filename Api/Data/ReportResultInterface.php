<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Api\Data;

use DateTimeImmutable;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;

/**
 * The outcome of running a {@see ReportRequestInterface} through
 * {@see \MageOS\AdvancedReport\Api\ReportRunnerInterface}: columns, rows, totals, the
 * optional comparison period and everything the UI needs to render the report.
 *
 * {@see toArray()} is the stable JSON contract consumed by the admin UI and the CSV export;
 * its keys must not change without updating every consumer.
 */
interface ReportResultInterface
{
    /**
     * Column metadata (dimensions then metrics, in request order).
     *
     * @return array<int,array{
     *     code: string,
     *     label: string,
     *     type: string,
     *     format: ?string,
     *     description: ?string,
     *     nonAdditive: bool,
     *     lowerIsBetter: bool
     * }>
     */
    public function getColumns(): array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getRows(): array;

    /**
     * @return array<string,mixed>
     */
    public function getTotals(): array;

    /**
     * Total number of rows matching the query, ignoring limit/offset.
     */
    public function getTotalRowCount(): int;

    /**
     * The reported period, null for dateless datasets.
     */
    public function getRange(): ?ResolvedDateRange;

    /**
     * The comparison period, null when no comparison was requested.
     */
    public function getComparisonRange(): ?ResolvedDateRange;

    /**
     * Base currency of the scope (first website in scope).
     */
    public function getCurrencyCode(): string;

    /**
     * Non-fatal warnings surfaced to the UI (e.g. mixed currencies, low cost coverage).
     *
     * @return string[]
     */
    public function getWarnings(): array;

    /**
     * The data source that executed the query (e.g. "mysql").
     */
    public function getSource(): string;

    /**
     * When the result was generated.
     */
    public function getGeneratedAt(): DateTimeImmutable;

    /**
     * Whether the result was served from cache.
     */
    public function isCached(): bool;

    /**
     * Source-specific metadata (e.g. the timezone strategy used).
     *
     * @return array<string,mixed>
     */
    public function getMeta(): array;

    /**
     * The stable JSON contract consumed by the admin UI and the CSV export.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array;
}
