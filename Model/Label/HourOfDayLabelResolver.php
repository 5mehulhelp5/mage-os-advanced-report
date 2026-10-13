<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

/**
 * Labels an hour of day (0-23) as "HH:00".
 */
class HourOfDayLabelResolver extends AbstractLabelResolver
{
    protected function loadLabels(array $values, array $context): array
    {
        $labels = [];
        foreach ($values as $value) {
            if (ctype_digit($value) && (int)$value <= 23) {
                $labels[$value] = \sprintf('%02d:00', (int)$value);
            }
        }

        return $labels;
    }
}
