<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * A reportable field, mapped to a concrete expression per data source.
 */
final readonly class Field
{
    /**
     * @param string $code
     * @param string $label
     * @param FieldType $type
     * @param array<string,SourceMapping> $sourceMappings Keyed by source name (e.g. "mysql").
     * @param string|null $description
     */
    public function __construct(
        public string $code,
        public string $label,
        public FieldType $type,
        public array $sourceMappings = [],
        public ?string $description = null,
    ) {
    }

    /**
     * Whether this field has a usable mapping (expression or provider) for the given source.
     *
     * @param string $source
     */
    public function isMappedForSource(string $source): bool
    {
        return isset($this->sourceMappings[$source]) && $this->sourceMappings[$source]->isMapped();
    }
}
