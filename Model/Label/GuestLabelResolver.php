<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

/**
 * Labels the `is_guest` flag: 1 is a guest, 0 a registered customer.
 */
class GuestLabelResolver extends AbstractLabelResolver
{
    protected function loadLabels(array $values, array $context): array
    {
        return [
            '1' => (string)__('Guest'),
            '0' => (string)__('Registered customer'),
        ];
    }
}
