<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Helper;

use Magento\Store\Model\GroupFactory;
use Magento\Store\Model\ResourceModel\Group as GroupResource;
use Magento\Store\Model\ResourceModel\Store as StoreResource;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\StoreFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\WebsiteFactory;
use Magento\TestFramework\Db\Sequence;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Idempotent provisioning of extra stores for integration tests (shared with task 009).
 *
 * Creating a store runs DDL (sales sequence tables), which implicitly commits the DbIsolation
 * transaction. Call these methods from `setUpBeforeClass()` only: it runs before the transaction
 * starts. Each entity is looked up by code and created only when missing, so repeated calls and
 * repeated runs return the same ids. The stores persist in the test DB (`make integration-reset`
 * removes them). Store ids are unknown at attribute time: pass them from code, or move an order
 * with the `Fixture/SetOrderStore` fixture inside the test method.
 */
final class StoreProvisioner
{
    public const string STORE_VIEW_CODE = 'ar_store_view_2';
    public const string WEBSITE_CODE = 'ar_website_2';
    public const string GROUP_CODE = 'ar_group_2';
    public const string WEBSITE_STORE_CODE = 'ar_store_2';

    /**
     * Ensures a second store view on the default website and group; returns its id.
     */
    public function ensureStoreView(): int
    {
        $default = $this->storeManager()->getDefaultStoreView();

        return $this->ensureStore(
            self::STORE_VIEW_CODE,
            (int) $default->getWebsiteId(),
            (int) $default->getStoreGroupId()
        );
    }

    /**
     * Ensures website `ar_website_2` with group `ar_group_2` and store `ar_store_2`.
     *
     * @return array{website_id:int, store_id:int}
     */
    public function ensureSecondWebsiteStore(): array
    {
        $objectManager = Bootstrap::getObjectManager();
        $websiteResource = $objectManager->get(WebsiteResource::class);
        $groupResource = $objectManager->get(GroupResource::class);

        $website = $objectManager->get(WebsiteFactory::class)->create();
        $websiteResource->load($website, self::WEBSITE_CODE, 'code');
        if (!$website->getId()) {
            $website->setData(['code' => self::WEBSITE_CODE, 'name' => 'AR Website 2', 'sort_order' => 10]);
            $websiteResource->save($website);
        }
        $websiteId = (int) $website->getId();

        $group = $objectManager->get(GroupFactory::class)->create();
        $groupResource->load($group, self::GROUP_CODE, 'code');
        if (!$group->getId()) {
            $group->setData([
                'code' => self::GROUP_CODE,
                'name' => 'AR Group 2',
                'website_id' => $websiteId,
                'root_category_id' => $this->storeManager()->getGroup(
                    (string) $this->storeManager()->getDefaultStoreView()->getStoreGroupId()
                )->getRootCategoryId(),
            ]);
            $groupResource->save($group);
        }
        $groupId = (int) $group->getId();

        $storeId = $this->ensureStore(self::WEBSITE_STORE_CODE, $websiteId, $groupId);

        if (!(int) $group->getDefaultStoreId()) {
            $group->setDefaultStoreId($storeId);
            $groupResource->save($group);
        }
        if (!(int) $website->getDefaultGroupId()) {
            $website->setDefaultGroupId($groupId);
            $websiteResource->save($website);
        }
        $this->storeManager()->reinitStores();

        return ['website_id' => $websiteId, 'store_id' => $storeId];
    }

    private function ensureStore(string $code, int $websiteId, int $groupId): int
    {
        $objectManager = Bootstrap::getObjectManager();
        $resource = $objectManager->get(StoreResource::class);
        $store = $objectManager->get(StoreFactory::class)->create();
        $resource->load($store, $code, 'code');
        if (!$store->getId()) {
            $store->setData([
                'code' => $code,
                'name' => $code,
                'website_id' => $websiteId,
                'group_id' => $groupId,
                'is_active' => 1,
                'sort_order' => 10,
            ]);
            $resource->save($store);
            $objectManager->get(Sequence::class)->generate((int) $store->getId());
            $this->storeManager()->reinitStores();
        }

        return (int) $store->getId();
    }

    private function storeManager(): StoreManagerInterface
    {
        return Bootstrap::getObjectManager()->get(StoreManagerInterface::class);
    }
}
