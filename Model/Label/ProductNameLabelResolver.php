<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

class ProductNameLabelResolver extends AbstractLabelResolver
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    protected function loadLabels(array $values, array $context): array
    {
        $criteria = $this->searchCriteriaBuilder->addFilter('entity_id', $values, 'in')->create();

        $labels = [];
        foreach ($this->productRepository->getList($criteria)->getItems() as $product) {
            $labels[(string)$product->getId()] = (string)$product->getName();
        }

        return $labels;
    }
}
