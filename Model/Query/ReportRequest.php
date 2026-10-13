<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;

/**
 * Immutable {@see ReportRequestInterface} implementation, built by
 * {@see ReportRequestFactory}.
 */
final readonly class ReportRequest implements ReportRequestInterface
{
    /**
     * @param string $dataset
     * @param string|null $preset
     * @param string[] $metrics
     * @param DimensionSelection[] $dimensions
     * @param FilterCondition[] $filters
     * @param DateRange $dateRange
     * @param CompareMode $compareTo
     * @param SortOrder[] $sort
     * @param int|null $limit
     * @param int $offset
     * @param int[] $storeIds
     * @param string|null $source
     * @param bool $bypassCache
     */
    public function __construct(
        private string $dataset,
        private ?string $preset,
        private array $metrics,
        private array $dimensions,
        private array $filters,
        private DateRange $dateRange,
        private CompareMode $compareTo,
        private array $sort,
        private ?int $limit,
        private int $offset,
        private array $storeIds,
        private ?string $source,
        private bool $bypassCache,
    ) {
    }

    public function getDataset(): string
    {
        return $this->dataset;
    }

    public function getPreset(): ?string
    {
        return $this->preset;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getDimensions(): array
    {
        return $this->dimensions;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getDateRange(): DateRange
    {
        return $this->dateRange;
    }

    public function getCompareTo(): CompareMode
    {
        return $this->compareTo;
    }

    public function getSort(): array
    {
        return $this->sort;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getStoreIds(): array
    {
        return $this->storeIds;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function isBypassCache(): bool
    {
        return $this->bypassCache;
    }
}
