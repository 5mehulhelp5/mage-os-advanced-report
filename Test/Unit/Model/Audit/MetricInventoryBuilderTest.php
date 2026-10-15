<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Audit;

use MageOS\AdvancedReport\Model\Audit\MetricInventoryBuilder;
use MageOS\AdvancedReport\Model\Audit\MetricInventoryEntry;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\Join;
use MageOS\AdvancedReport\Model\Definition\JoinCardinality;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Definition\SourceMapping;
use MageOS\AdvancedReport\Model\Query\DerivedMetricCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricInventoryBuilder::class)]
#[CoversClass(MetricInventoryEntry::class)]
final class MetricInventoryBuilderTest extends TestCase
{
    public function testItReturnsOneEntryPerMetricOfEveryDataset(): void
    {
        $first = $this->dataset('a', metrics: [
            $this->sum('m1', 'f'),
            $this->sum('m2', 'f'),
            new Metric('v', 'V', null, 'number', false, false, virtual: true),
        ], fields: [$this->field('f')]);
        $second = $this->dataset('b', metrics: [$this->sum('m3', 'f')], fields: [$this->field('f')]);

        $entries = $this->build($first, $second);

        self::assertSame(
            ['a.m1', 'a.m2', 'a.v', 'b.m3'],
            array_map(static fn (MetricInventoryEntry $e): string => $e->datasetCode . '.' . $e->metricCode, $entries)
        );
        self::assertSame(MetricInventoryEntry::KIND_AGGREGATE, $entries[0]->kind);
        self::assertSame(MetricInventoryEntry::KIND_VIRTUAL, $entries[2]->kind);
        self::assertSame(AggregateFunction::SUM, $entries[0]->aggregateFunction);
        self::assertSame('t', $entries[0]->baseTable);
        self::assertSame('main.f', $entries[0]->expression);
        self::assertNull($entries[2]->expression);
    }

    public function testItResolvesJoinsTransitivelyFromFieldMappingAndJoinRequires(): void
    {
        $dataset = $this->dataset(
            'a',
            metrics: [$this->sum('m', 'f')],
            fields: [$this->field('f', joins: ['c'])],
            joins: [$this->join('a'), $this->join('b', ['a']), $this->join('c', ['b'])],
        );

        self::assertSame(['a', 'b', 'c'], $this->build($dataset)[0]->joins);
    }

    public function testItIncludesJoinsOfTheMetricConditionField(): void
    {
        $dataset = $this->dataset(
            'a',
            metrics: [new Metric('m', 'M', null, 'number', false, false, AggregateFunction::AVG, 'cost', 'has_cost')],
            fields: [$this->field('cost', joins: ['x']), $this->field('has_cost', joins: ['child'])],
            joins: [$this->join('x'), $this->join('child', ['x'])],
        );

        self::assertSame(['x', 'child'], $this->build($dataset)[0]->joins);
    }

    public function testItListsOnlyManyCardinalityJoinsAsFanOutJoins(): void
    {
        $entry = $this->build($this->manyDataset($this->sum('m', 'f')))[0];

        self::assertSame(['one', 'many'], $entry->joins);
        self::assertSame(['many'], $entry->fanOutJoins);
    }

    public function testItFlagsSumMetricOverAManyJoinAsFanOutRisk(): void
    {
        self::assertTrue($this->build($this->manyDataset($this->sum('m', 'f')))[0]->isFanOutRisk());
    }

    public function testItDoesNotFlagCountDistinctMetricOverAManyJoinAsFanOutRisk(): void
    {
        $metric = new Metric('m', 'M', null, 'number', false, false, AggregateFunction::COUNT_DISTINCT, 'f');

        $entry = $this->build($this->manyDataset($metric))[0];

        self::assertSame(['many'], $entry->fanOutJoins);
        self::assertFalse($entry->isFanOutRisk());
    }

    public function testItListsDimensionsBackedByAManyJoinAsFanOutDimensionsOfAdditiveMetrics(): void
    {
        $dataset = $this->dimensionFanOutDataset();

        $entries = $this->build($dataset);

        self::assertSame(['category_id'], $entries[0]->fanOutDimensions);
        self::assertSame([], $entries[1]->fanOutDimensions);
    }

