<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Observer;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\AdvancedReport\Model\Cache\Type;

/**
 * Cleans the `advanced_report` cache type whenever the module's own
 * configuration section is saved. Result-changing settings (report attributes,
 * dead stock days, counted statuses, connection) are already part of the result
 * cache key (`ResultCache::buildKey()`, `ResultConfigFingerprint`), so this only
 * frees entries that would otherwise linger until their TTL.
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
