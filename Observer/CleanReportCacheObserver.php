<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Observer;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\AdvancedReport\Model\Cache\Type;

/**
 * Cleans the `advanced_report` cache type whenever the module's own
 * configuration section is saved, covering settings that change results
 * without being part of the result cache key (report attributes, dead stock
 * days, counted statuses, connection).
 */
class CleanReportCacheObserver implements ObserverInterface
{
    /**
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(private readonly TypeListInterface $cacheTypeList)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->cacheTypeList->cleanType(Type::TYPE_IDENTIFIER);
    }
}
