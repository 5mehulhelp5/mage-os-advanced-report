<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use Magento\Framework\Phrase;
use Magento\Framework\Phrase\RendererInterface;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Query\ColumnMetadataBuilder;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ColumnMetadataBuilder::class)]
final class ColumnMetadataBuilderTest extends TestCase
{
    private ?RendererInterface $previousRenderer = null;

    protected function setUp(): void
    {
        $this->previousRenderer = Phrase::getRenderer();
    }

    protected function tearDown(): void
    {
        if ($this->previousRenderer instanceof RendererInterface) {
            Phrase::setRenderer($this->previousRenderer);
        }
    }

    public function testItBuildsColumnMetadataForDimensionsAndMetricsInRequestOrder(): void
    {
        $dataset = new Dataset(
            code: 'sales_orders',
            label: 'Sales Orders',
            aclResource: 'MageOS_AdvancedReport::sales',
            area: 'sales',
            dateless: false,
            baseTable: 'sales_order',
            baseAlias: 'main',
            baseProvider: null,
            implicitFilters: [],
            fields: [
                'order_date' => new Field('order_date', 'Order date', FieldType::DATE, [], 'The order date.'),
                'store_id' => new Field('store_id', 'Store', FieldType::ID),
            ],
            joins: [],
            metrics: [
                'net_sales' => new Metric(
                    code: 'net_sales',
                    label: 'Net sales',
                    description: 'Sales net of tax and shipping.',
                    format: 'money',
                    hidden: false,
                    lowerIsBetter: false,
                ),
                'returns' => new Metric(
                    code: 'returns',
                    label: 'Returns',
                    description: null,
                    format: 'money',
                    hidden: false,
                    lowerIsBetter: true,
                ),
            ],
            dimensions: [
                'order_date' => new Dimension('order_date', 'Date', 'order_date'),
                'store_id' => new Dimension('store_id', 'Store', 'store_id', nonAdditive: true),
            ],
        );

        $request = $this->createStub(ReportRequestInterface::class);
        $request->method('getDimensions')->willReturn([
            new DimensionSelection('store_id'),
            new DimensionSelection('order_date'),
        ]);
        $request->method('getMetrics')->willReturn(['returns', 'net_sales']);

        $builder = new ColumnMetadataBuilder();
        $columns = $builder->build($dataset, $request);

        self::assertSame(
            [
                [
                    'code' => 'store_id',
                    'label' => 'Store',
                    'type' => 'dimension',
                    'format' => 'id',
                    'description' => null,
                    'nonAdditive' => true,
                    'lowerIsBetter' => false,
                ],
                [
                    'code' => 'order_date',
                    'label' => 'Date',
                    'type' => 'dimension',
                    'format' => 'date',
                    'description' => 'The order date.',
                    'nonAdditive' => false,
                    'lowerIsBetter' => false,
                ],
                [
                    'code' => 'returns',
                    'label' => 'Returns',
                    'type' => 'metric',
                    'format' => 'money',
                    'description' => null,
                    'nonAdditive' => false,
                    'lowerIsBetter' => true,
                ],
                [
                    'code' => 'net_sales',
                    'label' => 'Net sales',
                    'type' => 'metric',
                    'format' => 'money',
                    'description' => 'Sales net of tax and shipping.',
                    'nonAdditive' => false,
                    'lowerIsBetter' => false,
                ],
            ],
            $columns
        );
    }

    public function testItTranslatesLabelsAndDescriptions(): void
    {
        $renderer = $this->createStub(RendererInterface::class);
        $renderer->method('render')->willReturnCallback(
            static fn (array $source): string => 'IT:' . $source[0]
        );
        Phrase::setRenderer($renderer);

        $dataset = new Dataset(
            code: 'sales_orders',
            label: 'Sales Orders',
            aclResource: 'MageOS_AdvancedReport::sales',
            area: 'sales',
            dateless: false,
            baseTable: 'sales_order',
            baseAlias: 'main',
            baseProvider: null,
            implicitFilters: [],
            fields: [
                'order_date' => new Field('order_date', 'Order date', FieldType::DATE, [], 'The order date.'),
            ],
            joins: [],
            metrics: [
                'net_sales' => new Metric(
                    code: 'net_sales',
                    label: 'Net sales',
                    description: 'Sales net of tax and shipping.',
                    format: 'money',
                    hidden: false,
                    lowerIsBetter: false,
                ),
                'returns' => new Metric(
                    code: 'returns',
                    label: 'Returns',
                    description: null,
                    format: 'money',
                    hidden: false,
                    lowerIsBetter: true,
                ),
            ],
            dimensions: [
                'order_date' => new Dimension('order_date', 'Date', 'order_date'),
            ],
        );

        $request = $this->createStub(ReportRequestInterface::class);
        $request->method('getDimensions')->willReturn([new DimensionSelection('order_date')]);
        $request->method('getMetrics')->willReturn(['net_sales', 'returns']);

        $columns = (new ColumnMetadataBuilder())->build($dataset, $request);

        self::assertSame('IT:Date', $columns[0]['label']);
        self::assertSame('IT:The order date.', $columns[0]['description']);
        self::assertSame('IT:Net sales', $columns[1]['label']);
        self::assertSame('IT:Sales net of tax and shipping.', $columns[1]['description']);
        self::assertSame('IT:Returns', $columns[2]['label']);
        self::assertNull($columns[2]['description']);
    }
}
