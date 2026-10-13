<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

use Magento\Framework\Phrase;

/**
 * A queryable subject (e.g. "sales_orders"): its fields, metrics, dimensions and joins.
 *
 * Reserved field codes drive engine behaviour instead of hard-coded columns:
 * - {@see FIELD_CREATED_AT}: date-range filter and date buckets, required unless dateless.
 * - {@see FIELD_STORE_ID}: store scope filter and per-store timezone; optional.
 * - {@see FIELD_STATUS}: order status, backs the "counted_status" implicit filter.
 */
final readonly class Dataset
{
    public const string FIELD_CREATED_AT = 'created_at';
    public const string FIELD_STORE_ID = 'store_id';
    public const string FIELD_STATUS = 'status';

    /**
     * @param string $code
     * @param string $label
     * @param string $aclResource
     * @param string $area
     * @param bool $dateless
     * @param string|null $baseTable
     * @param string|null $baseAlias
     * @param string|null $baseProvider
     * @param array<string,mixed> $implicitFilters
     * @param array<string,Field> $fields
     * @param array<string,Join> $joins
     * @param array<string,Metric> $metrics
     * @param array<string,Dimension> $dimensions
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $aclResource,
        public string $area,
        public bool $dateless,
        public ?string $baseTable,
        public ?string $baseAlias,
        public ?string $baseProvider,
        public array $implicitFilters,
        public array $fields,
        public array $joins,
        public array $metrics,
        public array $dimensions,
    ) {
    }

    /**
     * Whether a field with the given code is declared on this dataset.
     *
     * @param string $code
     */
    public function hasField(string $code): bool
    {
        return isset($this->fields[$code]);
    }

    /**
     * Look up a declared field by code.
     *
     * @param string $code
     * @throws NoSuchDefinitionException
     */
    public function getField(string $code): Field
    {
        return $this->fields[$code] ?? throw $this->noSuchDefinition('field', $code);
    }

    /**
     * Look up a declared metric by code.
     *
     * @param string $code
     * @throws NoSuchDefinitionException
     */
    public function getMetric(string $code): Metric
    {
        return $this->metrics[$code] ?? throw $this->noSuchDefinition('metric', $code);
    }

    /**
     * Look up a declared dimension by code.
     *
     * @param string $code
     * @throws NoSuchDefinitionException
     */
    public function getDimension(string $code): Dimension
    {
        return $this->dimensions[$code] ?? throw $this->noSuchDefinition('dimension', $code);
    }

    /**
     * Look up a declared join by code.
     *
     * @param string $code
     * @throws NoSuchDefinitionException
     */
    public function getJoin(string $code): Join
    {
        return $this->joins[$code] ?? throw $this->noSuchDefinition('join', $code);
    }

    /**
     * Build the "no such definition" exception for a missing lookup code.
     *
     * @param string $kind
     * @param string $code
     */
    private function noSuchDefinition(string $kind, string $code): NoSuchDefinitionException
    {
        return new NoSuchDefinitionException(
            new Phrase('Dataset "%1" has no %2 "%3".', [$this->code, $kind, $code])
        );
    }
}
