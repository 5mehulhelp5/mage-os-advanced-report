<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\LabelResolverInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\DimensionExpressionBuilder;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\MysqlDataSource;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\DataSource\Mysql\SelectBuilder;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneStrategy;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneSupportDetector;
use MageOS\AdvancedReport\Model\DataSource\Mysql\UtcHourFallbackSelect;
use MageOS\AdvancedReport\Model\DataSource\Mysql\UtcHourReaggregator;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\FilterOperator;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use MageOS\AdvancedReport\Model\Label\DimensionLabelApplier;
use MageOS\AdvancedReport\Model\Label\LabelResolverPool;
use MageOS\AdvancedReport\Model\Query\DerivedMetricCalculator;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\FilterCondition;
use MageOS\AdvancedReport\Model\Query\ReportScope;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use MageOS\AdvancedReport\Model\Query\SortOrder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MysqlDataSource::class)]
#[AllowMockObjectsWithoutExpectations]
final class MysqlDataSourceTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private SelectBuilder&MockObject $selectBuilder;
    private Guardrails&MockObject $guardrails;
    private TimezoneStrategy&MockObject $strategy;
    private DimensionExpressionBuilder&MockObject $dimensionBuilder;
    private UtcHourReaggregator&MockObject $reaggregator;
    private LabelResolverInterface&MockObject $labelResolver;
    private MysqlDataSource $source;
    private ?ResolvedQuery $captured = null;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturnCallback(
            fn (string $name): AdapterInterface => $name === 'reporting' ? $this->connection : throw new \LogicException($name)
        );
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn('reporting');
        $this->selectBuilder = $this->createMock(SelectBuilder::class);
        $this->guardrails = $this->createMock(Guardrails::class);
        $this->guardrails->method('applyTimeout')->willReturnCallback(
            static fn (string $sql): string => 'T:' . $sql
        );
        $this->guardrails->method('getMaxRows')->willReturn(100);
        $this->strategy = $this->createMock(TimezoneStrategy::class);
        $this->strategy->method('getName')->willReturn(TimezoneSupportDetector::STRATEGY_CONVERT_TZ);
        $this->dimensionBuilder = $this->createMock(DimensionExpressionBuilder::class);
        $this->reaggregator = $this->createMock(UtcHourReaggregator::class);
        $this->labelResolver = $this->createMock(LabelResolverInterface::class);
        $this->source = new MysqlDataSource(
            new ReportConnection($resource, $config),
            $this->selectBuilder,
            $this->guardrails,
            $this->strategy,
            $this->reaggregator,
            new DerivedMetricCalculator(),
            $this->dimensionBuilder,
            new DimensionLabelApplier(new LabelResolverPool(['Resolver' => $this->labelResolver])),
        );
    }

    public function testItSupportsRequestsWhoseFieldsAllHaveMysqlMappings(): void
    {
        $dataset = $this->dataset();

        self::assertSame('mysql', $this->source->getCode());
        self::assertTrue($this->source->supports($dataset, $this->request(['net', 'aov'], ['country'])));
        self::assertTrue($this->source->supports(
            $dataset,
            $this->request(['net'], [], [new FilterCondition('country', FilterOperator::EQ, 'IT')])
        ));
        self::assertFalse($this->source->supports($dataset, $this->request(['unmapped_metric'], [])));
        self::assertFalse($this->source->supports($dataset, $this->request(['net'], ['unmapped_dim'])));
        self::assertFalse($this->source->supports(
            $dataset,
            $this->request(['net'], [], [new FilterCondition('unmapped', FilterOperator::EQ, 'x')])
        ));
    }

    public function testItTreatsExpressionProviderMappingsAsMysqlMappedFields(): void
    {
        $dataset = $this->dataset();

        self::assertTrue($this->source->supports($dataset, $this->request(['net'], ['customer_kind'])));
    }

    public function testItExecutesRowsTotalsAndRowCountQueriesOnTheConfiguredConnection(): void
    {
        $query = $this->query(['net', 'orders'], ['country'], limit: 10, offset: 20);
        $this->selectBuilder->method('hasDateBucketDimension')->willReturn(false);
        $this->selectBuilder->method('buildPage')->willReturnCallback(
            function (ResolvedQuery $q): Select {
                $this->captured = $q;

                return $this->select('PAGE');
            }
        );
        $this->selectBuilder->method('buildTotals')->willReturn($this->select('TOTALS'));
        $this->selectBuilder->method('buildRowCount')->willReturn($this->select('COUNT'));
        $this->connection->expects(self::once())->method('fetchAll')
            ->with('T:PAGE', ['b' => 1])
            ->willReturn(array_fill(0, 10, ['country' => 'IT', 'net' => '12.50', 'orders' => '3']));
        $this->connection->expects(self::once())->method('fetchRow')
            ->with('T:TOTALS', ['b' => 1])
            ->willReturn(['net' => '12.50', 'orders' => '3']);
        $this->connection->expects(self::once())->method('fetchOne')
            ->with('T:COUNT', ['b' => 1])
            ->willReturn('42');

        $result = $this->source->execute($this->request(['net'], ['country']), $query);

        self::assertSame(42, $result->totalRowCount);
        self::assertSame(['metrics' => ['net' => 12.5, 'orders' => 3]], $result->totals);
        self::assertSame(['country' => 'IT'], $result->rows[0]['dims_raw']);
        self::assertSame(10, $this->captured()->limit);
        self::assertSame(20, $this->captured()->offset);
    }

    public function testItSkipsTheRowCountQueryWhenTheFirstPageIsIncomplete(): void
    {
        $this->stubNormalPath([['net' => '1'], ['net' => '2']]);
        $this->connection->expects(self::never())->method('fetchOne');

        $result = $this->source->execute($this->request(['net'], []), $this->query(['net'], [], limit: 10));

        self::assertSame(2, $result->totalRowCount);
    }

    public function testItDerivesTheTotalRowCountFromTheOffsetWhenALaterPageIsIncomplete(): void
    {
        $this->stubNormalPath([['net' => '1'], ['net' => '2'], ['net' => '3']]);
        $this->connection->expects(self::never())->method('fetchOne');

        $result = $this->source->execute($this->request(['net'], []), $this->query(['net'], [], limit: 10, offset: 20));

        self::assertSame(23, $result->totalRowCount);
    }

    public function testItRunsTheRowCountQueryWhenThePageIsFull(): void
    {
        $this->stubNormalPath([['net' => '1'], ['net' => '2']], '42');
        $this->connection->expects(self::once())->method('fetchOne');

        $result = $this->source->execute($this->request(['net'], []), $this->query(['net'], [], limit: 2));

        self::assertSame(42, $result->totalRowCount);
    }

    public function testItRunsTheRowCountQueryWhenALaterPageIsEmpty(): void
    {
        $this->stubNormalPath([], '42');
        $this->connection->expects(self::once())->method('fetchOne');

        $result = $this->source->execute($this->request(['net'], []), $this->query(['net'], [], limit: 10, offset: 20));

        self::assertSame(42, $result->totalRowCount);
    }

    public function testItNeverRunsTheRowCountQueryWhenCountingIsDisabled(): void
    {
        $this->stubNormalPath([['net' => '1'], ['net' => '2']], '42');
        $this->connection->expects(self::never())->method('fetchOne');

        $result = $this->source->execute(
            $this->request(['net'], []),
            $this->query(['net'], [], limit: 2, countRows: false)
        );

        self::assertSame(2, $result->totalRowCount);
    }

    public function testItReportsTheNumberOfExecutedSqlStatementsInTheResultMeta(): void
    {
        $this->stubNormalPath([['net' => '1'], ['net' => '2']], '42');
        $full = $this->source->execute($this->request(['net'], []), $this->query(['net'], [], limit: 2));
        $incomplete = $this->source->execute($this->request(['net'], []), $this->query(['net'], [], limit: 10));

        self::assertSame(3, $full->meta['sql_query_count']);
        self::assertSame(2, $incomplete->meta['sql_query_count']);
    }

    public function testItReportsTheExecutedSqlStatementsOfTheFallbackPath(): void
    {
        $strategy = $this->createMock(TimezoneStrategy::class);
        $strategy->method('getName')->willReturn(TimezoneSupportDetector::STRATEGY_UTC_HOUR_FALLBACK);
        $this->stubFallback();
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->method('fetchRow')->willReturn([]);
        $this->reaggregator->method('reaggregate')->willReturn(['rows' => [], 'warnings' => []]);
        $source = $this->withStrategy($strategy);

        $result = $source->execute($this->request(['net'], ['country']), $this->query(['net'], ['country']));

        self::assertSame(2, $result->meta['sql_query_count']);
    }

    public function testItKeepsTheCountRowsFlagWhenCappingTheLimit(): void
    {
        $this->stubNormalPath([]);

        $this->source->execute($this->request(['net'], []), $this->query(['net'], [], countRows: false));

        self::assertFalse($this->captured()->countRows);
    }

    public function testItAppliesTheMaxRowsCapToTheNormalPath(): void
    {
        $this->stubNormalPath([]);

        $this->source->execute($this->request(['net'], []), $this->query(['net'], [], limit: null));
        self::assertSame(100, $this->captured()->limit);

        $this->source->execute($this->request(['net'], []), $this->query(['net'], [], limit: 50));
        self::assertSame(50, $this->captured()->limit);

        $this->source->execute($this->request(['net'], []), $this->query(['net'], [], limit: 500));
        self::assertLessThanOrEqual(100, $this->captured()->limit);
    }

    public function testItUsesTheFallbackReaggregatorWhenConvertTzIsUnavailable(): void
    {
        $strategy = $this->createMock(TimezoneStrategy::class);
        $strategy->method('getName')->willReturn(TimezoneSupportDetector::STRATEGY_UTC_HOUR_FALLBACK);
        $source = $this->withStrategy($strategy);
        $query = $this->query(['net'], ['created_at', 'country'], storeIds: [1]);
        $this->stubFallback();
        $this->connection->expects(self::once())->method('fetchAll')->with('T:FALLBACK', [])->willReturn([
            ['utc_hour' => '2024-03-10 22:00:00', 'country' => 'IT', 'net' => '10.00'],
            ['utc_hour' => '2024-03-10 23:00:00', 'country' => 'IT', 'net' => '5.00'],
        ]);
        $this->connection->method('fetchRow')->willReturn(['net' => '15.00']);
        $this->reaggregator->expects(self::once())->method('reaggregate')
            ->with(
                [
                    $this->row(['country' => 'IT', 'utc_hour' => '2024-03-10 22:00:00'], ['net' => 10.0]),
                    $this->row(['country' => 'IT', 'utc_hour' => '2024-03-10 23:00:00'], ['net' => 5.0]),
                ],
                'created_at',
                'day',
                self::anything(),
                [1],
                null,
                false
            )
            ->willReturn([
                'rows' => [$this->row(['created_at' => '2024-03-10', 'country' => 'IT'], ['net' => 15.0])],
                'warnings' => ['re-aggregated'],
            ]);

        $result = $source->execute($this->request(['net'], ['created_at']), $query);

        self::assertSame(['created_at' => '2024-03-10', 'country' => 'IT'], $result->rows[0]['dims_raw']);
        self::assertSame(1, $result->totalRowCount);
        self::assertContains('re-aggregated', $result->warnings);
        self::assertSame(TimezoneSupportDetector::STRATEGY_UTC_HOUR_FALLBACK, $result->meta['timezone_strategy']);
    }

    public function testItSortsPaginatesAndCountsRowsInPhpInFallbackMode(): void
    {
        $strategy = $this->createMock(TimezoneStrategy::class);
        $strategy->method('getName')->willReturn(TimezoneSupportDetector::STRATEGY_UTC_HOUR_FALLBACK);
        $source = $this->withStrategy($strategy);
        $query = $this->query(
            ['net', 'orders'],
            ['created_at'],
            sort: [new SortOrder('aov', 'desc')],
            limit: 2,
            offset: 1
        );
        $this->stubFallback();
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->method('fetchRow')->willReturn(['net' => '0', 'orders' => '0']);
        $this->reaggregator->method('reaggregate')->willReturn([
            'rows' => [
                $this->row(['created_at' => '2024-03-01'], ['net' => 10.0, 'orders' => 1]),
                $this->row(['created_at' => '2024-03-02'], ['net' => 90.0, 'orders' => 3]),
                $this->row(['created_at' => '2024-03-03'], ['net' => 50.0, 'orders' => 1]),
                $this->row(['created_at' => '2024-03-04'], ['net' => 30.0, 'orders' => 1]),
            ],
            'warnings' => [],
        ]);

        $result = $source->execute($this->request(['net'], ['created_at']), $query);

        self::assertSame(4, $result->totalRowCount);
        self::assertSame(
            ['2024-03-02', '2024-03-04'],
            array_map(static fn (array $row): ?string => $row['dims_raw']['created_at'], $result->rows)
        );
        self::assertSame(['net' => 30.0, 'orders' => 1], $result->rows[1]['metrics']);
    }

    public function testItUsesDimensionTieBreakersWhenSortingInFallbackMode(): void
    {
        $strategy = $this->createMock(TimezoneStrategy::class);
        $strategy->method('getName')->willReturn(TimezoneSupportDetector::STRATEGY_UTC_HOUR_FALLBACK);
        $source = $this->withStrategy($strategy);
        $query = $this->query(['net'], ['created_at'], sort: [new SortOrder('net', 'desc')]);
        $this->stubFallback();
        $this->connection->method('fetchAll')->willReturn([]);
        $this->connection->method('fetchRow')->willReturn(['net' => '0']);
        $this->reaggregator->method('reaggregate')->willReturn([
            'rows' => [
                $this->row(['created_at' => '2024-03-02'], ['net' => 5.0]),
                $this->row(['created_at' => '2024-03-01'], ['net' => 5.0]),
                $this->row(['created_at' => '2024-03-03'], ['net' => 9.0]),
            ],
            'warnings' => [],
        ]);

        $result = $source->execute($this->request(['net'], ['created_at']), $query);

        self::assertSame(
            ['2024-03-03', '2024-03-01', '2024-03-02'],
            array_map(static fn (array $row): ?string => $row['dims_raw']['created_at'], $result->rows)
        );
    }

    public function testItKeepsTheNormalSqlPathInFallbackModeWithoutDateDimensions(): void
    {
        $strategy = $this->createMock(TimezoneStrategy::class);
        $strategy->method('getName')->willReturn(TimezoneSupportDetector::STRATEGY_UTC_HOUR_FALLBACK);
        $source = $this->withStrategy($strategy);
        $this->stubNormalPath([['country' => 'IT', 'net' => '1']]);
        $this->reaggregator->expects(self::never())->method('reaggregate');

        $result = $source->execute($this->request(['net'], ['country']), $this->query(['net'], ['country']));

        self::assertSame('IT', $result->rows[0]['dims_raw']['country']);
    }

    public function testItCastsMetricValuesByFieldType(): void
    {
        $this->stubNormalPath(
            [['net' => '12.50', 'orders' => '3', 'qty' => '7.0000', 'avg_qty' => '2.5', 'empty' => null]],
        );
        $query = $this->query(['net', 'orders', 'qty', 'avg_qty', 'empty'], []);

        $result = $this->source->execute($this->request(['net'], []), $query);

        $metrics = $result->rows[0]['metrics'];
        self::assertSame(12.5, $metrics['net']);
        self::assertSame(3, $metrics['orders']);
        self::assertSame(7, $metrics['qty']);
        self::assertSame(2.5, $metrics['avg_qty']);
        self::assertNull($metrics['empty']);
    }

    public function testItReportsTheTimezoneStrategyInResultMeta(): void
    {
        $this->stubNormalPath([]);

        $result = $this->source->execute($this->request(['net'], []), $this->query(['net'], []));

        self::assertSame(TimezoneSupportDetector::STRATEGY_CONVERT_TZ, $result->meta['timezone_strategy']);
        self::assertIsFloat($result->meta['sql_time_ms']);
    }

    public function testItPropagatesGuardrailWarnings(): void
    {
        $this->stubNormalPath(array_fill(0, 100, ['net' => '1']), '250');
        $this->guardrails->expects(self::once())->method('getTruncationWarning')->with(250)->willReturn(__('Results were truncated'));

        $result = $this->source->execute($this->request(['net'], []), $this->query(['net'], []));

        self::assertSame(['Results were truncated'], $result->warnings);
    }

    public function testItMapsDriverExceptionsThroughTheGuardrails(): void
    {
        $this->selectBuilder->method('hasDateBucketDimension')->willReturn(false);
        $this->selectBuilder->method('buildPage')->willReturn($this->select('PAGE'));
        $this->connection->method('fetchAll')->willThrowException(new \RuntimeException('raw'));
        $mapped = new \DomainException('mapped');
        $this->guardrails->method('mapException')->willReturn($mapped);

        $this->expectExceptionObject($mapped);
        $this->source->execute($this->request(['net'], []), $this->query(['net'], []));
    }

    public function testItResolvesDimensionLabelsWhileKeepingRawValues(): void
    {
        $this->stubNormalPath(
            [['country' => 'IT', 'net' => '1'], ['country' => null, 'net' => '2']],
        );
        $this->labelResolver->expects(self::once())->method('resolve')
            ->with(['IT', ''], ['kind' => 'x'])
            ->willReturn(['IT' => 'Italy', '' => '(none)']);

        $result = $this->source->execute($this->request(['net'], ['country']), $this->query(['net'], ['country']));

        self::assertSame(['country' => 'Italy'], $result->rows[0]['dims']);
        self::assertSame(['country' => 'IT'], $result->rows[0]['dims_raw']);
        self::assertSame(['country' => '(none)'], $result->rows[1]['dims']);
        self::assertSame(['country' => null], $result->rows[1]['dims_raw']);
    }

    private function captured(): ResolvedQuery
    {
        return $this->captured ?? throw new \LogicException('No query captured.');
    }

    private function withStrategy(TimezoneStrategy $strategy): MysqlDataSource
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($this->connection);
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn('reporting');

        return new MysqlDataSource(
            new ReportConnection($resource, $config),
            $this->selectBuilder,
            $this->guardrails,
            $strategy,
            $this->reaggregator,
            new DerivedMetricCalculator(),
            $this->dimensionBuilder,
            new DimensionLabelApplier(new LabelResolverPool(['Resolver' => $this->labelResolver])),
        );
    }

    private function stubFallback(): void
    {
        $this->selectBuilder->method('hasDateBucketDimension')->willReturn(true);
        $this->selectBuilder->method('buildUtcHourFallback')->willReturn(
            new UtcHourFallbackSelect($this->select('FALLBACK'), ['created_at'], 5000, 'utc_hour', null, false, 60)
        );
        $this->selectBuilder->method('buildTotals')->willReturn($this->select('TOTALS'));
        $this->dimensionBuilder->method('getBucket')->willReturn('day');
    }

    /**
     * @param array<int,array<string,mixed>> $fetched
     */
    private function stubNormalPath(array $fetched, string $count = '1'): void
    {
        $this->selectBuilder->method('hasDateBucketDimension')->willReturn(false);
        $this->selectBuilder->method('buildPage')->willReturnCallback(
            function (ResolvedQuery $q): Select {
                $this->captured = $q;

                return $this->select('PAGE');
            }
        );
        $this->selectBuilder->method('buildTotals')->willReturn($this->select('TOTALS'));
        $this->selectBuilder->method('buildRowCount')->willReturn($this->select('COUNT'));
        $this->connection->method('fetchAll')->willReturn($fetched);
        $this->connection->method('fetchRow')->willReturn(['net' => '1']);
        $this->connection->method('fetchOne')->willReturn($count);
    }

    private function select(string $sql): Select&MockObject
    {
        $select = $this->createMock(Select::class);
        $select->method('__toString')->willReturn($sql);
        $select->method('getBind')->willReturn($sql === 'FALLBACK' ? [] : ['b' => 1]);

        return $select;
    }

    /**
     * @param array<string,string> $dims
     * @param array<string,int|float|null> $metrics
     * @return array{dims: array<string,string>, dims_raw: array<string,string>, metrics: array<string,int|float|null>}
     */
    private function row(array $dims, array $metrics): array
    {
        return ['dims' => $dims, 'dims_raw' => $dims, 'metrics' => $metrics];
    }

    /**
     * @param string[] $metrics
     * @param string[] $dimensions
     * @param FilterCondition[] $filters
     */
    private function request(array $metrics, array $dimensions, array $filters = []): ReportRequestInterface
    {
        $request = $this->createStub(ReportRequestInterface::class);
        $request->method('getMetrics')->willReturn($metrics);
        $request->method('getDimensions')->willReturn(
            array_map(static fn (string $code): DimensionSelection => new DimensionSelection($code), $dimensions)
        );
        $request->method('getFilters')->willReturn($filters);

        return $request;
    }

    /**
     * @param string[] $metrics
     * @param string[] $dimensions
     * @param SortOrder[] $sort
     * @param int[] $storeIds
     */
    private function query(
        array $metrics,
        array $dimensions,
        array $sort = [],
        ?int $limit = null,
        int $offset = 0,
        array $storeIds = [],
        bool $countRows = true,
    ): ResolvedQuery {
        return new ResolvedQuery(
            $this->dataset(),
            $metrics,
            array_map(static fn (string $code): DimensionSelection => new DimensionSelection($code), $dimensions),
            [],
            null,
            $storeIds,
            $sort,
            $limit,
            $offset,
            new ReportScope(null, 'UTC', 0, ['UTC']),
            $countRows
        );
    }

    private function dataset(): Dataset
    {
        $mapped = new SourceMapping('x');
        $field = static fn (string $code, FieldType $type, ?SourceMapping $mapping): Field => new Field(
            $code,
            $code,
            $type,
            $mapping === null ? [] : ['mysql' => $mapping]
        );
        $aggregate = static fn (string $code, AggregateFunction $fn, string $field): Metric => new Metric(
            $code,
            $code,
            null,
            'number',
            false,
            false,
            $fn,
            $field
        );

        return new Dataset(
            'sales',
            'Sales',
            'acl',
            'adminhtml',
            false,
            't',
            'main',
            null,
            [],
            [
                'created_at' => $field('created_at', FieldType::DATE, $mapped),
                'amount' => $field('amount', FieldType::MONEY, $mapped),
                'quantity' => $field('quantity', FieldType::INTEGER, $mapped),
                'country' => $field('country', FieldType::STRING, $mapped),
                'kind' => $field('kind', FieldType::STRING, new SourceMapping(null, 'first_order')),
                'unmapped' => $field('unmapped', FieldType::STRING, null),
            ],
            [],
            [
                'net' => $aggregate('net', AggregateFunction::SUM, 'amount'),
                'orders' => $aggregate('orders', AggregateFunction::COUNT_DISTINCT, 'amount'),
                'qty' => $aggregate('qty', AggregateFunction::SUM, 'quantity'),
                'avg_qty' => $aggregate('avg_qty', AggregateFunction::AVG, 'quantity'),
                'empty' => $aggregate('empty', AggregateFunction::SUM, 'amount'),
                'unmapped_metric' => $aggregate('unmapped_metric', AggregateFunction::SUM, 'unmapped'),
                'aov' => new Metric('aov', 'aov', null, 'money', false, false, null, null, null, 'net / orders'),
            ],
            [
                'created_at' => new Dimension('created_at', 'Date', 'created_at', []),
                'country' => new Dimension('country', 'Country', 'country', [], 'Resolver', ['kind' => 'x']),
                'customer_kind' => new Dimension('customer_kind', 'Kind', 'kind'),
                'unmapped_dim' => new Dimension('unmapped_dim', 'X', 'unmapped'),
            ]
        );
    }
}
