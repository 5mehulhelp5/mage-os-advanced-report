<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use MageOS\AdvancedReport\Model\Config\Source\ReportAttributes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportAttributes::class)]
final class ReportAttributesTest extends TestCase
{
    public function testItReturnsOnlySelectAndMultiselectProductAttributes(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())
            ->method('addFieldToFilter')
            ->with('frontend_input', ['in' => ['select', 'multiselect']]);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $source = new ReportAttributes($this->collectionFactory($collection));

        self::assertSame([], $source->toOptionArray());
    }

    public function testItUsesAttributeCodeAsOptionValue(): void
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn('color');
        $attribute->method('getData')->willReturn('Color');

        $collection = $this->createStub(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$attribute]));

        $source = new ReportAttributes($this->collectionFactory($collection));

        self::assertSame('color', $source->toOptionArray()[0]['value']);
    }

    public function testItUsesFrontendLabelAsOptionLabelFallingBackToAttributeCode(): void
    {
        $withLabel = $this->createStub(Attribute::class);
        $withLabel->method('getAttributeCode')->willReturn('color');
        $withLabel->method('getData')->willReturn('Color');

        $withoutLabel = $this->createStub(Attribute::class);
        $withoutLabel->method('getAttributeCode')->willReturn('size');
        $withoutLabel->method('getData')->willReturn(null);

        $collection = $this->createStub(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$withLabel, $withoutLabel]));

        $source = new ReportAttributes($this->collectionFactory($collection));
        $options = $source->toOptionArray();

        self::assertSame('Color', $options[0]['label']);
        self::assertSame('size', $options[1]['label']);
    }

    public function testItSortsOptionsByLabel(): void
    {
        $zebra = $this->createStub(Attribute::class);
        $zebra->method('getAttributeCode')->willReturn('zebra');
        $zebra->method('getData')->willReturn('Zebra');

        $apple = $this->createStub(Attribute::class);
        $apple->method('getAttributeCode')->willReturn('apple');
        $apple->method('getData')->willReturn('Apple');

        $mango = $this->createStub(Attribute::class);
        $mango->method('getAttributeCode')->willReturn('mango');
        $mango->method('getData')->willReturn('Mango');

        $collection = $this->createStub(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$zebra, $apple, $mango]));

        $source = new ReportAttributes($this->collectionFactory($collection));
        $labels = array_column($source->toOptionArray(), 'label');

        self::assertSame(['Apple', 'Mango', 'Zebra'], $labels);
    }

    private function collectionFactory(Collection $collection): CollectionFactory
    {
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return $collectionFactory;
    }
}
