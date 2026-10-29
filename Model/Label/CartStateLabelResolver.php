<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use MageOS\AdvancedReport\Model\Cart\CartState;

/**
 * Labels the raw `cart_state` values.
 */
class CartStateLabelResolver extends AbstractLabelResolver
{
    protected function loadLabels(array $values, array $context): array
    {
        $labels = [];
        foreach (CartState::cases() as $state) {
            $labels[$state->value] = $state->label();
        }

        return $labels;
    }
}
