<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Labels option ids of the product attribute named by the context's `attribute_code`.
 */
class AttributeOptionLabelResolver extends AbstractLabelResolver
{
    public function __construct(private readonly ProductAttributeRepositoryInterface $attributeRepository)
    {
    }

    protected function loadLabels(array $values, array $context): array
    {
        $attributeCode = (string)($context['attribute_code'] ?? '');
        if ($attributeCode === '') {
            return [];
        }

        try {
            $options = $this->attributeRepository->get($attributeCode)->getOptions() ?? [];
        } catch (NoSuchEntityException) {
            return [];
        }

        $labels = [];
        foreach ($options as $option) {
            $labels[(string)$option->getValue()] = (string)$option->getLabel();
        }

        return $labels;
    }
}
