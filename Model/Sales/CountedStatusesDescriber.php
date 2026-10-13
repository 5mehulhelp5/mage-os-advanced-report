<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Sales;

use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\CountedStatusCondition;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ImplicitFilterPool;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\ResourceModel\OrderStatusLabels;

/**
 * Describes, as a translated sentence, which order statuses a dataset counts.
 */
class CountedStatusesDescriber
{
    public function __construct(
        private readonly Config $config,
        private readonly OrderStatusLabels $orderStatusLabels
    ) {
    }

    /**
     * Returns the sentence, or null when the dataset is not filtered by counted statuses.
     *
     * @param Dataset $dataset
     * @param int[] $storeIds Empty means all stores
     */
    public function describe(Dataset $dataset, array $storeIds): ?string
    {
        if (!isset($dataset->implicitFilters[ImplicitFilterPool::COUNTED_STATUS])) {
            return null;
        }

        $labels = $this->orderStatusLabels->getLabels(\count($storeIds) === 1 ? (int) reset($storeIds) : null);
        $statuses = $this->config->getCountedStatuses();
        if ($statuses === []) {
            return (string) __(
                'Counted orders: all statuses except %1',
                $this->labelOf(CountedStatusCondition::DEFAULT_EXCLUDED_STATUS, $labels)
            );
        }

        return (string) __(
            'Counted orders: %1',
            implode(', ', array_map(fn (string $status): string => $this->labelOf($status, $labels), $statuses))
        );
    }

    /**
     * @param string $status
     * @param array<string,string> $labels
     */
    private function labelOf(string $status, array $labels): string
    {
        $label = $labels[$status] ?? '';

        return $label === '' ? $status : $label;
    }
}
