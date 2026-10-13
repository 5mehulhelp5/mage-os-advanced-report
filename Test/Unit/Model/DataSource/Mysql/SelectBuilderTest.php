<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use DateTimeImmutable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\AggregateExpressionBuilder;
use MageOS\AdvancedReport\Model\DataSource\Mysql\BaseSelectProviderInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\CountedStatusCondition;
use MageOS\AdvancedReport\Model\DataSource\Mysql\DateScopeApplier;
use MageOS\AdvancedReport\Model\DataSource\Mysql\DimensionExpressionBuilder;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionProviderInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionResolver;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ImplicitFilterPool;
use MageOS\AdvancedReport\Model\DataSource\Mysql\JoinRegistry;
use MageOS\AdvancedReport\Model\DataSource\Mysql\SelectBuilder;
use MageOS\AdvancedReport\Model\DataSource\Mysql\SortApplier;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneStrategy;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneSupportDetector;
use MageOS\AdvancedReport\Model\DataSource\Mysql\UserFilterBuilder;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\FilterOperator;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Definition\Join;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\FilterCondition;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use MageOS\AdvancedReport\Model\Query\SortOrder;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;
use MageOS\AdvancedReport\Test\Unit\Helper\SelectTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SelectBuilder::class)]
#[CoversClass(AggregateExpressionBuilder::class)]
#[CoversClass(ImplicitFilterPool::class)]
#[CoversClass(CountedStatusCondition::class)]
#[CoversClass(UserFilterBuilder::class)]
#[CoversClass(DimensionExpressionBuilder::class)]
#[CoversClass(FieldExpressionResolver::class)]
#[CoversClass(DateScopeApplier::class)]
#[CoversClass(SortApplier::class)]
final class SelectBuilderTest extends TestCase
{
    use SelectTestTrait;

    public function testItSelectsAggregateMetricsGroupedByDimensionExpressions(): void
    {
        $sql = $this->sql($this->query(
            metrics: ['orders', 'gross'],
            dimensions: [new DimensionSelection('customer_group_id'), new DimensionSelection('order_date', Granularity::MONTH)],
        ));

        self::assertSame(
            "SELECT main.customer_group_id AS `customer_group_id`,"
            . " DATE_FORMAT(CONVERT_TZ(main.created_at, 'UTC', 'UTC'), '%Y-%m') AS `order_date`,"
            . " COUNT(main.entity_id) AS `orders`, SUM(main.base_subtotal) AS `gross`"
            . " FROM `sales_order` AS `main`"
            . " WHERE (main.status NOT IN ('canceled'))"
            . " GROUP BY `customer_group_id`, `order_date`",
            $sql
        );
    }

    public function testItBuildsConditionalAggregatesForMetricsWithAConditionField(): void
    {
        $sql = $this->sql($this->query(metrics: ['new_gross', 'new_customers']));

        self::assertStringContainsString(
            'SUM(CASE WHEN main.is_new THEN main.base_subtotal ELSE 0 END) AS `new_gross`',
            $sql
        );
        self::assertStringContainsString(
            'COUNT(DISTINCT CASE WHEN main.is_new THEN main.customer_id END) AS `new_customers`',
            $sql
        );
    }

    public function testItAppliesCountedStatusAsNotCanceledWhenConfigIsEmptyAndAsAnInListOtherwise(): void
    {
        self::assertStringContainsString(
            "WHERE (main.status NOT IN ('canceled'))",
            $this->sql($this->query(metrics: ['orders']))
        );
        self::assertStringContainsString(
            "WHERE (main.status IN ('complete', 'processing'))",
            $this->sql($this->query(metrics: ['orders']), ['complete', 'processing'])
        );
    }

    public function testItAppliesTheTopLevelItemImplicitFilter(): void
    {
        $sql = $this->sql($this->query(metrics: ['orders'], implicit: ['top_level_item' => 'top_level_item']));

        self::assertStringContainsString('(main.parent_item_id IS NULL)', $sql);
        self::assertStringNotContainsString('status', $sql);
    }

