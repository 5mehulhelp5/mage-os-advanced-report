<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface SavedReportSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return SavedReportInterface[]
     */
    public function getItems();

    /**
     * @param SavedReportInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
