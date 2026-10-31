<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql\FieldExpression;

use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression\DynamicBundleRollupExpression;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Test\Unit\Helper\SelectTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DynamicBundleRollupExpression::class)]
final class DynamicBundleRollupExpressionTest extends TestCase
{
    use SelectTestTrait;

    public function testItSumsTheChildrenOnlyForDynamicPriceBundleLinesAndKeepsTheLineExpressionOtherwise(): void
    {
        $sql = $this->expression();

        self::assertStringStartsWith("(CASE WHEN main.product_type = 'bundle' AND", $sql);
        self::assertStringContainsString("'$.product_calculations')) END) = '0' THEN (SELECT SUM(bc.x) FROM", $sql);
        self::assertStringEndsWith('ELSE main.x END)', $sql);
    }

    public function testItCorrelatesTheChildrenThroughTheOrderIdAndParentItemId(): void
    {
        self::assertStringContainsString(
            'FROM sales_order_item AS bc WHERE bc.order_id = main.order_id AND bc.parent_item_id = main.item_id',
            $this->expression()
        );
    }

    public function testItRendersTheChildrenSubqueryAgainstThePrefixedItemTable(): void
    {
        $sql = (new DynamicBundleRollupExpression('main.x', 'SUM(bc.x)', $this->createReportConnection('pre_')))
            ->getExpression($this->dataset(), $this->createSqlAdapter());

        self::assertStringContainsString('FROM pre_sales_order_item AS bc', $sql);
    }

    private function expression(): string
    {
        return $this->normalizeSql(
            (new DynamicBundleRollupExpression('main.x', 'SUM(bc.x)', $this->createReportConnection()))
                ->getExpression($this->dataset(), $this->createSqlAdapter())
        );
    }

    private function dataset(): Dataset
    {
        return new Dataset('d', 'D', 'acl', 'adminhtml', false, 'sales_order_item', 'main', null, [], [], [], [], []);
    }
}