    /**
     * @param FilterOperator $operator
     * @param mixed $value
     * @param string $expected
     */
    #[DataProvider('filterProvider')]
    public function testItBindsUserFilterValuesForEveryOperatorWithoutInterpolation(
        FilterOperator $operator,
        mixed $value,
        string $expected
    ): void {
        $sql = $this->sql($this->query(
            metrics: ['orders'],
            filters: [new FilterCondition('customer_group_id', $operator, $value)],
            implicit: [],
        ));

        self::assertStringContainsString('WHERE (' . $expected . ')', $sql);
    }

    /**
     * @return array<string,array{FilterOperator,mixed,string}>
     */
    public static function filterProvider(): array
    {
        return [
            'eq' => [FilterOperator::EQ, "1' OR '1'='1", "main.customer_group_id = '1\\' OR \\'1\\'=\\'1'"],
            'neq' => [FilterOperator::NEQ, 2, 'main.customer_group_id <> 2'],
            'in' => [FilterOperator::IN, [1, 2], 'main.customer_group_id IN (1, 2)'],
            'nin' => [FilterOperator::NIN, [3], 'main.customer_group_id NOT IN (3)'],
            'gte' => [FilterOperator::GTE, 4, 'main.customer_group_id >= 4'],
            'lte' => [FilterOperator::LTE, 5, 'main.customer_group_id <= 5'],
            'between' => [FilterOperator::BETWEEN, [6, 7], 'main.customer_group_id BETWEEN 6 AND 7'],
        ];
    }

    public function testItAddsOnlyJoinsRequiredByUsedFields(): void
    {
        $without = $this->sql($this->query(metrics: ['orders']));
        self::assertStringNotContainsString('JOIN', $without);

        $with = $this->sql($this->query(metrics: ['orders'], dimensions: [new DimensionSelection('method')]));
        self::assertStringContainsString(
            'LEFT JOIN `sales_order_payment` AS `payment` ON payment.parent_id = main.entity_id',
            $with
        );
        self::assertStringNotContainsString('sales_order_address', $with);
    }

    public function testItUsesTheDatasetCreatedAtStoreIdAndStatusFieldExpressionsInsteadOfMainColumns(): void
    {
        $sql = $this->sql(
            $this->query(
                metrics: ['orders'],
                dimensions: [new DimensionSelection('order_date', Granularity::DAY)],
                dataset: $this->dataset(prefix: 'order'),
                storeIds: [1, 2],
            ),
            timezones: [1 => 'Europe/Rome', 2 => 'Asia/Tokyo'],
        );

        self::assertStringContainsString('CASE order.store_id WHEN 1 THEN CONVERT_TZ(order.created_at', $sql);
        self::assertStringContainsString("order.status NOT IN ('canceled')", $sql);
        self::assertStringNotContainsString('main.created_at', $sql);
        self::assertStringNotContainsString('main.status', $sql);
    }

    public function testItResolvesExpressionProvidersByName(): void
    {
        $provider = $this->createStub(FieldExpressionProviderInterface::class);
        $provider->method('getExpression')->willReturn('(SELECT 1)');

        $sql = $this->sql($this->query(metrics: ['first_orders']), providers: ['first_order' => $provider]);

        self::assertStringContainsString('SUM((SELECT 1)) AS `first_orders`', $sql);
    }

    public function testItOpensTheConfiguredConnectionByConnectionName(): void
    {
        $adapter = $this->createSqlAdapter();
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::once())->method('getConnectionByName')->with('reports')->willReturn($adapter);
        $resource->expects(self::never())->method('getConnection');
        $resource->method('getTableName')->willReturnArgument(0);

