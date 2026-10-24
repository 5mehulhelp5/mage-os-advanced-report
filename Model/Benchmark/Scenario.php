<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Benchmark;

use MageOS\AdvancedReport\Model\Query\CompareMode;
use MageOS\AdvancedReport\Model\Query\DateRange;

/**
 * One benchmark case: a preset (optionally overridden by `$request`) or a plain request array,
 * with the date range, stores and comparison it is run with.
 */
final readonly class Scenario
{
    /**
     * @param string $code
     * @param string|null $preset
     * @param array<string,mixed> $request Request array: the whole request without preset, overrides with one.
     * @param DateRange $dateRange
     * @param int[] $storeIds Empty means all stores.
     * @param CompareMode $compareTo
     */
    public function __construct(
        public string $code,
        public ?string $preset,
        public array $request,
        public DateRange $dateRange,
        public array $storeIds,
        public CompareMode $compareTo,
    ) {
    }
}
