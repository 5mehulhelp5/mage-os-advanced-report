<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Definition;

use MageOS\AdvancedReport\Model\Definition\DefinitionFactory;
use MageOS\AdvancedReport\Model\Definition\InvalidDefinitionException;
use MageOS\AdvancedReport\Model\Definition\JoinCardinality;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinitionFactory::class)]
final class DefinitionFactoryTest extends TestCase
{
    public function testItBuildsJoinValueObjectWithManyCardinality(): void
    {
        $dataset = (new DefinitionFactory())->createDataset($this->rawDataset(['cardinality' => 'many']));

        self::assertSame(JoinCardinality::MANY, $dataset->joins['category']->cardinality);
    }

    public function testItRejectsUnknownJoinCardinalityValueWithInvalidDefinitionException(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        (new DefinitionFactory())->createDataset($this->rawDataset(['cardinality' => 'bogus']));
    }

    public function testItDefaultsJoinCardinalityToOneWhenTheRawJoinArrayHasNoCardinalityKey(): void
    {
        $dataset = (new DefinitionFactory())->createDataset($this->rawDataset([]));

        self::assertSame(JoinCardinality::ONE, $dataset->joins['category']->cardinality);
    }

    public function testItBuildsFieldsWithTheDeclaredAcl(): void
    {
        $raw = $this->rawDataset([]);
        $raw['fields'] = ['birth_year' => $this->rawField(['acl' => 'Magento_Sales::actions_view'])];

        $dataset = (new DefinitionFactory())->createDataset($raw);

        self::assertSame('Magento_Sales::actions_view', $dataset->fields['birth_year']->acl);
    }

    public function testItDefaultsTheFieldAclToNullForDefinitionsCachedWithoutIt(): void
    {
        $raw = $this->rawDataset([]);
        $raw['fields'] = ['birth_year' => $this->rawField([])];

        $dataset = (new DefinitionFactory())->createDataset($raw);

        self::assertNull($dataset->fields['birth_year']->acl);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function rawField(array $overrides): array
    {
        return [
            'code' => 'birth_year',
            'label' => 'Year of birth',
            'type' => 'integer',
            'description' => null,
            'sourceMappings' => ['mysql' => ['expression' => 'YEAR(main.customer_dob)', 'expressionProvider' => null, 'joins' => []]],
        ] + $overrides;
    }

    /**
     * @param array<string,mixed> $joinOverrides
     * @return array<string,mixed>
     */
    private function rawDataset(array $joinOverrides): array
    {
        return [
            'code' => 'sales_items',
            'label' => 'Items',
            'aclResource' => 'MageOS_AdvancedReport::sales',
            'area' => 'sales',
            'dateless' => false,
            'sources' => ['mysql' => ['name' => 'mysql', 'table' => 'sales_order_item', 'alias' => 'main', 'provider' => null]],
            'implicitFilters' => [],
            'joins' => [
                'category' => [
                    'code' => 'category',
                    'table' => 'catalog_category_product',
                    'alias' => 'category',
                    'type' => 'left',
                    'condition' => 'category.product_id = main.product_id',
                    'requires' => [],
                ] + $joinOverrides,
            ],
            'fields' => [],
            'metrics' => [],
            'dimensions' => [],
        ];
    }
}