    public function testItFlagsAdditiveMetricAsFanOutRiskWhenTheDatasetHasAFanOutDimension(): void
    {
        $entries = $this->build($this->dimensionFanOutDataset());

        self::assertSame([], $entries[0]->fanOutJoins);
        self::assertTrue($entries[0]->isFanOutRisk());
        self::assertFalse($entries[1]->isFanOutRisk());
    }

    public function testItPropagatesComponentJoinsAndFanOutRiskToDerivedMetrics(): void
    {
        $dataset = $this->dataset(
            'a',
            metrics: [
                $this->sum('s', 'f'),
                new Metric('c', 'C', null, 'number', false, false, AggregateFunction::COUNT, 'g'),
                new Metric('d', 'D', null, 'number', false, false, formula: 's / c'),
            ],
            fields: [$this->field('f', joins: ['one']), $this->field('g', joins: ['many'])],
            joins: [$this->join('one'), $this->join('many', cardinality: JoinCardinality::MANY)],
        );

        $derived = $this->build($dataset)[2];

        self::assertSame(MetricInventoryEntry::KIND_DERIVED, $derived->kind);
        self::assertSame('s / c', $derived->expression);
        self::assertSame(['one', 'many'], $derived->joins);
        self::assertSame(['many'], $derived->fanOutJoins);
        self::assertTrue($derived->isFanOutRisk());
    }

    public function testItMarksMoneyMetricsWithCurrencyDependency(): void
    {
        $dataset = $this->dataset(
            'a',
            metrics: [
                $this->sum('money', 'm'),
                $this->sum('plain', 'f'),
                new Metric('d', 'D', null, 'money', false, false, formula: 'money / plain'),
            ],
            fields: [$this->field('m', FieldType::MONEY), $this->field('f')],
        );

        $entries = $this->build($dataset);

        self::assertContains(MetricInventoryEntry::DEPENDENCY_CURRENCY, $entries[0]->dependencies);
        self::assertNotContains(MetricInventoryEntry::DEPENDENCY_CURRENCY, $entries[1]->dependencies);
        self::assertContains(MetricInventoryEntry::DEPENDENCY_CURRENCY, $entries[2]->dependencies);
    }

    public function testItMarksMetricsOfDatasetsWithTheCountedStatusImplicitFilterWithCountedStatusesDependency(): void
    {
        $with = $this->dataset('a', metrics: [$this->sum('m', 'f')], fields: [$this->field('f')], implicitFilters: [
            'counted_status' => true,
        ]);
        $without = $this->dataset('b', metrics: [$this->sum('m', 'f')], fields: [$this->field('f')]);

        $entries = $this->build($with, $without);

        self::assertContains(MetricInventoryEntry::DEPENDENCY_COUNTED_STATUSES, $entries[0]->dependencies);
        self::assertNotContains(MetricInventoryEntry::DEPENDENCY_COUNTED_STATUSES, $entries[1]->dependencies);
    }

    public function testItMarksMetricsUsingTheFirstOrderExpressionProviderWithCountedStatusesDependency(): void
    {
        $dataset = $this->dataset(
            'a',
            metrics: [new Metric('m', 'M', null, 'number', false, false, AggregateFunction::COUNT, 'first')],
            fields: [new Field('first', 'First', FieldType::DATE, [
                'mysql' => new SourceMapping(expressionProvider: 'first_order'),
            ])],
        );

        self::assertContains(MetricInventoryEntry::DEPENDENCY_COUNTED_STATUSES, $this->build($dataset)[0]->dependencies);
    }

    public function testItMarksMetricsOfDatelessDatasetsWithoutTimezoneDependency(): void
    {
        $dated = $this->dataset('a', metrics: [$this->sum('m', 'f')], fields: [$this->field('f')]);
        $dateless = $this->dataset('b', metrics: [$this->sum('m', 'f')], fields: [$this->field('f')], dateless: true);

        $entries = $this->build($dated, $dateless);

        self::assertContains(MetricInventoryEntry::DEPENDENCY_TIMEZONE, $entries[0]->dependencies);
        self::assertNotContains(MetricInventoryEntry::DEPENDENCY_TIMEZONE, $entries[1]->dependencies);
    }

