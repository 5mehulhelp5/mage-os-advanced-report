<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use InvalidArgumentException;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Turns the implicit filter codes declared on a dataset into SQL conditions.
 */
class ImplicitFilterPool
{
    public const string COUNTED_STATUS = 'counted_status';
    public const string TOP_LEVEL_ITEM = 'top_level_item';

    public function __construct(
        private readonly CountedStatusCondition $countedStatusCondition,
        private readonly FieldExpressionResolver $fieldExpressionResolver,
    ) {
    }

    /**
     * Dataset field codes the implicit filters' conditions rely on (for join resolution).
     *
     * @param Dataset $dataset
     * @return string[]
     */
    public function getFieldCodes(Dataset $dataset): array
    {
        return isset($dataset->implicitFilters[self::COUNTED_STATUS]) ? [Dataset::FIELD_STATUS] : [];
    }

    /**
     * SQL conditions of the dataset's implicit filters.
     *
     * @param Dataset $dataset
     * @param AdapterInterface $connection
     * @throws InvalidArgumentException For unknown implicit filter codes.
     * @return string[]
     */
    public function getConditions(Dataset $dataset, AdapterInterface $connection): array
    {
        $conditions = [];
        foreach (array_keys($dataset->implicitFilters) as $code) {
            $conditions[] = match ($code) {
                self::COUNTED_STATUS => $this->countedStatusCondition->build(
                    $this->fieldExpressionResolver->resolve($dataset, Dataset::FIELD_STATUS, $connection),
                    $connection
                ),
                self::TOP_LEVEL_ITEM => $dataset->baseAlias . '.parent_item_id IS NULL',
                default => throw new InvalidArgumentException(
                    \sprintf('Unknown implicit filter "%s".', $code)
                ),
            };
        }

        return $conditions;
    }
}
