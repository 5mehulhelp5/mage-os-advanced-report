<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Definition;

use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Field::class)]
final class FieldTest extends TestCase
{
    public function testItReportsWhetherAFieldIsMappedForAGivenSource(): void
    {
        $field = new Field(
            code: 'net_sales',
            label: 'Net Sales',
            type: FieldType::MONEY,
            sourceMappings: [
                'mysql' => new SourceMapping(expression: 'main.base_subtotal'),
            ],
        );

        self::assertTrue($field->isMappedForSource('mysql'));
        self::assertFalse($field->isMappedForSource('opensearch'));
    }
}
