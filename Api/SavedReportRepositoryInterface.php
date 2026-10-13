<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\AdvancedReport\Api\Data\SavedReportInterface;
use MageOS\AdvancedReport\Api\Data\SavedReportSearchResultsInterface;

interface SavedReportRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(SavedReportInterface $savedReport): SavedReportInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $id): SavedReportInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): SavedReportSearchResultsInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(SavedReportInterface $savedReport): bool;

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $id): bool;

    /**
     * Reports owned by the user plus all shared reports.
     *
     * @return SavedReportInterface[]
     */
    public function getVisibleForUser(int $adminUserId): array;
}
