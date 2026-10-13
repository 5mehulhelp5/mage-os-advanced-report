<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

/**
 * A single sort clause: a metric or dimension code and its direction.
 */
final readonly class SortOrder
{
    /**
     * @param string $by Metric or dimension code.
     * @param string $direction One of asc|desc.
     */
    public function __construct(
        public string $by,
        public string $direction = 'asc',
    ) {
    }
}
