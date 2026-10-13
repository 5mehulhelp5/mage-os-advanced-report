<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Definition;

use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\FilterOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldType::class)]
final class FieldTypeTest extends TestCase
{
    /**
     * @param FilterOperator[] $expected
     */
    #[DataProvider('fieldTypeProvider')]
    public function testItExposesFieldAllowedOperatorsByFieldType(FieldType $fieldType, array $expected): void
    {
        self::assertSame($expected, $fieldType->allowedOperators());
    }

    /**
     * @return array<string, array{0: FieldType, 1: FilterOperator[]}>
     */
    public static function fieldTypeProvider(): array
    {
        $equalityOnly = [
            FilterOperator::EQ,
            FilterOperator::NEQ,
            FilterOperator::IN,
            FilterOperator::NIN,
        ];
        $all = [
            FilterOperator::EQ,
            FilterOperator::NEQ,
            FilterOperator::IN,
            FilterOperator::NIN,
            FilterOperator::GTE,
            FilterOperator::LTE,
            FilterOperator::BETWEEN,
        ];

        return [
            'string' => [FieldType::STRING, $equalityOnly],
            'id' => [FieldType::ID, $equalityOnly],
            'boolean' => [FieldType::BOOLEAN, $equalityOnly],
            'money' => [FieldType::MONEY, $all],
            'integer' => [FieldType::INTEGER, $all],
            'decimal' => [FieldType::DECIMAL, $all],
            'date' => [FieldType::DATE, $all],
        ];
    }
}
