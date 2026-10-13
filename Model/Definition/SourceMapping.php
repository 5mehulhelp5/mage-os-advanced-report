<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * How a field is resolved for a given data source (e.g. "mysql"): either a raw SQL
 * expression or a named expression provider, plus the joins it needs.
 */
final readonly class SourceMapping
{
    /**
     * @param string|null $expression
     * @param string|null $expressionProvider
     * @param string[] $joins Join codes required to build the expression.
     */
    public function __construct(
        public ?string $expression = null,
        public ?string $expressionProvider = null,
        public array $joins = [],
    ) {
    }

    /**
     * Whether this mapping resolves the field for its source (expression or provider set).
     */
    public function isMapped(): bool
    {
        return $this->expression !== null || $this->expressionProvider !== null;
    }
}
