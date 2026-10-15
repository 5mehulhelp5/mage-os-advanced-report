<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Config;

use Magento\Framework\Config\FileResolverInterface;
use Magento\Framework\Config\SchemaLocatorInterface;
use Magento\Framework\Config\ValidationStateInterface;
use Magento\Framework\Exception\LocalizedException;
use MageOS\AdvancedReport\Model\Config\Converter;
use MageOS\AdvancedReport\Model\Config\Reader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(Reader::class)]
final class ReaderTest extends TestCase
{
    /**
     * @var FileResolverInterface&Stub
     */
    private FileResolverInterface&Stub $fileResolver;

    /**
     * @var ValidationStateInterface&Stub
     */
    private ValidationStateInterface&Stub $validationState;

    /**
     * @var SchemaLocatorInterface&Stub
     */
    private SchemaLocatorInterface&Stub $schemaLocator;

    protected function setUp(): void
    {
        $this->fileResolver = $this->createStub(FileResolverInterface::class);
        $this->validationState = $this->createStub(ValidationStateInterface::class);
        $this->validationState->method('isValidationRequired')->willReturn(true);

        $this->schemaLocator = $this->createStub(SchemaLocatorInterface::class);
        $this->schemaLocator->method('getSchema')
            ->willReturn(\dirname(__DIR__, 4) . '/etc/report_datasets.xsd');
        $this->schemaLocator->method('getPerFileSchema')
            ->willReturn(\dirname(__DIR__, 4) . '/etc/report_datasets_file.xsd');
    }

    public function testItMergesASecondFileThatAddsAFieldAndAMetricToAnExistingDataset(): void
    {
        $this->fileResolver->method('get')->willReturn([
            $this->fixture('dataset_merge_base.xml'),
            $this->fixture('dataset_merge_extension.xml'),
        ]);

        $result = $this->reader()->read();

        $dataset = $result['datasets']['sales_orders'];
        self::assertSame('Orders', $dataset['label']);
        self::assertArrayHasKey('grand_total', $dataset['fields']);
        self::assertArrayHasKey('discount_total', $dataset['fields']);
        self::assertArrayHasKey('orders', $dataset['metrics']);
        self::assertArrayHasKey('discount_rate', $dataset['metrics']);
    }

    public function testItMergesASecondFileThatAddsAFilterAndAMetricToAnExistingPreset(): void
    {
        $this->fileResolver->method('get')->willReturn([
            $this->fixture('preset_merge_base.xml'),
            $this->fixture('preset_merge_extension.xml'),
        ]);

        $result = $this->reader()->read();

        $preset = $result['presets']['sales_overview'];
        self::assertSame('Sales overview', $preset['label']);
        self::assertSame(['orders', 'discount_rate'], $preset['metrics']);
        self::assertCount(2, $preset['filters']);
        self::assertSame('store_id', $preset['filters'][0]['field']);
        self::assertSame('customer_group_id', $preset['filters'][1]['field']);
    }

    public function testItAcceptsPresetDescriptionAttributeInSchemaValidation(): void
    {
        $this->fileResolver->method('get')->willReturn([
            $this->fixture('preset_merge_base.xml'),
            $this->fixture('preset_merge_extension.xml'),
        ]);

        $result = $this->reader()->read();

        self::assertSame('Revenue and orders over time', $result['presets']['sales_overview']['description']);
    }

    public function testItValidatesJoinCardinalityAttributeAgainstTheMergedAndPerFileXsd(): void
    {
        $this->fileResolver->method('get')->willReturn([$this->fixture('join_cardinality_many.xml')]);

        $result = $this->reader()->read();

        self::assertSame('many', $result['datasets']['sales_items']['joins']['category']['cardinality']);
    }

    public function testItRejectsAnUnknownJoinCardinalityInTheXsd(): void
    {
        $this->fileResolver->method('get')->willReturn([$this->fixture('join_cardinality_invalid.xml')]);

        $this->expectException(LocalizedException::class);

        $this->reader()->read();
    }

    public function testItRejectsAFileThatViolatesTheXsd(): void
    {
        $this->fileResolver->method('get')->willReturn([
            $this->fixture('invalid_field_type.xml'),
        ]);

        $this->expectException(LocalizedException::class);

        $this->reader()->read();
    }

    public function testItAcceptsTheCustomersAreaInReportDatasetsXml(): void
    {
        $this->fileResolver->method('get')->willReturn([$this->fixture('customers_area.xml')]);

        $result = $this->reader()->read();

        self::assertSame('customers', $result['datasets']['customer_orders']['area']);
        self::assertSame('customers', $result['presets']['customer_spending']['area']);
    }

    public function testItStillRejectsAnUnknownArea(): void
    {
        $this->fileResolver->method('get')->willReturn([$this->fixture('unknown_area.xml')]);

        $this->expectException(LocalizedException::class);

        $this->reader()->read();
    }

    private function fixture(string $fileName): string
    {
        return (string)file_get_contents(__DIR__ . '/_files/' . $fileName);
    }

    private function reader(): Reader
    {
        return new Reader(
            $this->fileResolver,
            new Converter(),
            $this->schemaLocator,
            $this->validationState,
        );
    }
}
