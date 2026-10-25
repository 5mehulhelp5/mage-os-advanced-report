<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Benchmark;

/**
 * Formats the segments of benchmark scenario codes.
 */
final class ScenarioCode
{
    /**
     * @param int[] $storeIds Empty means all stores.
     */
    public static function storeSegment(array $storeIds): string
    {
        return match (\count($storeIds)) {
            0 => 'all_stores',
            1 => 'store_' . $storeIds[0],
            default => 'stores_' . implode(',', $storeIds),
        };
    }
}
