<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql\FieldExpression;

use InvalidArgumentException;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression\FirstShipmentWaitExpression;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use MageOS\AdvancedReport\Test\Unit\Helper\SelectTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FirstShipmentWaitExpression::class)]
final class FirstShipmentWaitExpressionTest extends TestCase
{
    use SelectTestTrait;

    public function testItMeasuresTheSecondsFromTheOrderCreationToTheEarliestShipmentDividedBy86400(): void
    {
        self::assertStringContainsString(
            'TIMESTAMPDIFF(SECOND, main.created_at, MIN(sh.created_at))) / 86400',
            $this->expression()
        );
    }

    public function testItCorrelatesTheShipmentSubSelectOnTheOrderEntityIdOfTheDatasetAlias(): void
    {
        self::assertStringContainsString('WHERE sh.order_id = order.entity_id)', $this->expression('order'));
    }

    public function testItRendersTheFirstShipmentWaitSubqueryAgainstThePrefixedSalesShipmentTable(): void
    {
        self::assertStringContainsString('FROM pre_sales_shipment AS sh', $this->expression('main', 'pre_'));
    }

    public function testItDerivesTheOrderAliasFromTheCreatedAtMappingOfTheDataset(): void
    {
        $sql = $this->expression('o');

        self::assertStringContainsString('o.created_at', $sql);
        self::assertStringNotContainsString('main.', $sql);
    }

    public function testItThrowsWhenTheCreatedAtMappingIsNotAPlainAliasColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FirstShipmentWaitExpression($this->createReportConnection()))->getExpression(
            $this->dataset('main', 'DATE(main.created_at)'),
            $this->createSqlAdapter()
        );
    }

    public function testItClampsAShipmentDatedBeforeTheOrderToZeroDays(): void
    {
        self::assertStringContainsString('GREATEST(0, TIMESTAMPDIFF(', $this->expression());
    }

    private function expression(string $alias = 'main', string $prefix = ''): string
    {
        return $this->normalizeSql(
            (new FirstShipmentWaitExpression($this->createReportConnection($prefix)))
                ->getExpression($this->dataset($alias), $this->createSqlAdapter())
        );
    }

    private function dataset(string $alias, ?string $mapping = null): Dataset
    {
        $createdAt = new Field(
            Dataset::FIELD_CREATED_AT,
            'Created at',
            FieldType::DATE,
            ['mysql' => new SourceMapping($mapping ?? $alias . '.created_at')]
        );

        return new Dataset('d', 'D', 'acl', 'adminhtml', false, 'sales_order', $alias, null, [], [
            Dataset::FIELD_CREATED_AT => $createdAt,
        ], [], [], []);
    }
}
