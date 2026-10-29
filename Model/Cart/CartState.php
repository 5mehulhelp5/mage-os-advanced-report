<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Cart;

/**
 * Lifecycle state of a quote as exposed by the `cart_state` dimension.
 */
enum CartState: string
{
    case ACTIVE = 'active';
    case ABANDONED = 'abandoned';
    case UNRECOVERABLE = 'unrecoverable';
    case CONVERTED = 'converted';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => (string)__('Active'),
            self::ABANDONED => (string)__('Abandoned'),
            self::UNRECOVERABLE => (string)__('Abandoned without email'),
            self::CONVERTED => (string)__('Converted'),
            self::INACTIVE => (string)__('Inactive'),
        };
    }
}
