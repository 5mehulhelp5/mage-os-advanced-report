<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use Magento\Catalog\Api\CategoryListInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

class CategoryLabelResolver extends AbstractLabelResolver
{
    public function __construct(
        private readonly CategoryListInterface $categoryList,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    protected function loadLabels(array $values, array $context): array
    {
        $criteria = $this->searchCriteriaBuilder->addFilter('entity_id', $values, 'in')->create();

        $labels = [];
        foreach ($this->categoryList->getList($criteria)->getItems() as $category) {
            $labels[(string)$category->getId()] = (string)$category->getName();
        }

        return $labels;
    }
}
