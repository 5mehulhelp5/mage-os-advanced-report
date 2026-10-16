<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql\FieldExpression;

use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression\OrderUnitsNetOfCanceledExpression;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use MageOS\AdvancedReport\Test\Unit\Helper\SelectTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderUnitsNetOfCanceledExpression::class)]
final class OrderUnitsNetOfCanceledExpressionTest extends TestCase
{
    use SelectTestTrait;

    public function testItBuildsUnitsAsOrderedQuantityMinusACanceledQuantitySubqueryRestrictedToParentOrderItems(): void
    {
        self::assertSame(
            '(COALESCE(main.total_qty_ordered, 0) - (SELECT COALESCE(SUM(oi.qty_canceled), 0) '
            . 'FROM sales_order_item AS oi WHERE oi.order_id = main.entity_id AND oi.parent_item_id IS NULL))',
            $this->normalizeSql($this->expression('main'))
        );
    }

    public function testItUsesTheOrderAliasOfTheDataset(): void
    {
        $sql = $this->expression('order');

        self::assertStringContainsString('COALESCE(order.total_qty_ordered, 0)', $sql);
        self::assertStringContainsString('oi.order_id = order.entity_id', $sql);
    }

    private function expression(string $alias): string
    {
        return (new OrderUnitsNetOfCanceledExpression())
            ->getExpression($this->dataset($alias), $this->createSqlAdapter());
    }

    private function dataset(string $alias): Dataset
    {
        $createdAt = new Field(
            Dataset::FIELD_CREATED_AT,
            'Created at',
            FieldType::DATE,
            ['mysql' => new SourceMapping($alias . '.created_at')]
        );

        return new Dataset('d', 'D', 'acl', 'adminhtml', false, 'sales_order', $alias, null, [], [
            Dataset::FIELD_CREATED_AT => $createdAt,
        ], [], [], []);
    }
}
