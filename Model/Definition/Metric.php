<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * A reportable measure: either an aggregate over a field, a formula derived from other
 * metrics, or a virtual value produced only by a preset query (e.g. coverage_days).
 */
final readonly class Metric
{
    /**
     * @param string $code
     * @param string $label
     * @param string|null $description
     * @param string $format One of money|number|percent|integer.
     * @param bool $hidden
     * @param bool $lowerIsBetter Whether a lower value is an improvement (e.g. returns, discounts).
     * @param AggregateFunction|null $aggregate
     * @param string|null $fieldCode
     * @param string|null $conditionFieldCode
     * @param string|null $formula Raw formula text; parsed later by the registry.
     * @param bool $virtual
     */
    public function __construct(
        public string $code,
        public string $label,
        public ?string $description,
        public string $format,
        public bool $hidden,
        public bool $lowerIsBetter,
        public ?AggregateFunction $aggregate = null,
        public ?string $fieldCode = null,
        public ?string $conditionFieldCode = null,
        public ?string $formula = null,
        public bool $virtual = false,
    ) {
    }

    /**
     * Whether this metric is an aggregate over a field.
     */
    public function isAggregate(): bool
    {
        return $this->aggregate !== null;
    }

    /**
     * Whether this metric is derived from a formula over other metrics.
     */
    public function isDerived(): bool
    {
        return $this->formula !== null;
    }

    /**
     * Whether this metric's value is produced only by a preset query.
     */
    public function isVirtual(): bool
    {
        return $this->virtual;
    }
}