    public function testItReportsTheBaseProviderAndStockProviderDependencyWhenTheDatasetHasNoBaseTable(): void
    {
        $stock = $this->dataset(
            'a',
            metrics: [$this->sum('m', 'f')],
            fields: [$this->field('f')],
            baseTable: null,
            baseProvider: 'catalog_stock',
        );
        $table = $this->dataset('b', metrics: [$this->sum('m', 'f')], fields: [$this->field('f')]);

        $entries = $this->build($stock, $table);

        self::assertNull($entries[0]->baseTable);
        self::assertSame('catalog_stock', $entries[0]->baseProvider);
        self::assertContains(MetricInventoryEntry::DEPENDENCY_STOCK_PROVIDER, $entries[0]->dependencies);
        self::assertNull($entries[1]->baseProvider);
        self::assertNotContains(MetricInventoryEntry::DEPENDENCY_STOCK_PROVIDER, $entries[1]->dependencies);
    }

    public function testItReportsExpressionProviderFieldsAsProviderExpressions(): void
    {
        $dataset = $this->dataset(
            'a',
            metrics: [
                new Metric('m', 'M', null, 'number', false, false, AggregateFunction::SUM, 'p'),
                $this->sum('raw', 'f'),
            ],
            fields: [
                new Field('p', 'P', FieldType::INTEGER, ['mysql' => new SourceMapping(expressionProvider: 'shipment_count')]),
                $this->field('f'),
            ],
        );

        $entries = $this->build($dataset);

        self::assertSame('provider:shipment_count', $entries[0]->expression);
        self::assertSame('main.f', $entries[1]->expression);
    }

    /**
     * @return MetricInventoryEntry[]
     */
    private function build(Dataset ...$datasets): array
    {
        $registry = $this->createStub(DefinitionRegistry::class);
        $registry->method('getDatasets')->willReturn(array_column(
            array_map(static fn (Dataset $d): array => [$d->code, $d], $datasets),
            1,
            0
        ));

        return (new MetricInventoryBuilder($registry, new DerivedMetricCalculator()))->build();
    }

    private function manyDataset(Metric $metric): Dataset
    {
        return $this->dataset(
            'a',
            metrics: [$metric],
            fields: [$this->field('f', joins: ['many'])],
            joins: [$this->join('one'), $this->join('many', ['one'], JoinCardinality::MANY)],
        );
    }

    private function dimensionFanOutDataset(): Dataset
    {
        return $this->dataset(
            'a',
            metrics: [
                $this->sum('s', 'f'),
                new Metric('mx', 'Mx', null, 'number', false, false, AggregateFunction::MAX, 'f'),
            ],
            fields: [$this->field('f'), $this->field('cat', joins: ['many'])],
            joins: [$this->join('many', cardinality: JoinCardinality::MANY)],
            dimensions: [new Dimension('category_id', 'Category', 'cat'), new Dimension('plain', 'Plain', 'f')],
        );
    }

    private function sum(string $code, string $fieldCode): Metric
    {
        return new Metric($code, strtoupper($code), null, 'number', false, false, AggregateFunction::SUM, $fieldCode);
    }

    /**
     * @param string[] $joins
     */
    private function field(string $code, FieldType $type = FieldType::INTEGER, array $joins = []): Field
    {
        return new Field($code, $code, $type, ['mysql' => new SourceMapping('main.' . $code, null, $joins)]);
    }

    /**
     * @param string[] $requires
     */
    private function join(string $code, array $requires = [], JoinCardinality $cardinality = JoinCardinality::ONE): Join
    {
        return new Join($code, 't_' . $code, $code, 'cond', $requires, $cardinality);
    }

    /**
     * @param Metric[] $metrics
     * @param Field[] $fields
     * @param Join[] $joins
     * @param Dimension[] $dimensions
     * @param array<string,mixed> $implicitFilters
     */
    private function dataset(
        string $code,
        array $metrics,
        array $fields = [],
        array $joins = [],
        array $dimensions = [],
        array $implicitFilters = [],
        bool $dateless = false,
        ?string $baseTable = 't',
        ?string $baseProvider = null,
    ): Dataset {
        return new Dataset(
            $code,
            $code,
            'acl',
            'sales',
            $dateless,
            $baseTable,
            'main',
            $baseProvider,
            $implicitFilters,
            array_column(array_map(static fn (Field $f): array => [$f->code, $f], $fields), 1, 0),
            array_column(array_map(static fn (Join $j): array => [$j->code, $j], $joins), 1, 0),
            array_column(array_map(static fn (Metric $m): array => [$m->code, $m], $metrics), 1, 0),
            array_column(array_map(static fn (Dimension $d): array => [$d->code, $d], $dimensions), 1, 0),
        );
    }
}
