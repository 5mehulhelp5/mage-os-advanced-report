<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Definition;

use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceMapping::class)]
final class SourceMappingTest extends TestCase
{
    public function testItTreatsAFieldAsMappedWhenItsSourceMappingHasAnExpressionOrAnExpressionProvider(): void
    {
        $withExpression = new SourceMapping(expression: 'main.base_subtotal');
        $withProvider = new SourceMapping(expressionProvider: 'first_order');
        $unmapped = new SourceMapping();

        self::assertTrue($withExpression->isMapped());
        self::assertTrue($withProvider->isMapped());
        self::assertFalse($unmapped->isMapped());
    }
}
