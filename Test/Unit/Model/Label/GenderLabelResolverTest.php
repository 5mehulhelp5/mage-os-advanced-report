<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Api\Data\AttributeMetadataInterface;
use Magento\Customer\Api\Data\OptionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\AdvancedReport\Model\Label\GenderLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GenderLabelResolver::class)]
final class GenderLabelResolverTest extends TestCase
{
    public function testItLabelsGenderOptionIdsWithTheGenderAttributeOptionLabels(): void
    {
        $resolver = $this->resolverWithOptions([
            $this->option('1', 'Male'),
            $this->option('2', 'Female'),
        ]);

        $this->assertSame(['1' => 'Male', '2' => 'Female'], $resolver->resolve(['1', '2']));
    }

    public function testItSkipsGenderOptionsWithAnEmptyValue(): void
    {
        $resolver = $this->resolverWithOptions([
            $this->option('', ' '),
            $this->option(null, 'Group'),
            $this->option('1', 'Male'),
        ]);

        $this->assertSame(['1' => 'Male', '3' => '3'], $resolver->resolve(['1', '3']));
    }

    public function testItTranslatesGenderOptionLabels(): void
    {
        $resolver = $this->resolverWithOptions([$this->option('3', 'Not Specified')]);

        // The unit bootstrap translates through __(); an untranslated phrase falls back to itself.
        $this->assertSame(['3' => (string)__('Not Specified')], $resolver->resolve(['3']));
    }

    public function testItKeepsGenderIdsRawWhenTheGenderAttributeDoesNotExist(): void
    {
        $metadata = $this->createStub(CustomerMetadataInterface::class);
        $metadata->method('getAttributeMetadata')->willThrowException(new NoSuchEntityException());

        $this->assertSame(['1' => '1'], (new GenderLabelResolver($metadata))->resolve(['1']));
    }

    public function testItKeepsGenderIdsRawWhenTheCustomerMetadataLookupFails(): void
    {
        $metadata = $this->createStub(CustomerMetadataInterface::class);
        $metadata->method('getAttributeMetadata')->willThrowException(new LocalizedException(__('Failure')));

        $this->assertSame(['1' => '1'], (new GenderLabelResolver($metadata))->resolve(['1']));
    }

    public function testItLabelsAnEmptyGenderAsNone(): void
    {
        $metadata = $this->createMock(CustomerMetadataInterface::class);
        $metadata->expects($this->never())->method('getAttributeMetadata');

        $this->assertSame(['' => (string)__('(none)')], (new GenderLabelResolver($metadata))->resolve(['']));
    }

    /**
     * @param OptionInterface[] $options
     */
    private function resolverWithOptions(array $options): GenderLabelResolver
    {
        $attribute = $this->createStub(AttributeMetadataInterface::class);
        $attribute->method('getOptions')->willReturn($options);
        $metadata = $this->createMock(CustomerMetadataInterface::class);
        $metadata->expects($this->once())->method('getAttributeMetadata')->with('gender')->willReturn($attribute);

        return new GenderLabelResolver($metadata);
    }

    private function option(?string $value, string $label): OptionInterface
    {
        $option = $this->createStub(OptionInterface::class);
        $option->method('getValue')->willReturn($value);
        $option->method('getLabel')->willReturn($label);

        return $option;
    }
}
