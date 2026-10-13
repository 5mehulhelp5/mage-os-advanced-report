<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

/**
 * Labels weekday numbers, 0 being Sunday.
 */
class WeekdayLabelResolver extends AbstractLabelResolver
{
    protected function loadLabels(array $values, array $context): array
    {
        return [
            '0' => (string)__('Sunday'),
            '1' => (string)__('Monday'),
            '2' => (string)__('Tuesday'),
            '3' => (string)__('Wednesday'),
            '4' => (string)__('Thursday'),
            '5' => (string)__('Friday'),
            '6' => (string)__('Saturday'),
        ];
    }
}
