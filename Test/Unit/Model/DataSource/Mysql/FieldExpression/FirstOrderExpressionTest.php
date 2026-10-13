<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql\FieldExpression;

use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\CountedStatusCondition;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression\FirstOrderExpression;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use MageOS\AdvancedReport\Test\Unit\Helper\SelectTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FirstOrderExpression::class)]
final class FirstOrderExpressionTest extends TestCase
{
    use SelectTestTrait;

    public function testItChecksEarlierOrdersByCustomerIdForRegisteredCustomers(): void
    {
        $sql = $this->expression();

        self::assertStringContainsString(
            'CASE WHEN main.customer_id IS NOT NULL THEN NOT EXISTS (SELECT 1 FROM sales_order AS p '
            . 'WHERE p.customer_id = main.customer_id AND',
            $sql
        );
    }

    public function testItChecksEarlierGuestOrdersByEmailForGuests(): void
    {
        self::assertStringContainsString(
            'ELSE NOT EXISTS (SELECT 1 FROM sales_order AS p WHERE p.customer_id IS NULL '
            . 'AND p.customer_email = main.customer_email AND',
            $this->expression()
        );
    }

    public function testItAppliesTheCountedStatusConditionToEarlierOrders(): void
    {
        $sql = $this->expression(['complete', 'processing']);

        self::assertSame(2, substr_count($sql, "p.status IN ('complete', 'processing')"));
    }

    public function testItBreaksCreatedAtTiesByEntityIdWithASargableCreatedAtRangeInsteadOfARowComparison(): void
    {
        $sql = $this->expression();

        self::assertSame(
            2,
            substr_count(
                $sql,
                'p.created_at <= main.created_at AND (p.created_at < main.created_at OR p.entity_id < main.entity_id)'
            )
        );
        self::assertStringNotContainsString('(p.created_at, p.entity_id)', $sql);
    }

    public function testItUsesTheOrderAliasOfTheDataset(): void
    {
        $sql = $this->expression([], 'order');

        self::assertStringContainsString('order.customer_id IS NOT NULL', $sql);
        self::assertStringContainsString('p.customer_email = order.customer_email', $sql);
        self::assertStringNotContainsString('main.', $sql);
    }

    /**
     * @param string[] $statuses
     */
    private function expression(array $statuses = [], string $alias = 'main'): string
    {
        $config = $this->createStub(Config::class);
        $config->method('getCountedStatuses')->willReturn($statuses);

        return $this->normalizeSql(
            (new FirstOrderExpression(new CountedStatusCondition($config)))
                ->getExpression($this->dataset($alias), $this->createSqlAdapter())
        );
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
