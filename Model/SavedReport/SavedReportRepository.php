<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\SavedReport;

use Exception;
use InvalidArgumentException;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use MageOS\AdvancedReport\Api\Data\SavedReportInterface;
use MageOS\AdvancedReport\Api\Data\SavedReportSearchResultsInterface;
use MageOS\AdvancedReport\Api\Data\SavedReportSearchResultsInterfaceFactory;
use MageOS\AdvancedReport\Api\SavedReportRepositoryInterface;
use MageOS\AdvancedReport\Model\ResourceModel\SavedReport as SavedReportResource;
use MageOS\AdvancedReport\Model\ResourceModel\SavedReport\CollectionFactory;

class SavedReportRepository implements SavedReportRepositoryInterface
{
    public function __construct(
        private readonly SavedReportResource $resource,
        private readonly SavedReportFactory $savedReportFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly SavedReportSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
    ) {
    }

    public function save(SavedReportInterface $savedReport): SavedReportInterface
    {
        try {
            $this->resource->save($this->asModel($savedReport));
        } catch (Exception $e) {
            throw new CouldNotSaveException(__('Could not save the report: %1', $e->getMessage()), $e);
        }

        return $savedReport;
    }

    public function getById(int $id): SavedReportInterface
    {
        $savedReport = $this->savedReportFactory->create();
        $this->resource->load($savedReport, $id);
        if (!$savedReport->getId()) {
            throw new NoSuchEntityException(__('The saved report with id "%1" does not exist.', $id));
        }

        return $savedReport;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SavedReportSearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        /** @var SavedReportInterface[] $items */
        $items = $collection->getItems();
        $searchResults->setItems($items);
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function delete(SavedReportInterface $savedReport): bool
    {
        try {
            $this->resource->delete($this->asModel($savedReport));
        } catch (Exception $e) {
            throw new CouldNotDeleteException(__('Could not delete the report: %1', $e->getMessage()), $e);
        }

        return true;
    }

    public function deleteById(int $id): bool
    {
        return $this->delete($this->getById($id));
    }

    public function getVisibleForUser(int $adminUserId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(
            [SavedReportInterface::ADMIN_USER_ID, SavedReportInterface::IS_SHARED],
            [['eq' => $adminUserId], ['eq' => 1]],
        );
        $collection->setOrder(SavedReportInterface::NAME);

        /** @var SavedReportInterface[] $items */
        $items = $collection->getItems();

        return array_values($items);
    }

    private function asModel(SavedReportInterface $savedReport): AbstractModel
    {
        if (!$savedReport instanceof AbstractModel) {
            throw new InvalidArgumentException('The saved report must be a persistable model.');
        }

        return $savedReport;
    }
}
