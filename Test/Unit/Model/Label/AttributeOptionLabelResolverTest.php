<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeOptionInterface;
use MageOS\AdvancedReport\Model\Label\AttributeOptionLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AttributeOptionLabelResolver::class)]
final class AttributeOptionLabelResolverTest extends TestCase
{
    public function testItResolvesAttributeOptionLabelsForTheAttributeInContext(): void
    {
        $options = [];
        foreach (['5' => 'Red', '6' => 'Blue'] as $value => $label) {
            $option = $this->createStub(AttributeOptionInterface::class);
            $option->method('getValue')->willReturn((string)$value);
            $option->method('getLabel')->willReturn($label);
            $options[] = $option;
        }
        $attribute = $this->createStub(ProductAttributeInterface::class);
        $attribute->method('getOptions')->willReturn($options);
        $repository = $this->createMock(ProductAttributeRepositoryInterface::class);
        $repository->expects($this->once())->method('get')->with('color')->willReturn($attribute);

        $labels = (new AttributeOptionLabelResolver($repository))->resolve(['5', '7'], ['attribute_code' => 'color']);

        $this->assertSame(['5' => 'Red', '7' => '7'], $labels);
    }
}
