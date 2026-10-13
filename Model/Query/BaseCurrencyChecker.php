<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolves and compares the base currency (`currency/options/base`, website scope) of the
 * websites a report request is scoped to, so {@see ReportResult} can report a single
 * `currency_code` and the report runner (task 044) can warn when the selected stores mix
 * currencies.
 */
class BaseCurrencyChecker
{
    private const string XML_PATH_BASE_CURRENCY = 'currency/options/base';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Base currency of the first website in scope; empty `$storeIds` means all stores.
     *
     * @param int[] $storeIds
     */
    public function getCurrencyCode(array $storeIds): string
    {
        $websiteIds = $this->resolveWebsiteIds($storeIds);

        return $this->currencyForWebsite($websiteIds[0]);
    }

    /**
     * Whether the websites of the given stores use different base currencies; empty
     * `$storeIds` means all stores.
     *
     * @param int[] $storeIds
     */
    public function hasMixedCurrencies(array $storeIds): bool
    {
        $currencies = array_unique(
            array_map(
                $this->currencyForWebsite(...),
                $this->resolveWebsiteIds($storeIds)
            )
        );

        return \count($currencies) > 1;
    }

    /**
     * @param int[] $storeIds
     * @return int[]
     */
    private function resolveWebsiteIds(array $storeIds): array
    {
        if ($storeIds === []) {
            $websiteIds = array_map(
                static fn (WebsiteInterface $website): int => (int) $website->getId(),
                $this->storeManager->getWebsites()
            );

            return array_values(array_unique($websiteIds));
        }

        $websiteIds = [];
        foreach ($storeIds as $storeId) {
            $websiteIds[(int) $this->storeManager->getStore($storeId)->getWebsiteId()] = true;
        }

        return array_keys($websiteIds);
    }

    private function currencyForWebsite(int $websiteId): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_BASE_CURRENCY,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId,
        );
    }
}
