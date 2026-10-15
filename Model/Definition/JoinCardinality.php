<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * How many rows a join can match per base row; `many` joins can multiply rows and inflate aggregates.
 */
enum JoinCardinality: string
{
    case ONE = 'one';
    case MANY = 'many';
}
