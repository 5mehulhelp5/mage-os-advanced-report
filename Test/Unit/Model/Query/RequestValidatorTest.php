<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateTimeImmutable;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Phrase;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\FilterOperator;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use MageOS\AdvancedReport\Model\Query\ClockInterface;
use MageOS\AdvancedReport\Model\Query\CompareMode;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DateRangeResolver;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\FilterCondition;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use MageOS\AdvancedReport\Model\Query\ReportRequest;
use MageOS\AdvancedReport\Model\Query\RequestValidator;
use MageOS\AdvancedReport\Model\Query\SortOrder;
use MageOS\AdvancedReport\Model\Security\DatasetAccess;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestValidator::class)]
#[AllowMockObjectsWithoutExpectations]
final class RequestValidatorTest extends TestCase
{
    private const string TODAY = '2026-03-15';
    private const string ACL_SALES = 'MageOS_AdvancedReport::sales';
    private const string ACL_CONFIG = 'MageOS_AdvancedReport::config';

    private DefinitionRegistry&Stub $definitionRegistry;
    private AuthorizationInterface&Stub $authorization;
    private Config&Stub $config;
    private DatasetAccess&Stub $datasetAccess;
    private StoreManagerInterface&Stub $storeManager;
    private RequestValidator $validator;

    protected function setUp(): void
    {
        $this->definitionRegistry = $this->createStub(DefinitionRegistry::class);
        $this->authorization = $this->createStub(AuthorizationInterface::class);
        $this->authorization->method('isAllowed')->willReturn(true);
        $this->config = $this->createStub(Config::class);
        $this->config->method('getMaxRows')->willReturn(10000);

        $this->datasetAccess = $this->createStub(DatasetAccess::class);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStores')->willReturn([1 => $store]);

        $this->validator = $this->buildValidator($this->definitionRegistry, $this->authorization, $this->config);
    }

    /**
     * @param DefinitionRegistry&Stub $definitionRegistry
     * @param AuthorizationInterface $authorization
     * @param Config&Stub $config
     */
    private function buildValidator(
        DefinitionRegistry $definitionRegistry,
        AuthorizationInterface $authorization,
        Config $config,
        ?DatasetAccess $datasetAccess = null,
    ): RequestValidator {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('today')->willReturn(new DateTimeImmutable(self::TODAY));

        return new RequestValidator(
            $definitionRegistry,
            $authorization,
            new DateRangeResolver($clock),
            $config,
            $datasetAccess ?? $this->datasetAccess,
            $this->storeManager,
        );
    }

    /**
     * @return array<string,array{0:ReportRequest}>
     */
    public static function unknownOrHiddenSelectionProvider(): array
    {
        return [
            'unknown metric' => [self::requestWithMetrics(['unknown_metric'])],
            'hidden metric' => [self::requestWithMetrics(['hidden_metric'])],
            'unknown dimension' => [self::requestWithDimensions([new DimensionSelection('unknown_dimension')])],
        ];
    }

