<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * Semantic data type of a report field, used to whitelist filter operators.
 */
enum FieldType: string
{
    case MONEY = 'money';
    case INTEGER = 'integer';
    case DECIMAL = 'decimal';
    case BOOLEAN = 'boolean';
    case STRING = 'string';
    case ID = 'id';
    case DATE = 'date';

    /**
     * Operators the query engine is allowed to build for this field type.
     *
     * @return FilterOperator[]
     */
    public function allowedOperators(): array
    {
        $equalityOnly = [
            FilterOperator::EQ,
            FilterOperator::NEQ,
            FilterOperator::IN,
            FilterOperator::NIN,
        ];

        return match ($this) {
            self::STRING, self::ID, self::BOOLEAN => $equalityOnly,
            self::MONEY, self::INTEGER, self::DECIMAL, self::DATE => [
                ...$equalityOnly,
                FilterOperator::GTE,
                FilterOperator::LTE,
                FilterOperator::BETWEEN,
            ],
        };
    }
}
