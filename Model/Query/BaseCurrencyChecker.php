<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Resolves and compares the base currency (`currency/options/base`, website scope when the catalog price scope is website, default scope otherwise) of the
 * websites a report request is scoped to, so {@see ReportResult} can report a single
 * `currency_code` and {@see RequestValidator} can reject monetary requests whose stores mix
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
     * Distinct, sorted base currency codes of the websites in scope; empty `$storeIds` means
     * all stores.
     *
     * @param int[] $storeIds
     * @return string[]
     */
    public function getCurrencyCodes(array $storeIds): array
    {
        $codes = array_values(
            array_unique(array_map($this->currencyForWebsite(...), $this->resolveWebsiteIds($storeIds)))
        );
        sort($codes);

        return $codes;
    }

    /**
     * Currency code of a result: the default scope base currency for datasets without a store
     * field, the single base currency in scope, or '' when the scope mixes currencies.
     *
     * @param int[] $storeIds
     */
    public function resolveCurrencyCode(Dataset $dataset, array $storeIds): string
    {
        if (!$dataset->hasField(Dataset::FIELD_STORE_ID)) {
            return (string) $this->scopeConfig->getValue(self::XML_PATH_BASE_CURRENCY);
        }

        $codes = $this->getCurrencyCodes($storeIds);

        return \count($codes) === 1 ? $codes[0] : '';
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
        if (!$this->isWebsitePriceScope()) {
            return (string) $this->scopeConfig->getValue(self::XML_PATH_BASE_CURRENCY);
        }

        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_BASE_CURRENCY,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId,
        );
    }

    /**
     * With the global catalog price scope the core ignores website base currency values.
     */
    private function isWebsitePriceScope(): bool
    {
        return (int) $this->scopeConfig->getValue(Store::XML_PATH_PRICE_SCOPE) === Store::PRICE_SCOPE_WEBSITE;
    }
}
