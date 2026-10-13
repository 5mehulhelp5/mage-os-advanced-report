<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Definition;

use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Definition\Join;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Dataset::class)]
final class DatasetTest extends TestCase
{
    public function testItReturnsDatasetFieldsMetricsDimensionsAndJoinsByCode(): void
    {
        $field = new Field(code: 'created_at', label: 'Created At', type: FieldType::DATE, sourceMappings: []);
        $metric = new Metric(
            code: 'orders_count',
            label: 'Orders',
            description: null,
            format: 'integer',
            hidden: false,
            lowerIsBetter: false,
            aggregate: AggregateFunction::COUNT,
            fieldCode: 'created_at',
        );
        $dimension = new Dimension(
            code: 'created_at',
            label: 'Date',
            fieldCode: 'created_at',
            granularities: [Granularity::DAY],
        );
        $join = new Join(
            code: 'sales_order',
            table: 'sales_order',
            alias: 'order',
            condition: 'main.order_id = order.entity_id',
        );

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
            fields: [$field->code => $field],
            joins: [$join->code => $join],
            metrics: [$metric->code => $metric],
            dimensions: [$dimension->code => $dimension],
        );

        self::assertSame($field, $dataset->getField('created_at'));
        self::assertSame($metric, $dataset->getMetric('orders_count'));
        self::assertSame($dimension, $dataset->getDimension('created_at'));
        self::assertSame($join, $dataset->getJoin('sales_order'));
        self::assertTrue($dataset->hasField('created_at'));
        self::assertFalse($dataset->hasField('missing'));
    }

    public function testItThrowsNoSuchDefinitionExceptionForUnknownCodes(): void
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
            fields: [],
            joins: [],
            metrics: [],
            dimensions: [],
        );

        $this->expectException(NoSuchDefinitionException::class);

        $dataset->getField('missing');
    }
}
