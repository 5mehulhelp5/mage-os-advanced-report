<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Labels customer gender option ids with the (translated) gender attribute option labels.
 */
class GenderLabelResolver extends AbstractLabelResolver
{
    public function __construct(
        private readonly CustomerMetadataInterface $customerMetadata
    ) {
    }

    protected function loadLabels(array $values, array $context): array
    {
        try {
            $options = $this->customerMetadata->getAttributeMetadata('gender')->getOptions();
        } catch (LocalizedException) {
            return [];
        }

        $labels = [];
        foreach ($options as $option) {
            $value = $option->getValue();
            if ($value === null || $value === '') {
                continue;
            }
            $labels[(string)$value] = (string)__((string)$option->getLabel());
        }

        return $labels;
    }
}