        $this->builder($resource, 'reports')->build($this->query(metrics: ['orders']));
    }

    public function testItFiltersCreatedAtByTheUtcRangeAndStoreScope(): void
    {
        $map = $this->createStub(StoreTimezoneMap::class);
        $map->method('toUtcRange')->willReturn(['from' => '2026-01-01 00:00:00', 'to' => '2026-02-01 00:00:00']);
        $map->method('hasMultipleTimezones')->willReturn(false);

        $sql = $this->sql($this->query(metrics: ['orders'], storeIds: [1, 2], withRange: true), dateMap: $map);

        self::assertStringContainsString(
            "WHERE (main.created_at >= '2026-01-01 00:00:00' AND main.created_at < '2026-02-01 00:00:00')"
            . " AND (main.store_id IN (1, 2))",
            $sql
        );
        self::assertStringNotContainsString('CONVERT_TZ', $sql);
    }

    public function testItAddsPerTimezoneUtcRangeConditionsWhenStoresSpanSeveralTimezones(): void
    {
        $map = $this->createStub(StoreTimezoneMap::class);
        $map->method('toUtcRange')->willReturn(['from' => '2025-12-31 14:00:00', 'to' => '2026-02-01 00:00:00']);
        $map->method('hasMultipleTimezones')->willReturn(true);
        $map->method('toUtcRangesByTimezone')->willReturn([
            ['timezone' => 'Europe/Rome', 'storeIds' => [1], 'from' => '2025-12-31 23:00:00', 'to' => '2026-01-31 23:00:00'],
            ['timezone' => 'Asia/Tokyo', 'storeIds' => [2], 'from' => '2025-12-31 15:00:00', 'to' => '2026-01-31 15:00:00'],
        ]);

        $sql = $this->sql($this->query(metrics: ['orders'], storeIds: [1, 2], withRange: true), dateMap: $map);

        self::assertStringContainsString(
            "((main.store_id IN (1) AND main.created_at >= '2025-12-31 23:00:00' AND main.created_at < '2026-01-31 23:00:00')"
            . " OR (main.store_id IN (2) AND main.created_at >= '2025-12-31 15:00:00'"
            . " AND main.created_at < '2026-01-31 15:00:00'))",
            $sql
        );
    }

    public function testItSkipsTheStoreFilterForDatasetsWithoutAStoreIdField(): void
    {
        $dataset = $this->dataset();
        $fields = $dataset->fields;
        unset($fields['store_id']);
        $dataset = $this->withFields($dataset, $fields);
        $map = $this->createStub(StoreTimezoneMap::class);
        $map->method('toUtcRange')->willReturn(['from' => 'a', 'to' => 'b']);

        $sql = $this->sql($this->query(metrics: ['orders'], dataset: $dataset, storeIds: [1], withRange: true), dateMap: $map);

        self::assertStringNotContainsString('store_id', $sql);
        self::assertStringContainsString('main.created_at >=', $sql);
    }

    public function testItBuildsATotalsSelectWithoutGroupingAndLimit(): void
    {
        $query = $this->query(metrics: ['orders'], dimensions: [new DimensionSelection('customer_group_id')]);
        $query = new ResolvedQuery($query->dataset, ['orders'], $query->dimensions, [], null, [1], [], 10, 5);

        $sql = $this->normalizeSql($this->builderFor()->buildTotals($query));

        self::assertSame(
            "SELECT COUNT(main.entity_id) AS `orders` FROM `sales_order` AS `main`"
            . " WHERE (main.store_id IN (1)) AND (main.status NOT IN ('canceled'))",
            $sql
        );
    }

    public function testItBuildsARowCountSelectOverTheGroupedSelect(): void
    {
        $query = $this->query(metrics: ['orders'], dimensions: [new DimensionSelection('customer_group_id')], storeIds: [1]);

        $sql = $this->normalizeSql($this->builderFor()->buildRowCount($query));

        self::assertSame(
            "SELECT COUNT(*) FROM (SELECT main.customer_group_id AS `customer_group_id`,"
            . " COUNT(main.entity_id) AS `orders` FROM `sales_order` AS `main`"
            . " WHERE (main.store_id IN (1)) AND (main.status NOT IN ('canceled'))"
            . " GROUP BY `customer_group_id`) AS `grouped`",
            $sql
        );
    }

    public function testItWrapsAProviderSelectAsTheMainDerivedTable(): void
    {
        $adapter = $this->createSqlAdapter();
        $providerSelect = $adapter->select()->from('catalog_product_entity', ['entity_id', 'qty' => 'qty']);
        $provider = $this->createMock(BaseSelectProviderInterface::class);
        $provider->expects(self::once())->method('getSelect')->willReturn($providerSelect);
        $dataset = $this->dataset();
        $dataset = new Dataset(
            $dataset->code,
            $dataset->label,
            $dataset->aclResource,
            $dataset->area,
            false,
            null,
            null,
            'Vendor\\StockProvider',
            [],
            $dataset->fields,
            $dataset->joins,
            $dataset->metrics,
            $dataset->dimensions
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);

        $builder = $this->builder($resource, 'default', baseProviders: ['Vendor\\StockProvider' => $provider]);
        $sql = $this->normalizeSql($builder->build($this->query(metrics: ['orders'], dataset: $dataset, storeIds: [])));

        self::assertSame(
            'SELECT COUNT(main.entity_id) AS `orders` FROM'
            . ' (SELECT `catalog_product_entity`.`entity_id`, `catalog_product_entity`.`qty` FROM `catalog_product_entity`)'
            . ' AS `main`',
            $sql
        );
    }

    public function testItOrdersByWhitelistedDimensionAndAggregateAliasesAndAppliesLimitAndOffset(): void
    {
        $query = $this->sortedQuery(
            [new SortOrder('orders', 'desc'), new SortOrder('customer_group_id', 'asc')],
            [new DimensionSelection('customer_group_id'), new DimensionSelection('method')],
            25,
            50
        );

        $sql = $this->normalizeSql($this->builderFor()->buildPage($query));

        self::assertStringEndsWith(
            'GROUP BY `customer_group_id`, `method` ORDER BY `orders` DESC, `customer_group_id` ASC,'
            . ' `method` ASC LIMIT 25 OFFSET 50',
            $sql
        );
    }

    public function testItOrdersByADerivedMetricUsingItsRenderedFormulaAndAppendsDimensionTieBreakers(): void
    {
        $query = $this->sortedQuery(
            [new SortOrder('avg_gross', 'desc')],
            [new DimensionSelection('method')],
            null,
            0
        );

        $sql = $this->normalizeSql($this->builderFor()->buildPage($query));

        self::assertStringEndsWith(
            'ORDER BY (SUM(main.base_subtotal) / NULLIF(COUNT(main.entity_id), 0)) DESC, `method` ASC',
            $sql
        );
        self::assertStringNotContainsString('LIMIT', $sql);
    }

    /**
     * @param string $target
     */
    #[DataProvider('invalidSortTargetProvider')]
    public function testItRejectsSortTargetsThatAreNotSelectedMetricsOrDimensions(string $target): void
    {
        $query = $this->sortedQuery([new SortOrder($target)], [new DimensionSelection('method')], 10, 0);

        $this->expectException(\InvalidArgumentException::class);

        $this->builderFor()->buildPage($query);
    }

    /**
     * @return array<string,array{string}>
     */
    public static function invalidSortTargetProvider(): array
    {
        return [
            'unselected metric' => ['new_gross'],
            'unselected dimension' => ['customer_group_id'],
            'unknown code' => ['1; DROP TABLE x'],
        ];
    }

    public function testItBuildsTheUtcHourFallbackSelectWithoutSortAndLimit(): void
    {
        $query = $this->sortedQuery(
            [new SortOrder('orders')],
            [new DimensionSelection('order_date', Granularity::DAY), new DimensionSelection('method')],
            10,
            20
        );

        $fallback = $this->builderFor()->buildUtcHourFallback($query);
        $sql = $this->normalizeSql($fallback->select);

        self::assertSame(
            "SELECT DATE_FORMAT(main.created_at, '%Y-%m-%d %H:00:00') AS `utc_hour`,"
            . " payment.method AS `method`, COUNT(main.entity_id) AS `orders`, SUM(main.base_subtotal) AS `gross`"
            . " FROM `sales_order` AS `main`"
            . " LEFT JOIN `sales_order_payment` AS `payment` ON payment.parent_id = main.entity_id"
            . " WHERE (main.status NOT IN ('canceled')) GROUP BY `utc_hour`, `method` LIMIT 5001",
            $sql
        );
        self::assertSame(['order_date'], $fallback->replacedDimensions);
        self::assertSame(5000, $fallback->rowCap);
        self::assertNull($fallback->storeColumn);
        self::assertTrue($this->builderFor()->hasDateBucketDimension($query));
    }

    public function testItAddsTheStoreColumnToTheUtcHourFallbackWhenTimezonesDiffer(): void
    {
        $map = $this->createStub(StoreTimezoneMap::class);
        $map->method('hasMultipleTimezones')->willReturn(true);
        $query = $this->sortedQuery([], [new DimensionSelection('order_hour')], null, 0);

        $fallback = $this->builderFor($map)->buildUtcHourFallback($query);

        self::assertStringContainsString('main.store_id AS `store_id`', (string) $fallback->select);
        self::assertStringContainsString('GROUP BY `utc_hour`, `store_id`', $this->normalizeSql($fallback->select));
        self::assertSame(['order_hour'], $fallback->replacedDimensions);
        self::assertSame('store_id', $fallback->storeColumn);
        self::assertTrue($fallback->dropStoreColumn);
    }

    /**
     * @param SortOrder[] $sort
     * @param DimensionSelection[] $dimensions
     */
    private function sortedQuery(array $sort, array $dimensions, ?int $limit, int $offset): ResolvedQuery
    {
        $query = $this->query(metrics: ['orders', 'gross'], dimensions: $dimensions);

        return new ResolvedQuery($query->dataset, ['orders', 'gross'], $dimensions, [], null, [], $sort, $limit, $offset);
    }

    private function builderFor(?StoreTimezoneMap $dateMap = null): SelectBuilder
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($this->createSqlAdapter());
        $resource->method('getTableName')->willReturnArgument(0);

        return $this->builder($resource, 'default', dateMap: $dateMap);
    }

    /**
     * @param array<string,Field> $fields
     */
    private function withFields(Dataset $dataset, array $fields): Dataset
    {
        return new Dataset(
            $dataset->code,
            $dataset->label,
            $dataset->aclResource,
            $dataset->area,
            false,
            $dataset->baseTable,
            $dataset->baseAlias,
            null,
            [],
            $fields,
            $dataset->joins,
            $dataset->metrics,
            $dataset->dimensions
        );
    }

    /**
     * @param string[] $counted
     * @param array<int,string> $timezones
     * @param array<string,FieldExpressionProviderInterface> $providers
     */
    private function sql(
        ResolvedQuery $query,
        array $counted = [],
        array $timezones = [1 => 'UTC'],
        array $providers = [],
        ?StoreTimezoneMap $dateMap = null,
    ): string {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($this->createSqlAdapter());
        $resource->method('getTableName')->willReturnArgument(0);

        return $this->normalizeSql($this->builder($resource, 'default', $counted, $timezones, $providers, $dateMap)->build($query));
    }

    /**
     * @param string[] $counted
     * @param array<int,string> $timezones
     * @param array<string,FieldExpressionProviderInterface> $providers
     * @param array<string,BaseSelectProviderInterface> $baseProviders
     */
    private function builder(
        ResourceConnection $resource,
        string $connectionName,
        array $counted = [],
        array $timezones = [1 => 'UTC'],
        array $providers = [],
        ?StoreTimezoneMap $dateMap = null,
        array $baseProviders = [],
    ): SelectBuilder {
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn($connectionName);
        $config->method('getCountedStatuses')->willReturn($counted);

        $map = $this->createStub(StoreTimezoneMap::class);
        $map->method('getTimezonesForStores')->willReturn($timezones);
        $timezoneAdapter = $this->createSqlAdapter();
        $tzResource = $this->createStub(ResourceConnection::class);
        $tzResource->method('getConnectionByName')->willReturn($timezoneAdapter);
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('0');
        $strategy = new TimezoneStrategy(
            $map,
            $this->createStub(TimezoneSupportDetector::class),
            $tzResource,
            $config,
            $scopeConfig
        );

        $resolver = new FieldExpressionResolver($providers);
        $guardrails = $this->createStub(Guardrails::class);
        $guardrails->method('getMaxRows')->willReturn(100);
        $aggregates = new AggregateExpressionBuilder($resolver);

        return new SelectBuilder(
            $resource,
            $config,
            new JoinRegistry(),
            $resolver,
            $aggregates,
            new DimensionExpressionBuilder($resolver, $strategy),
            new ImplicitFilterPool(new CountedStatusCondition($config), $resolver),
            new UserFilterBuilder(),
            new DateScopeApplier($resolver, $dateMap ?? $map),
            new SortApplier($aggregates),
            $strategy,
            $dateMap ?? $map,
            $guardrails,
            $baseProviders,
        );
    }

    /**
     * @param string[] $metrics
     * @param DimensionSelection[] $dimensions
     * @param FilterCondition[] $filters
     * @param array<string,string>|null $implicit
     * @param int[] $storeIds
     */
    private function query(
        array $metrics,
        array $dimensions = [],
        array $filters = [],
        ?array $implicit = null,
        ?Dataset $dataset = null,
        array $storeIds = [],
        bool $withRange = false,
    ): ResolvedQuery {
        $dataset ??= $this->dataset();
        if ($implicit !== null) {
            $dataset = new Dataset(
                $dataset->code,
                $dataset->label,
                $dataset->aclResource,
                $dataset->area,
                false,
                $dataset->baseTable,
                $dataset->baseAlias,
                null,
                $implicit,
                $dataset->fields,
                $dataset->joins,
                $dataset->metrics,
                $dataset->dimensions
            );
        }

        return new ResolvedQuery($dataset, $metrics, $dimensions, $filters, $withRange ? new ResolvedDateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'), false, 31) : null, $storeIds, [], null, 0);
    }

    private function dataset(string $prefix = 'main'): Dataset
    {
        $field = static fn (string $code, FieldType $type, string $expr, array $joins = []): Field => new Field(
            $code,
            $code,
            $type,
            ['mysql' => new SourceMapping($expr, null, $joins)]
        );
        $metric = static fn (
            string $code,
            AggregateFunction $fn,
            string $fieldCode,
            ?string $condition = null
        ): Metric => new Metric($code, $code, null, 'number', false, false, $fn, $fieldCode, $condition);

        return new Dataset(
            'sales_orders',
            'Orders',
            'acl',
            'sales',
            false,
            'sales_order',
            $prefix,
            null,
            ['counted_status' => 'counted_status'],
            [
                'created_at' => $field('created_at', FieldType::DATE, $prefix . '.created_at'),
                'store_id' => $field('store_id', FieldType::ID, $prefix . '.store_id'),
                'status' => $field('status', FieldType::STRING, $prefix . '.status'),
                'order_id' => $field('order_id', FieldType::ID, $prefix . '.entity_id'),
                'customer_id' => $field('customer_id', FieldType::ID, $prefix . '.customer_id'),
                'customer_group_id' => $field('customer_group_id', FieldType::ID, $prefix . '.customer_group_id'),
                'subtotal' => $field('subtotal', FieldType::MONEY, $prefix . '.base_subtotal'),
                'is_new' => $field('is_new', FieldType::BOOLEAN, $prefix . '.is_new'),
                'method' => $field('method', FieldType::STRING, 'payment.method', ['payment']),
                'address' => $field('address', FieldType::STRING, 'billing.city', ['billing']),
                'first_order' => new Field(
                    'first_order',
                    'first_order',
                    FieldType::BOOLEAN,
                    ['mysql' => new SourceMapping(null, 'first_order')]
                ),
            ],
            [
                'payment' => new Join(
                    'payment',
                    'sales_order_payment',
                    'payment',
                    'payment.parent_id = ' . $prefix . '.entity_id'
                ),
                'billing' => new Join(
                    'billing',
                    'sales_order_address',
                    'billing',
                    'billing.parent_id = ' . $prefix . '.entity_id'
                ),
            ],
            [
                'orders' => $metric('orders', AggregateFunction::COUNT, 'order_id'),
                'gross' => $metric('gross', AggregateFunction::SUM, 'subtotal'),
                'new_gross' => $metric('new_gross', AggregateFunction::SUM, 'subtotal', 'is_new'),
                'new_customers' => $metric('new_customers', AggregateFunction::COUNT_DISTINCT, 'customer_id', 'is_new'),
                'first_orders' => $metric('first_orders', AggregateFunction::SUM, 'first_order'),
                'avg_gross' => new Metric('avg_gross', 'avg_gross', null, 'money', false, false, null, null, null, 'gross / orders'),
            ],
            [
                'customer_group_id' => new Dimension('customer_group_id', 'Group', 'customer_group_id'),
                'method' => new Dimension('method', 'Method', 'method'),
                'order_hour' => new Dimension('order_hour', 'Hour', 'created_at'),
                'order_date' => new Dimension(
                    'order_date',
                    'Date',
                    'created_at',
                    [Granularity::DAY, Granularity::MONTH]
                ),
            ]
        );
    }
}
