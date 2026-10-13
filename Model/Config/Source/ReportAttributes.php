<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Product attributes usable as report dimensions: select and multiselect attributes only.
 */
class ReportAttributes implements OptionSourceInterface
{
    /**
     * @var string[]
     */
    private const array ALLOWED_FRONTEND_INPUTS = ['select', 'multiselect'];

    /**
     * @param CollectionFactory $attributeCollectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $attributeCollectionFactory,
    ) {
    }

    /**
     * Return select/multiselect product attributes as value-label pairs, sorted by label.
     *
     * @return array<int,array{value:string,label:string}>
     */
    public function toOptionArray(): array
    {
        $collection = $this->attributeCollectionFactory->create();
        $collection->addFieldToFilter('frontend_input', ['in' => self::ALLOWED_FRONTEND_INPUTS]);

        $options = [];
        foreach ($collection as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            $label = $attribute->getData('frontend_label');
            $options[] = [
                'value' => $code,
                'label' => $label !== null && $label !== '' ? (string) $label : $code,
            ];
        }

        usort($options, static fn (array $left, array $right): int => $left['label'] <=> $right['label']);

        return $options;
    }
}
