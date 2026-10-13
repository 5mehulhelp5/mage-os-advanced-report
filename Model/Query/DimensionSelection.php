<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use MageOS\AdvancedReport\Model\Definition\Granularity;

/**
 * A dimension requested by a report request, with an optional date bucket size.
 */
final readonly class DimensionSelection
{
    /**
     * @param string $code
     * @param Granularity|null $granularity Only meaningful for date dimensions.
     */
    public function __construct(
        public string $code,
        public ?Granularity $granularity = null,
    ) {
    }
}
