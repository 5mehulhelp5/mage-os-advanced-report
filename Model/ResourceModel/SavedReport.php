<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class SavedReport extends AbstractDb
{
    public const TABLE = 'mageos_advancedreport_saved';

    /**
     * @SuppressWarnings("PHPMD.CamelCaseMethodName")
     */
    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'id');
    }
}
