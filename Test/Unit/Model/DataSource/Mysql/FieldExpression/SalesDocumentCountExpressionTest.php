<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql\FieldExpression;

use InvalidArgumentException;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpression\SalesDocumentCountExpression;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use MageOS\AdvancedReport\Test\Unit\Helper\SelectTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SalesDocumentCountExpression::class)]
final class SalesDocumentCountExpressionTest extends TestCase
{
    use SelectTestTrait;

    public function testItCountsDistinctDocumentsJoinedToTheirItemsThroughTheDocumentOrderId(): void
    {
        $sql = $this->expression();

        self::assertStringContainsString('COUNT(DISTINCT d.entity_id)', $sql);
        self::assertStringNotContainsString('COUNT(*)', $sql);
        self::assertStringContainsString('FROM sales_shipment AS d', $sql);
        self::assertStringContainsString('INNER JOIN sales_shipment_item AS di ON di.parent_id = d.entity_id', $sql);
        self::assertStringContainsString('WHERE d.order_id = main.order_id', $sql);
    }

    public function testItMatchesDocumentItemsReferencingTheRowItemOrItsConfigurableChildItem(): void
    {
        $sql = $this->expression();

        self::assertStringContainsString('INNER JOIN sales_order_item AS oi ON oi.item_id = di.order_item_id', $sql);
        self::assertStringContainsString(
            "(oi.item_id = main.item_id OR (oi.parent_item_id = main.item_id AND main.product_type = 'configurable'))",
            $sql
        );
    }

    public function testItIgnoresDocumentItemsWithZeroQuantity(): void
    {
        self::assertStringContainsString('di.qty > 0', $this->expression());
    }

    public function testItExcludesDocumentsInTheConfiguredExcludedStates(): void
    {
        self::assertStringContainsString('AND d.state NOT IN (3, 5)', $this->expression('main', [3, 5]));
    }

    public function testItAddsNoStateConditionWhenNoExcludedStatesAreConfigured(): void
    {
        self::assertStringNotContainsString('d.state', $this->expression());
    }

    public function testItResolvesAllTableNamesThroughTheConnectionSoTheTablePrefixIsApplied(): void
    {
        $adapter = $this->createStub(Mysql::class);
        $adapter->method('getTableName')->willReturnCallback(
            static fn (string $name): string => 'pre_' . $name
        );

        $sql = (new SalesDocumentCountExpression('sales_shipment', 'sales_shipment_item'))
            ->getExpression($this->dataset('main'), $adapter);

        self::assertStringContainsString('FROM pre_sales_shipment AS d', $sql);
        self::assertStringContainsString('JOIN pre_sales_shipment_item AS di', $sql);
        self::assertStringContainsString('JOIN pre_sales_order_item AS oi', $sql);
    }

    public function testItDerivesTheItemAliasFromTheDatasetItemIdMapping(): void
    {
        $sql = $this->expression('item');

        self::assertStringContainsString('d.order_id = item.order_id', $sql);
        self::assertStringContainsString('oi.item_id = item.item_id', $sql);
        self::assertStringContainsString("item.product_type = 'configurable'", $sql);
        self::assertStringNotContainsString('main.', $sql);
    }

    public function testItThrowsWhenTheDatasetItemIdMappingIsNotAnAliasItemIdColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->expression('main', [], 'COALESCE(main.item_id, 0)');
    }

    public function testItReturnsAParenthesizedScalarSubqueryWithoutPlaceholdersSoItCanBeSummedAndUsedAsAFilterOperand(): void
    {
        $sql = $this->expression('main', [3]);

        self::assertStringStartsWith('(SELECT', $sql);
        self::assertStringEndsWith(')', $sql);
        self::assertStringNotContainsString('?', $sql);
    }

    /**
     * @param int[] $excludedStates
     */
    private function expression(string $alias = 'main', array $excludedStates = [], ?string $mapping = null): string
    {
        return $this->normalizeSql(
            (new SalesDocumentCountExpression('sales_shipment', 'sales_shipment_item', $excludedStates))
                ->getExpression($this->dataset($alias, $mapping), $this->createSqlAdapter())
        );
    }

    private function dataset(string $alias, ?string $mapping = null): Dataset
    {
        $itemId = new Field(
            'item_id',
            'Item id',
            FieldType::INTEGER,
            ['mysql' => new SourceMapping($mapping ?? $alias . '.item_id')]
        );

        return new Dataset('d', 'D', 'acl', 'adminhtml', false, 'sales_order_item', $alias, null, [], [
            'item_id' => $itemId,
        ], [], [], []);
    }
}
