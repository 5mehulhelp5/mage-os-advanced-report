<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Api\Data;

use MageOS\AdvancedReport\Model\Query\CompareMode;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\FilterCondition;
use MageOS\AdvancedReport\Model\Query\SortOrder;

/**
 * A parsed, structurally valid report request: what to query (dataset, metrics,
 * dimensions), how to scope it (filters, date range, store ids) and how to shape
 * the result (compare mode, sort, limit/offset). Built by
 * {@see \MageOS\AdvancedReport\Model\Query\ReportRequestFactory} from the plain
 * array payload sent by the admin UI or stored with a saved report.
 */
interface ReportRequestInterface
{
    /**
     * Dataset code this request queries.
     */
    public function getDataset(): string;

    /**
     * Preset code this request originates from, when dispatched through a preset
     * query, null otherwise.
     */
    public function getPreset(): ?string;

    /**
     * Requested metric codes.
     *
     * @return string[]
     */
    public function getMetrics(): array;

    /**
     * Requested dimensions, each with an optional date bucket size.
     *
     * @return DimensionSelection[]
     */
    public function getDimensions(): array;

    /**
     * Requested filters.
     *
     * @return FilterCondition[]
     */
    public function getFilters(): array;

    /**
     * Requested reporting period.
     */
    public function getDateRange(): DateRange;

    /**
     * How the requested period is compared against another period.
     */
    public function getCompareTo(): CompareMode;

    /**
     * Requested sort order.
     *
     * @return SortOrder[]
     */
    public function getSort(): array;

    /**
     * Maximum number of rows to return, null for no explicit limit.
     */
    public function getLimit(): ?int;

    /**
     * Number of rows to skip.
     */
    public function getOffset(): int;

    /**
     * Store ids this request is scoped to; empty means all stores.
     *
     * @return int[]
     */
    public function getStoreIds(): array;

    /**
     * Explicit data source override, null lets the source resolver pick one.
     */
    public function getSource(): ?string;

    /**
     * Whether the result cache must be bypassed for this request.
     */
    public function isBypassCache(): bool;
}
