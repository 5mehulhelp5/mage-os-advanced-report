<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;

/**
 * Derives the {@see ReportScope} from the request's store ids: the shared timezone of the
 * selected stores, or the default scope timezone when they span several.
 */
class ReportScopeResolver
{
    private const string XML_PATH_TIMEZONE = 'general/locale/timezone';
    private const string FALLBACK_TIMEZONE = 'UTC';

    public function __construct(
        private readonly StoreTimezoneMap $storeTimezoneMap,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Config $config,
    ) {
    }

    /**
     * @param int[] $storeIds Empty means all stores.
     */
    public function resolve(array $storeIds): ReportScope
    {
        $storeTimezones = $this->storeTimezoneMap->getTimezonesForStores($storeIds);
        $timezones = array_values(array_unique($storeTimezones));
        sort($timezones);

        if (\count($timezones) !== 1) {
            return new ReportScope(null, $this->getDefaultTimezone(), $this->config->getFirstDayOfWeek(null), $timezones);
        }

        $referenceStoreId = $storeIds === []
            ? (int)$this->storeManager->getDefaultStoreView()?->getId()
            : (int)reset($storeIds);

        return new ReportScope(
            $referenceStoreId,
            $timezones[0],
            $this->config->getFirstDayOfWeek($referenceStoreId),
            $timezones,
        );
    }

    private function getDefaultTimezone(): string
    {
        $timezone = $this->scopeConfig->getValue(self::XML_PATH_TIMEZONE);

        return \is_string($timezone) && $timezone !== '' ? $timezone : self::FALLBACK_TIMEZONE;
    }
}
