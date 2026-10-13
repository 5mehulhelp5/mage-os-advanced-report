<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\AttributeJoinProvider;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use MageOS\AdvancedReport\Model\Label\AttributeOptionLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttributeJoinProvider::class)]
final class AttributeJoinProviderTest extends TestCase
{
    public function testItSynthesizesAttributeFieldsJoinsAndDimensionsForConfiguredSelectAttributesJoinedOnEntityId(): void
    {
        $extended = $this->provider(['color' => ['select', 92]])->extend($this->dataset(['product_id']));

        $join = $extended->getJoin('attr_color');
        self::assertSame('catalog_product_entity_int', $join->table);
        self::assertSame('attr_color', $join->alias);
        self::assertStringContainsString('attr_color.attribute_id = 92', $join->condition);
        self::assertStringContainsString('attr_color.store_id = 0', $join->condition);

        $field = $extended->getField('attr_color');
        self::assertSame(FieldType::STRING, $field->type);
        self::assertSame('attr_color.value', $field->sourceMappings['mysql']->expression);
        self::assertSame(['attr_color'], $field->sourceMappings['mysql']->joins);

        $dimension = $extended->getDimension('attr_color');
        self::assertSame('attr_color', $dimension->fieldCode);
        self::assertSame(
            AttributeOptionLabelResolver::class,
            $dimension->labelResolver
        );
        self::assertSame(['attribute_code' => 'color'], $dimension->labelContext);
    }

    public function testItUsesVarcharValuesForMultiselectAttributes(): void
    {
        $extended = $this->provider(['tags' => ['multiselect', 7]])->extend($this->dataset(['product_id']));

        self::assertSame('catalog_product_entity_varchar', $extended->getJoin('attr_tags')->table);
    }

    public function testItJoinsAttributeValuesOnTheChildSimpleProductWhenPresentAndRequiresTheChildJoin(): void
    {
        $extended = $this->provider(['color' => ['select', 92]])->extend($this->dataset(['product_id']));

        $join = $extended->getJoin('attr_color');
        self::assertStringContainsString(
            'attr_color.entity_id = COALESCE(child.product_id, main.product_id)',
            $join->condition
        );
        self::assertSame(['child'], $join->requires);
    }

    public function testItLeavesDatasetsWithoutProductIdFieldUntouched(): void
    {
        $dataset = $this->dataset(['sku']);

        self::assertSame($dataset, $this->provider(['color' => ['select', 92]])->extend($dataset));
    }

    public function testItSkipsUnknownAndUnsupportedAttributes(): void
    {
        $dataset = $this->dataset(['product_id']);
        $extended = $this->provider(['weight' => ['text', 5], 'ghost' => null])->extend($dataset);

        self::assertSame([], $extended->joins);
        self::assertSame([], $extended->dimensions);
    }

    /**
     * @param array<string,array{0:string,1:int}|null> $attributes code => [frontend input, id] or missing
     */
    private function provider(array $attributes): AttributeJoinProvider
    {
        $config = $this->createStub(Config::class);
        $config->method('getReportAttributes')->willReturn(array_keys($attributes));

        $repository = $this->createStub(AttributeRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(
            function (string $entity, string $code) use ($attributes): AttributeInterface {
                self::assertSame('catalog_product', $entity);
                if ($attributes[$code] === null) {
                    throw new NoSuchEntityException();
                }
                $attribute = $this->createStub(AttributeInterface::class);
                $attribute->method('getFrontendInput')->willReturn($attributes[$code][0]);
                $attribute->method('getAttributeId')->willReturn((string) $attributes[$code][1]);
                $attribute->method('getDefaultFrontendLabel')->willReturn(ucfirst($code));

                return $attribute;
            }
        );

        return new AttributeJoinProvider($config, $repository);
    }

    /**
     * @param string[] $fieldCodes
     */
    private function dataset(array $fieldCodes): Dataset
    {
        $fields = [];
        foreach ($fieldCodes as $code) {
            $fields[$code] = new Field($code, $code, FieldType::ID, ['mysql' => new SourceMapping('main.' . $code)]);
        }

        return new Dataset('sales_items', 'Items', 'acl', 'sales', false, 'soi', 'main', null, [], $fields, [], [], []);
    }
}
