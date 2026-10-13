<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

/**
 * Labels the `is_first_order` flag: 1 is a new customer, 0 a returning one.
 */
class CustomerTypeLabelResolver extends AbstractLabelResolver
{
    protected function loadLabels(array $values, array $context): array
    {
        return [
            '1' => (string)__('New customer'),
            '0' => (string)__('Returning customer'),
        ];
    }
}
