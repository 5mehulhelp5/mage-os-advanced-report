<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateTimeImmutable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Resolves the quote retention window of the core `sales_clean_quotes` cron
 * (`checkout/cart/delete_quote_after`, read at store scope like the cron does) for the cart
 * datasets, so reports can warn when their range reaches into deleted data.
 */
class QuoteRetention
{
    private const string XML_PATH_DELETE_QUOTE_AFTER = 'checkout/cart/delete_quote_after';
    private const string CARTS_AREA = 'carts';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param int[] $storeIds Empty means all stores.
     * @return QuoteRetentionWindow|null Null for datasets outside the carts area.
     */
    public function window(Dataset $dataset, array $storeIds): ?QuoteRetentionWindow
    {
        if ($dataset->area !== self::CARTS_AREA) {
            return null;
        }

        $lifetime = $this->smallestLifetime($storeIds);
        $today = new DateTimeImmutable($this->clock->now()->format('Y-m-d'));
        if ($lifetime < 0) {
            // Everything is deleted, even carts updated today.
            return new QuoteRetentionWindow(0, $today->modify('+1 day'));
        }

        return new QuoteRetentionWindow($lifetime, $today->modify(\sprintf('-%d days', $lifetime)));
    }

    /**
     * @param int[] $storeIds
     */
    private function smallestLifetime(array $storeIds): int
    {
        $lifetimes = [];
        foreach ($this->storeManager->getStores(true) as $store) {
            if ($storeIds === [] || \in_array((int) $store->getId(), $storeIds, true)) {
                $lifetimes[] = (int) $this->scopeConfig->getValue(
                    self::XML_PATH_DELETE_QUOTE_AFTER,
                    ScopeInterface::SCOPE_STORE,
                    $store->getCode(),
                );
            }
        }

        return $lifetimes === [] ? 0 : min($lifetimes);
    }
}
