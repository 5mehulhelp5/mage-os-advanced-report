<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * A reportable grouping axis, backed by a field. Only date dimensions declare
 * granularities; other dimensions group by the raw (or resolved) field value.
 */
final readonly class Dimension
{
    /**
     * @param string $code
     * @param string $label
     * @param string $fieldCode
     * @param Granularity[] $granularities Allowed date bucket sizes; empty for non-date dimensions.
     * @param string|null $labelResolver
     * @param array<string,string> $labelContext e.g. ['attribute_code' => 'color'] for synthesized dimensions.
     * @param bool $nonAdditive
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $fieldCode,
        public array $granularities = [],
        public ?string $labelResolver = null,
        public array $labelContext = [],
        public bool $nonAdditive = false,
    ) {
    }

    /**
     * Allowed date bucket sizes; empty for non-date dimensions.
     *
     * @return Granularity[]
     */
    public function getGranularities(): array
    {
        return $this->granularities;
    }
}
