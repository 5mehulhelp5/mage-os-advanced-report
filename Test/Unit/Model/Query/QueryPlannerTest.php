<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateTimeImmutable;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Query\CompareMode;
use MageOS\AdvancedReport\Model\Query\ComparisonPeriod;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DateRangeResolver;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\DerivedMetricCalculator;
use MageOS\AdvancedReport\Model\Query\QueryPlanner;
use MageOS\AdvancedReport\Model\Query\ReportScope;
use MageOS\AdvancedReport\Model\Query\ReportScopeResolver;
use MageOS\AdvancedReport\Model\Query\RequestValidator;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryPlanner::class)]
final class QueryPlannerTest extends TestCase
{
    private DefinitionRegistry $definitionRegistry;

    private Dataset $dataset;

    protected function setUp(): void
    {
        $this->dataset = $this->buildDataset();
        $this->definitionRegistry = $this->createStub(DefinitionRegistry::class);
        $this->definitionRegistry->method('getDataset')->willReturn($this->dataset);
    }

    public function testItPlansMainAndComparisonQueriesWithRequiredAggregates(): void
    {
        $range = new ResolvedDateRange(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-25'), true, 25);
        $comparisonRange = new ResolvedDateRange(
            new DateTimeImmutable('2026-08-07'),
            new DateTimeImmutable('2026-08-31'),
            false,
            25
        );
        $comparisonPeriod = new ComparisonPeriod($comparisonRange, CompareMode::PREVIOUS_PERIOD, 25);

        $dateRangeResolver = $this->createStub(DateRangeResolver::class);
        $dateRangeResolver->method('resolve')->willReturn($range);
        $dateRangeResolver->method('previousPeriod')->willReturn($comparisonPeriod);

        $requestValidator = $this->createStub(RequestValidator::class);
        $request = $this->buildRequest(metrics: ['aov'], compareTo: CompareMode::PREVIOUS_PERIOD);

        $planner = new QueryPlanner(
            $requestValidator,
            $this->definitionRegistry,
            $dateRangeResolver,
            new DerivedMetricCalculator(),
            $this->scopeResolver(),
        );

        $plan = $planner->plan($request);

        self::assertSame(['revenue', 'orders'], $plan['main']->metrics);
        self::assertSame($range, $plan['main']->dateRange);
        self::assertInstanceOf(ResolvedQuery::class, $plan['comparison']);
        self::assertSame(['revenue', 'orders'], $plan['comparison']->metrics);
        self::assertSame($comparisonRange, $plan['comparison']->dateRange);
    }

    public function testItSetsTheResolvedReportScopeOnTheMainAndComparisonQueries(): void
    {
        $range = new ResolvedDateRange(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-25'), true, 25);
        $comparisonPeriod = new ComparisonPeriod($range, CompareMode::PREVIOUS_PERIOD, 25);
        $scope = new ReportScope(5, 'Asia/Tokyo', 1, ['Asia/Tokyo']);

        $scopeResolver = $this->createMock(ReportScopeResolver::class);
        $scopeResolver->expects(self::once())->method('resolve')->with([])->willReturn($scope);
        $dateRangeResolver = $this->createMock(DateRangeResolver::class);
        $dateRangeResolver->expects(self::once())->method('resolve')->with(self::anything(), $scope)->willReturn($range);
        $dateRangeResolver->expects(self::once())->method('previousPeriod')->with($range, $scope)
            ->willReturn($comparisonPeriod);

        $planner = new QueryPlanner(
            $this->createStub(RequestValidator::class),
            $this->definitionRegistry,
            $dateRangeResolver,
            new DerivedMetricCalculator(),
            $scopeResolver,
        );

        $plan = $planner->plan($this->buildRequest(metrics: ['revenue'], compareTo: CompareMode::PREVIOUS_PERIOD));

        $comparison = $plan['comparison'];
        self::assertNotNull($comparison);
        self::assertSame($scope, $plan['main']->scope);
        self::assertSame($scope, $comparison->scope);
    }

    private function scopeResolver(): ReportScopeResolver
    {
        $resolver = $this->createStub(ReportScopeResolver::class);
        $resolver->method('resolve')->willReturn(new ReportScope(null, 'UTC', 0, ['UTC']));

        return $resolver;
    }

    public function testItPlansARequestWithoutAclChecksForTrustedCallers(): void
    {
        $range = new ResolvedDateRange(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-25'), true, 25);
        $dateRangeResolver = $this->createStub(DateRangeResolver::class);
        $dateRangeResolver->method('resolve')->willReturn($range);

        $request = $this->buildRequest(metrics: ['revenue']);

        $requestValidator = $this->createMock(RequestValidator::class);
        $requestValidator->expects(self::once())->method('validate')->with($request, false);

        $planner = new QueryPlanner(
            $requestValidator,
            $this->definitionRegistry,
            $dateRangeResolver,
            new DerivedMetricCalculator(),
            $this->scopeResolver(),
        );

        $planner->plan($request, false);
    }

    /**
     * @param string[] $metrics
     */
    private function buildRequest(array $metrics, CompareMode $compareTo = CompareMode::NONE): ReportRequestInterface
    {
        $request = $this->createStub(ReportRequestInterface::class);
        $request->method('getDataset')->willReturn('sales_orders');
        $request->method('getMetrics')->willReturn($metrics);
        $request->method('getDimensions')->willReturn([]);
        $request->method('getFilters')->willReturn([]);
        $request->method('getDateRange')->willReturn(new DateRange(DateRangeType::LAST_30_DAYS));
        $request->method('getStoreIds')->willReturn([]);
        $request->method('getSort')->willReturn([]);
        $request->method('getLimit')->willReturn(null);
        $request->method('getOffset')->willReturn(0);
        $request->method('getCompareTo')->willReturn($compareTo);
        $request->method('getPreset')->willReturn(null);
        $request->method('getSource')->willReturn(null);
        $request->method('isBypassCache')->willReturn(false);

        return $request;
    }

    private function buildDataset(): Dataset
    {
        return new Dataset(
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
            metrics: [
                'revenue' => new Metric(
                    code: 'revenue',
                    label: 'Revenue',
                    description: null,
                    format: 'money',
                    hidden: false,
                    lowerIsBetter: false,
                    aggregate: AggregateFunction::SUM,
                    fieldCode: 'revenue',
                ),
                'orders' => new Metric(
                    code: 'orders',
                    label: 'Orders',
                    description: null,
                    format: 'integer',
                    hidden: false,
                    lowerIsBetter: false,
                    aggregate: AggregateFunction::COUNT,
                    fieldCode: 'orders',
                ),
                'aov' => new Metric(
                    code: 'aov',
                    label: 'Average order value',
                    description: null,
                    format: 'money',
                    hidden: false,
                    lowerIsBetter: false,
                    formula: 'revenue / orders',
                ),
            ],
            dimensions: [],
        );
    }
}
