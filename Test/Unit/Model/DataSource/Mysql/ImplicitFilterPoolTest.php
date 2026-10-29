<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\CountedStatusCondition;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionResolver;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ImplicitFilterPool;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImplicitFilterPool::class)]
final class ImplicitFilterPoolTest extends TestCase
{
    public function testItBuildsAnItemsCountGreaterThanZeroConditionForTheNonEmptyCartFilter(): void
    {
        $conditions = $this->pool()->getConditions(
            $this->dataset(['non_empty_cart' => 'non_empty_cart'], 'main.items_count'),
            $this->createStub(AdapterInterface::class)
        );

        self::assertSame(['main.items_count > 0'], $conditions);
    }

    public function testItResolvesTheItemsCountExpressionThroughTheDatasetFieldMapping(): void
    {
        $conditions = $this->pool()->getConditions(
            $this->dataset(['non_empty_cart' => 'non_empty_cart'], 'cart.qty_total'),
            $this->createStub(AdapterInterface::class)
        );

        self::assertSame(['cart.qty_total > 0'], $conditions);
    }

    public function testItReportsTheItemsCountFieldCodeForJoinResolutionWhenNonEmptyCartIsDeclared(): void
    {
        $dataset = $this->dataset(['non_empty_cart' => 'non_empty_cart'], 'main.items_count');

        self::assertSame([ImplicitFilterPool::ITEMS_COUNT_FIELD], $this->pool()->getFieldCodes($dataset));
    }

    public function testItReportsNoExtraFieldCodesWhenNonEmptyCartIsNotDeclared(): void
    {
        self::assertSame([], $this->pool()->getFieldCodes($this->dataset([], 'main.items_count')));
    }

    private function pool(): ImplicitFilterPool
    {
        return new ImplicitFilterPool(
            $this->createStub(CountedStatusCondition::class),
            new FieldExpressionResolver()
        );
    }

    /**
     * @param array<string,mixed> $implicitFilters
     */
    private function dataset(array $implicitFilters, string $itemsCountExpression): Dataset
    {
        return new Dataset(
            'carts',
            'Carts',
            'acl',
            'quote',
            true,
            'quote',
            'main',
            null,
            $implicitFilters,
            [
                'items_count' => new Field(
                    'items_count',
                    'Items count',
                    FieldType::INTEGER,
                    ['mysql' => new SourceMapping($itemsCountExpression, null, [])]
                ),
            ],
            [],
            [],
            [],
        );
    }
}
