<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

/**
 * Labels month numbers 1 to 12 (unpadded, as MySQL MONTH() returns them).
 */
class MonthLabelResolver extends AbstractLabelResolver
{
    protected function loadLabels(array $values, array $context): array
    {
        return [
            '1' => (string)__('January'),
            '2' => (string)__('February'),
            '3' => (string)__('March'),
            '4' => (string)__('April'),
            '5' => (string)__('May'),
            '6' => (string)__('June'),
            '7' => (string)__('July'),
            '8' => (string)__('August'),
            '9' => (string)__('September'),
            '10' => (string)__('October'),
            '11' => (string)__('November'),
            '12' => (string)__('December'),
        ];
    }
}
