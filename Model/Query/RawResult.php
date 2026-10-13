<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

/**
 * Unprocessed result of executing a {@see ResolvedQuery} against a
 * {@see \MageOS\AdvancedReport\Api\DataSourceInterface}, before derived metrics, comparison
 * merge and empty-bucket filling are applied by the runner.
 *
 * Row shape: `['dims' => [dimCode => string label], 'dims_raw' => [dimCode => ?string raw
 * value], 'metrics' => [metricCode => int|float|null]]`. Sources that do not resolve labels
 * set `dims` equal to `dims_raw`. Totals share the same metrics shape:
 * `['metrics' => [metricCode => int|float|null]]`.
 */
final readonly class RawResult
{
    /**
     * @param array<int,array{
     *     dims: array<string,string>,
     *     dims_raw: array<string,?string>,
     *     metrics: array<string,int|float|null>
     * }> $rows
     * @param array{metrics: array<string,int|float|null>} $totals
     * @param int $totalRowCount Total number of rows matching the query, ignoring limit/offset.
     * @param string[] $warnings Non-fatal warnings surfaced to the UI (e.g. mixed currencies).
     * @param array<string,mixed> $meta Source-specific metadata (e.g. timezone strategy used).
     */
    public function __construct(
        public array $rows,
        public array $totals,
        public int $totalRowCount,
        public array $warnings = [],
        public array $meta = [],
    ) {
    }
}
