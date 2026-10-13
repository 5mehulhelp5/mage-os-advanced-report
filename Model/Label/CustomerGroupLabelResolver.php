<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

class CustomerGroupLabelResolver extends AbstractLabelResolver
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    protected function loadLabels(array $values, array $context): array
    {
        $criteria = $this->searchCriteriaBuilder->addFilter('customer_group_id', $values, 'in')->create();

        $labels = [];
        foreach ($this->groupRepository->getList($criteria)->getItems() as $group) {
            $labels[(string)$group->getId()] = (string)$group->getCode();
        }

        return $labels;
    }
}
