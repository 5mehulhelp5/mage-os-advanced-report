<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;

/**
 * Builds the SQL expression a dimension groups by: timezone-aware date buckets for date
 * dimensions, the plain field expression otherwise.
 */
class DimensionExpressionBuilder
{
    /**
     * @param array<string,string> $bucketByDimension Bucket of date dimensions without granularities, by code.
     */
    public function __construct(
        private readonly FieldExpressionResolver $fieldExpressionResolver,
        private readonly TimezoneStrategy $timezoneStrategy,
        private readonly array $bucketByDimension = ['order_hour' => 'hour_of_day', 'order_weekday' => 'weekday'],
    ) {
    }

    /**
     * Grouping expression of the selected dimension.
     *
     * @param Dataset $dataset
     * @param DimensionSelection $selection
     * @param int[] $storeIds
     * @param AdapterInterface $connection
     */
    public function build(
        Dataset $dataset,
        DimensionSelection $selection,
        array $storeIds,
        AdapterInterface $connection
    ): string {
        $dimension = $dataset->getDimension($selection->code);
        $expression = $this->fieldExpressionResolver->resolve($dataset, $dimension->fieldCode, $connection);
        $bucket = $this->getBucket($dataset, $selection);
        if ($bucket === null) {
            return $expression;
        }

        return $this->timezoneStrategy->bucket(
            $bucket,
            $expression,
            $this->storeIdExpression($dataset, $connection),
            $storeIds
        );
    }

    /**
     * Whether the dimension groups by a date bucket (date-bucket, hour-of-day or weekday).
     *
     * @param Dataset $dataset
     * @param DimensionSelection $selection
     */
    public function isDateBucket(Dataset $dataset, DimensionSelection $selection): bool
    {
        return $this->getBucket($dataset, $selection) !== null;
    }

    /**
     * Store id expression used by the per-store timezone CASE; constant when the dataset has none.
     *
     * @param Dataset $dataset
     * @param AdapterInterface $connection
     */
    public function storeIdExpression(Dataset $dataset, AdapterInterface $connection): string
    {
        return $dataset->hasField(Dataset::FIELD_STORE_ID)
            ? $this->fieldExpressionResolver->resolve($dataset, Dataset::FIELD_STORE_ID, $connection)
            : '0';
    }

    /**
     * Bucket name (hour|day|week|month|year|hour_of_day|weekday) of a date dimension, null otherwise.
     *
     * @param Dataset $dataset
     * @param DimensionSelection $selection
     */
    public function getBucket(Dataset $dataset, DimensionSelection $selection): ?string
    {
        $dimension = $dataset->getDimension($selection->code);
        if ($dataset->getField($dimension->fieldCode)->type !== FieldType::DATE) {
            return null;
        }

        return $dimension->granularities === []
            ? ($this->bucketByDimension[$dimension->code] ?? null)
            : ($selection->granularity ?? Granularity::DAY)->value;
    }
}