    #[DataProvider('unknownOrHiddenSelectionProvider')]
    public function testItRejectsUnknownOrHiddenMetricsAndDimensions(ReportRequest $request): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate($request);
    }

    /**
     * @return array<string,array{0:DimensionSelection}>
     */
    public static function disallowedGranularityProvider(): array
    {
        return [
            'granularity on a dimension that declares none' => [new DimensionSelection('channel', Granularity::DAY)],
            'granularity outside the dimension allow-list' => [new DimensionSelection('order_month', Granularity::DAY)],
        ];
    }

    #[DataProvider('disallowedGranularityProvider')]
    public function testItRejectsDisallowedGranularityForADimension(DimensionSelection $dimensionSelection): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::requestWithDimensions([$dimensionSelection]));
    }

    public function testItRejectsARequestWhenTheDatasetCoreResourceIsDenied(): void
    {
        $dataset = self::salesOrdersDataset();
        $this->definitionRegistry->method('getDataset')->willReturn($dataset);
        $datasetAccess = $this->createMock(DatasetAccess::class);
        $datasetAccess->expects(self::once())->method('assertAllowed')->with($dataset)->willThrowException(
            new AuthorizationException(new Phrase('denied'))
        );
        $validator = $this->buildValidator(
            $this->definitionRegistry,
            $this->authorization,
            $this->config,
            $datasetAccess
        );

        $this->expectException(AuthorizationException::class);

        $validator->validate(self::requestWithMetrics(['orders']));
    }

    public function testItAcceptsARequestWhenModuleAndCoreResourcesAreGranted(): void
    {
        $dataset = self::salesOrdersDataset();
        $this->definitionRegistry->method('getDataset')->willReturn($dataset);
        $datasetAccess = $this->createMock(DatasetAccess::class);
        $datasetAccess->expects(self::once())->method('assertAllowed')->with($dataset);
        $validator = $this->buildValidator(
            $this->definitionRegistry,
            $this->authorization,
            $this->config,
            $datasetAccess
        );

        $validator->validate(self::requestWithMetrics(['orders']));
    }

    public function testItRejectsFilterOperatorsNotAllowedForTheFieldType(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(
            self::requestWithFilters([new FilterCondition('channel', FilterOperator::GTE, 'web')])
        );
    }

    /**
     * @return array<string,array{0:FilterCondition}>
     */
    public static function malformedFilterValueProvider(): array
    {
        return [
            'in with an empty array' => [new FilterCondition('channel', FilterOperator::IN, [])],
            'nin with an empty array' => [new FilterCondition('channel', FilterOperator::NIN, [])],
            'in with a non-array value' => [new FilterCondition('channel', FilterOperator::IN, 'web')],
            'between with one value' => [new FilterCondition('amount', FilterOperator::BETWEEN, [10])],
            'between with three values' => [new FilterCondition('amount', FilterOperator::BETWEEN, [10, 20, 30])],
        ];
    }

    #[DataProvider('malformedFilterValueProvider')]
    public function testItRejectsMalformedFilterValues(FilterCondition $filter): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::requestWithFilters([$filter]));
    }

    /**
     * @param FilterCondition[] $filters
     */
    private static function requestWithFilters(array $filters): ReportRequest
    {
        return self::buildRequest(filters: $filters);
    }

    public function testItRejectsLimitsAboveMaxRows(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());
        $this->config = $this->createStub(Config::class);
        $this->config->method('getMaxRows')->willReturn(100);
        $this->validator = $this->buildValidator($this->definitionRegistry, $this->authorization, $this->config);

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::buildRequest(limit: 101));
    }

    /**
     * @return array<string,array{0:SortOrder}>
     */
    public static function invalidSortProvider(): array
    {
        return [
            'unselected metric' => [new SortOrder('net_sales')],
            'unselected dimension' => [new SortOrder('channel')],
            'unknown code' => [new SortOrder('bogus')],
        ];
    }

    #[DataProvider('invalidSortProvider')]
    public function testItRejectsASortTargetThatIsNotASelectedMetricOrDimension(SortOrder $sort): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::buildRequest(sort: [$sort]));
    }

    public function testItRejectsAnUnknownSortDirection(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::buildRequest(sort: [new SortOrder('orders', 'sideways')]));
    }

    public function testItAcceptsASortByASelectedMetricAndDimension(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());

        $this->validator->validate(self::buildRequest(
            dimensions: [new DimensionSelection('channel')],
            sort: [new SortOrder('orders', 'DESC'), new SortOrder('channel', 'asc')],
            storeIds: [1],
        ));

        $this->addToAssertionCount(1);
    }

    public function testItRejectsAStoreIdThatDoesNotExist(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::buildRequest(storeIds: [99]));
    }

    public function testItRejectsTheAdminStoreId(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::buildRequest(storeIds: [0]));
    }

    public function testItRejectsANegativeOffset(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::buildRequest(offset: -1));
    }

    public function testItRejectsALimitLowerThanOne(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::buildRequest(limit: 0));
    }

    public function testItRejectsAnUnknownPreset(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());
        $this->definitionRegistry->method('getPreset')->willThrowException(new NoSuchDefinitionException(new Phrase('x')));

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::buildRequest(preset: 'ghost'));
    }

    public function testItRejectsAPresetBelongingToAnotherDataset(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());
        $this->definitionRegistry->method('getPreset')->willReturn(self::preset('stock', 'catalog_stock', null));

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::buildRequest(preset: 'stock'));
    }

    /**
     * @return array<string,array{0:Granularity,1:string,2:string}>
     */
    public static function granularityOverLimitProvider(): array
    {
        return [
            'day over 400 days' => [Granularity::DAY, '2025-01-01', '2026-03-15'],
            'hour over 31 days' => [Granularity::HOUR, '2026-01-01', '2026-03-15'],
        ];
    }

    #[DataProvider('granularityOverLimitProvider')]
    public function testItRejectsDayGranularityOver400DaysAndHourGranularityOver31Days(
        Granularity $granularity,
        string $from,
        string $to,
    ): void {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::buildRequest(
            dimensions: [new DimensionSelection('order_date', $granularity)],
            dateRange: new DateRange(DateRangeType::CUSTOM, $from, $to),
        ));
    }

    public function testItRejectsNewVersusReturningAnalysisOver12Months(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::buildRequest(
            dimensions: [new DimensionSelection('customer_type')],
            dateRange: new DateRange(DateRangeType::CUSTOM, '2024-01-01', '2026-03-15'),
        ));
    }

    public function testItRejectsSourceOverrideWithoutConfigPermission(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());
        $this->authorization = $this->createStub(AuthorizationInterface::class);
        $this->authorization->method('isAllowed')->willReturnMap([
            [self::ACL_SALES, null, true],
            [self::ACL_CONFIG, null, false],
        ]);
        $this->validator = $this->buildValidator($this->definitionRegistry, $this->authorization, $this->config);

        $this->expectException(AuthorizationException::class);

        $this->validator->validate(self::buildRequest(source: 'mysql'));
    }

    /**
     * @return array<string,array{0:string|null,1:Preset|null}>
     */
    public static function invalidVirtualMetricPresetProvider(): array
    {
        return [
            'no preset set' => [null, null],
            'unknown preset code' => ['unknown_preset', null],
            'preset without a preset query' => [
                'preset_without_query',
                self::preset('preset_without_query', 'sales_orders', null),
            ],
            'preset for a different dataset' => [
                'preset_wrong_dataset',
                self::preset('preset_wrong_dataset', 'other_dataset', 'some_query'),
            ],
        ];
    }

    #[DataProvider('invalidVirtualMetricPresetProvider')]
    public function testItRejectsVirtualMetricsUnlessTheRequestPresetDeclaresAPresetQuery(
        ?string $presetCode,
        ?Preset $preset,
    ): void {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());
        if ($preset !== null) {
            $this->definitionRegistry->method('getPreset')->willReturn($preset);
        } elseif ($presetCode !== null) {
            $this->definitionRegistry->method('getPreset')->willThrowException(
                new NoSuchDefinitionException(new Phrase('No such preset "%1".', [$presetCode]))
            );
        }

        $this->expectException(InvalidRequestException::class);

        $this->validator->validate(self::buildRequest(preset: $presetCode, metricCodes: ['coverage_days']));
    }

    public function testItAcceptsVirtualMetricsWhenThePresetDeclaresAPresetQueryForTheSameDataset(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());
        $this->definitionRegistry->method('getPreset')->willReturn(
            self::preset('preset_with_query', 'sales_orders', 'some_query')
        );

        $this->validator->validate(self::buildRequest(preset: 'preset_with_query', metricCodes: ['coverage_days']));

        $this->addToAssertionCount(1);
    }

    public function testItSkipsDatasetAccessWhenAclCheckingIsDisabled(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::salesOrdersDataset());
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects(self::never())->method('isAllowed');
        $datasetAccess = $this->createMock(DatasetAccess::class);
        $datasetAccess->expects(self::never())->method('assertAllowed');
        $validator = $this->buildValidator($this->definitionRegistry, $authorization, $this->config, $datasetAccess);

        $validator->validate(self::buildRequest(source: 'mysql'), checkAcl: false);

        $this->addToAssertionCount(1);
    }

    public function testItSkipsDateRangeAndCompareToChecksForDatelessDatasets(): void
    {
        $this->definitionRegistry->method('getDataset')->willReturn(self::catalogStockDataset());
        $dateRangeResolver = $this->createMock(DateRangeResolver::class);
        $dateRangeResolver->expects(self::never())->method('resolve');
        $validator = new RequestValidator(
            $this->definitionRegistry,
            $this->authorization,
            $dateRangeResolver,
            $this->config,
            $this->datasetAccess,
            $this->storeManager
        );

        // A day granularity over 400 days and a first_order field over 12 months would
        // both be rejected on a dated dataset; here they must be silently ignored.
        $validator->validate(self::buildRequest(
            dataset: 'catalog_stock',
            metricCodes: ['stock_qty'],
            dimensions: [
                new DimensionSelection('stock_date', Granularity::DAY),
                new DimensionSelection('customer_type'),
            ],
            dateRange: new DateRange(DateRangeType::CUSTOM, '2020-01-01', '2026-03-15'),
        ));

        $this->addToAssertionCount(1);
    }

    private static function catalogStockDataset(): Dataset
    {
        $fields = [
            'stock_qty' => new Field('stock_qty', 'Stock qty', FieldType::INTEGER, [
                'mysql' => new SourceMapping(expression: 'main.qty'),
            ]),
            'stock_date' => new Field('stock_date', 'Stock date', FieldType::DATE, [
                'mysql' => new SourceMapping(expression: 'main.updated_at'),
            ]),
            'is_first_order' => new Field('is_first_order', 'Is first order', FieldType::BOOLEAN, [
                'mysql' => new SourceMapping(expressionProvider: 'first_order'),
            ]),
        ];

        $metrics = [
            'stock_qty' => new Metric(
                code: 'stock_qty',
                label: 'Stock qty',
                description: null,
                format: 'integer',
                hidden: false,
                lowerIsBetter: false,
                aggregate: AggregateFunction::SUM,
                fieldCode: 'stock_qty',
            ),
        ];

        $dimensions = [
            'stock_date' => new Dimension(
                code: 'stock_date',
                label: 'Stock date',
                fieldCode: 'stock_date',
                granularities: [Granularity::DAY, Granularity::WEEK, Granularity::MONTH],
            ),
            'customer_type' => new Dimension(code: 'customer_type', label: 'Customer type', fieldCode: 'is_first_order'),
        ];

        return new Dataset(
            code: 'catalog_stock',
            label: 'Stock',
            aclResource: 'MageOS_AdvancedReport::catalog',
            area: 'catalog',
            dateless: true,
            baseTable: 'cataloginventory_stock_item',
            baseAlias: 'main',
            baseProvider: null,
            implicitFilters: [],
            fields: $fields,
            joins: [],
            metrics: $metrics,
            dimensions: $dimensions,
        );
    }

    private static function preset(string $code, string $dataset, ?string $presetQuery): Preset
    {
        return new Preset(
            code: $code,
            area: 'sales',
            label: $code,
            dataset: $dataset,
            metrics: [],
            dimensions: [],
            filters: [],
            sort: [],
            compareTo: 'none',
            dateRange: null,
            sources: ['mysql'],
            presetQuery: $presetQuery,
        );
    }

    /**
     * @param string[] $metricCodes
     */
    private static function requestWithMetrics(array $metricCodes): ReportRequest
    {
        return self::buildRequest(metricCodes: $metricCodes);
    }

    /**
     * @param DimensionSelection[] $dimensions
     */
    private static function requestWithDimensions(array $dimensions): ReportRequest
    {
        return self::buildRequest(dimensions: $dimensions);
    }

    /**
     * @param string[] $metricCodes
     * @param DimensionSelection[] $dimensions
     * @param FilterCondition[] $filters
     * @param SortOrder[] $sort
     * @param int[] $storeIds
     */
    private static function buildRequest(
        string $dataset = 'sales_orders',
        ?string $preset = null,
        array $metricCodes = ['orders'],
        array $dimensions = [],
        array $filters = [],
        ?DateRange $dateRange = null,
        ?int $limit = null,
        ?string $source = null,
        array $sort = [],
        int $offset = 0,
        array $storeIds = [],
    ): ReportRequest {
        return new ReportRequest(
            dataset: $dataset,
            preset: $preset,
            metrics: $metricCodes,
            dimensions: $dimensions,
            filters: $filters,
            dateRange: $dateRange ?? new DateRange(DateRangeType::LAST_30_DAYS),
            compareTo: CompareMode::NONE,
            sort: $sort,
            limit: $limit,
            offset: $offset,
            storeIds: $storeIds,
            source: $source,
            bypassCache: false,
        );
    }

    private static function salesOrdersDataset(): Dataset
    {
        $fields = [
            'order_id' => new Field('order_id', 'Order ID', FieldType::ID, [
                'mysql' => new SourceMapping(expression: 'main.entity_id'),
            ]),
            'status' => new Field('status', 'Status', FieldType::STRING, [
                'mysql' => new SourceMapping(expression: 'main.status'),
            ]),
            'channel' => new Field('channel', 'Channel', FieldType::STRING, [
                'mysql' => new SourceMapping(expression: 'main.channel'),
            ]),
            'amount' => new Field('amount', 'Amount', FieldType::MONEY, [
                'mysql' => new SourceMapping(expression: 'main.base_subtotal'),
            ]),
            'order_date' => new Field('order_date', 'Order date', FieldType::DATE, [
                'mysql' => new SourceMapping(expression: 'main.created_at'),
            ]),
            'is_first_order' => new Field('is_first_order', 'Is first order', FieldType::BOOLEAN, [
                'mysql' => new SourceMapping(expressionProvider: 'first_order'),
            ]),
        ];

        $metrics = [
            'orders' => new Metric(
                code: 'orders',
                label: 'Orders',
                description: null,
                format: 'integer',
                hidden: false,
                lowerIsBetter: false,
                aggregate: AggregateFunction::COUNT,
                fieldCode: 'order_id',
            ),
            'net_sales' => new Metric(
                code: 'net_sales',
                label: 'Net sales',
                description: null,
                format: 'money',
                hidden: false,
                lowerIsBetter: false,
                aggregate: AggregateFunction::SUM,
                fieldCode: 'amount',
            ),
            'hidden_metric' => new Metric(
                code: 'hidden_metric',
                label: 'Hidden metric',
                description: null,
                format: 'money',
                hidden: true,
                lowerIsBetter: false,
                aggregate: AggregateFunction::SUM,
                fieldCode: 'amount',
            ),
            'new_customer_orders' => new Metric(
                code: 'new_customer_orders',
                label: 'New customer orders',
                description: null,
                format: 'integer',
                hidden: false,
                lowerIsBetter: false,
                aggregate: AggregateFunction::SUM,
                fieldCode: 'is_first_order',
            ),
            'coverage_days' => new Metric(
                code: 'coverage_days',
                label: 'Coverage days',
                description: null,
                format: 'number',
                hidden: false,
                lowerIsBetter: false,
                virtual: true,
            ),
        ];

        $dimensions = [
            'order_date' => new Dimension(
                code: 'order_date',
                label: 'Order date',
                fieldCode: 'order_date',
                granularities: [Granularity::HOUR, Granularity::DAY, Granularity::WEEK, Granularity::MONTH, Granularity::YEAR],
            ),
            'channel' => new Dimension(code: 'channel', label: 'Channel', fieldCode: 'channel'),
            'customer_type' => new Dimension(code: 'customer_type', label: 'Customer type', fieldCode: 'is_first_order'),
            'order_month' => new Dimension(
                code: 'order_month',
                label: 'Order month',
                fieldCode: 'order_date',
                granularities: [Granularity::MONTH, Granularity::YEAR],
            ),
        ];

        return new Dataset(
            code: 'sales_orders',
            label: 'Orders',
            aclResource: self::ACL_SALES,
            area: 'sales',
            dateless: false,
            baseTable: 'sales_order',
            baseAlias: 'main',
            baseProvider: null,
            implicitFilters: [],
            fields: $fields,
            joins: [],
            metrics: $metrics,
            dimensions: $dimensions,
        );
    }
}
