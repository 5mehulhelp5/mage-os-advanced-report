<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\FilterOperator;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Query\DerivedMetricCalculator;
use MageOS\AdvancedReport\Model\Query\FilterCondition;
use MageOS\AdvancedReport\Model\Query\MonetaryRequestDetector;
use MageOS\AdvancedReport\Model\Query\PresetRequestBuilder;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Test\Unit\Etc\ReportDatasetsFixtureTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonetaryRequestDetector::class)]
final class MonetaryRequestDetectorTest extends TestCase
{
    use ReportDatasetsFixtureTrait;

    private MonetaryRequestDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new MonetaryRequestDetector(new DerivedMetricCalculator());
    }

    public function testItTreatsARequestWithAMoneyMetricAsMonetary(): void
    {
        self::assertTrue($this->detector->isMonetary($this->buildDataset(), $this->buildRequest(['gross_sales'])));
    }

    public function testItTreatsARequestWithADerivedMoneyMetricAsMonetary(): void
    {
        self::assertTrue($this->detector->isMonetary($this->buildDataset(), $this->buildRequest(['net_sales'])));
    }

    public function testItTreatsAPercentRatioDerivedFromMoneyMetricsAsMonetary(): void
    {
        self::assertTrue($this->detector->isMonetary($this->buildDataset(), $this->buildRequest(['return_rate'])));
    }

    public function testItTreatsARequestFilteringOnAMoneyFieldAsMonetary(): void
    {
        $request = $this->buildRequest(['orders'], [new FilterCondition('gross_amount', FilterOperator::GTE, 10)]);

        self::assertTrue($this->detector->isMonetary($this->buildDataset(), $request));
    }

    public function testItTreatsARequestWithOnlyCountAndIntegerMetricsAsNotMonetary(): void
    {
        $request = $this->buildRequest(['orders', 'order_ratio'], [new FilterCondition('status', FilterOperator::EQ, 'x')]);

        self::assertFalse($this->detector->isMonetary($this->buildDataset(), $request));
    }

    public function testItTreatsAPresetRequestContainingAMoneyMetricAsMonetary(): void
    {
        $preset = $this->loadReportPresets()['sales_overview'];
        $request = (new PresetRequestBuilder(new ReportRequestFactory()))->build($preset);

        self::assertTrue($this->detector->isMonetary($this->loadReportDataset('sales_orders'), $request));
    }

    /**
     * @param string[] $metrics
     * @param FilterCondition[] $filters
     */
    private function buildRequest(array $metrics, array $filters = []): ReportRequestInterface
    {
        $request = $this->createStub(ReportRequestInterface::class);
        $request->method('getMetrics')->willReturn($metrics);
        $request->method('getFilters')->willReturn($filters);

        return $request;
    }

    private function buildDataset(): Dataset
    {
        $aggregate = static fn (string $code, string $format, AggregateFunction $function): Metric => new Metric(
            code: $code,
            label: $code,
            description: null,
            format: $format,
            hidden: false,
            lowerIsBetter: false,
            aggregate: $function,
            fieldCode: $code,
        );
        $derived = static fn (string $code, string $format, string $formula): Metric => new Metric(
            code: $code,
            label: $code,
            description: null,
            format: $format,
            hidden: false,
            lowerIsBetter: false,
            formula: $formula,
        );

        return new Dataset(
            code: 'sales_orders',
            label: 'Orders',
            aclResource: 'MageOS_AdvancedReport::sales',
            area: 'sales',
            dateless: false,
            baseTable: 'sales_order',
            baseAlias: 'main',
            baseProvider: null,
            implicitFilters: [],
            fields: [
                'gross_amount' => new Field('gross_amount', 'Gross amount', FieldType::MONEY),
                'status' => new Field('status', 'Status', FieldType::STRING),
            ],
            joins: [],
            metrics: [
                'orders' => $aggregate('orders', 'integer', AggregateFunction::COUNT),
                'gross_sales' => $aggregate('gross_sales', 'money', AggregateFunction::SUM),
                'returns' => $aggregate('returns', 'money', AggregateFunction::SUM),
                'net_sales' => $derived('net_sales', 'money', 'gross_sales - returns'),
                'return_rate' => $derived('return_rate', 'percent', 'returns / gross_sales'),
                'order_ratio' => $derived('order_ratio', 'number', 'orders / orders'),
            ],
            dimensions: [],
        );
    }
}
