<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\ResourceModel\SavedReport;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MageOS\AdvancedReport\Model\ResourceModel\SavedReport as SavedReportResource;
use MageOS\AdvancedReport\Model\SavedReport\SavedReport;

class Collection extends AbstractCollection
{
    /**
     * @SuppressWarnings("PHPMD.CamelCaseMethodName")
     */
    protected function _construct(): void
    {
        $this->_init(SavedReport::class, SavedReportResource::class);
    }
}
