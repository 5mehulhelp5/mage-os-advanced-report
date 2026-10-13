<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use MageOS\AdvancedReport\Model\DataSource\Mysql\JoinRegistry;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\Join;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JoinRegistry::class)]
final class JoinRegistryTest extends TestCase
{
    private JoinRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new JoinRegistry();
    }

    public function testItReturnsNoJoinsWhenOnlyBaseTableFieldsAreUsed(): void
    {
        self::assertSame([], $this->registry->resolve($this->dataset(), ['id']));
    }

    public function testItReturnsJoinsRequiredByUsedFields(): void
    {
        $joins = $this->registry->resolve($this->dataset(), ['id', 'order_status']);

        self::assertSame(['order'], $this->codes($joins));
    }

    public function testItIncludesTransitiveRequiredJoinsInDependencyOrder(): void
    {
        $joins = $this->registry->resolve($this->dataset(), ['attr_value']);

        self::assertSame(['child', 'attr'], $this->codes($joins));
    }

    public function testItDeduplicatesJoinsUsedBySeveralFields(): void
    {
        $joins = $this->registry->resolve($this->dataset(), ['attr_value', 'order_status', 'child_sku', 'order_date']);

        self::assertSame(['child', 'attr', 'order'], $this->codes($joins));
    }

    public function testItThrowsWhenAFieldReferencesAnUnknownJoin(): void
    {
        $this->expectException(NoSuchDefinitionException::class);

        $this->registry->resolve($this->dataset(), ['broken']);
    }

    /**
     * @param Join[] $joins
     * @return string[]
     */
    private function codes(array $joins): array
    {
        return array_map(static fn (Join $join): string => $join->code, $joins);
    }

    private function dataset(): Dataset
    {
        $field = static fn (string $code, array $joins): Field => new Field(
            $code,
            $code,
            FieldType::STRING,
            ['mysql' => new SourceMapping('x.' . $code, null, $joins)],
        );

        return new Dataset(
            'd',
            'D',
            'acl',
            'sales',
            false,
            'base',
            'main',
            null,
            [],
            [
                'id' => $field('id', []),
                'order_status' => $field('order_status', ['order']),
                'order_date' => $field('order_date', ['order']),
                'child_sku' => $field('child_sku', ['child']),
                'attr_value' => $field('attr_value', ['attr']),
                'broken' => $field('broken', ['nope']),
            ],
            [
                'order' => new Join('order', 'sales_order', 'order', 'order.entity_id = main.order_id'),
                'child' => new Join('child', 'sales_order_item', 'child', 'child.x = main.y'),
                'attr' => new Join('attr', 'eav', 'attr', 'attr.entity_id = child.product_id', ['child']),
            ],
            [],
            [],
        );
    }
}
