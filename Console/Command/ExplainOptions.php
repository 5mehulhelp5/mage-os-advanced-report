<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Console\Command;

/**
 * Output and execution switches of `advanced-report:explain`.
 */
final readonly class ExplainOptions
{
    /**
     * @param bool $analyze Run `EXPLAIN ANALYZE` (executes the statements).
     * @param bool $json Print a JSON list instead of tables.
     * @param string|null $compare Comparison mode overriding the preset's own.
     */
    public function __construct(
        public bool $analyze,
        public bool $json,
        public ?string $compare,
    ) {
    }
}
