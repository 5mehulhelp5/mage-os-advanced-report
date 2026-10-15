<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * A named SQL join usable by field and metric expressions of a dataset.
 */
final readonly class Join
{
    /**
     * @param string $code
     * @param string $table
     * @param string $alias
     * @param string $condition
     * @param string[] $requires Join codes that must be applied before this one.
     * @param JoinCardinality $cardinality Whether the join can match many rows per base row.
     */
    public function __construct(
        public string $code,
        public string $table,
        public string $alias,
        public string $condition,
        public array $requires = [],
        public JoinCardinality $cardinality = JoinCardinality::ONE,
    ) {
    }
}
