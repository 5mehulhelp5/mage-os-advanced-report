<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\DataSourceInterface;
use MageOS\AdvancedReport\Model\DataSource\DataSourcePool;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Query\NoSourceAvailableException;
use MageOS\AdvancedReport\Model\Query\SourceResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceResolver::class)]
final class SourceResolverTest extends TestCase
{
    private DefinitionRegistry $definitionRegistry;

    private Dataset $dataset;

    protected function setUp(): void
    {
        $this->definitionRegistry = $this->createStub(DefinitionRegistry::class);
        $this->dataset = $this->buildDataset();
    }

    public function testItReturnsTheOverriddenSourceWhenItSupportsTheRequest(): void
    {
        $mysql = $this->buildSource('mysql', true);
        $opensearch = $this->buildSource('opensearch', true);
        $pool = $this->buildPool([
            'opensearch' => [$opensearch, 20],
            'mysql' => [$mysql, 10],
        ]);
        $request = $this->buildRequest(source: 'mysql');
        $resolver = new SourceResolver($pool, $this->definitionRegistry);

        self::assertSame($mysql, $resolver->resolve($this->dataset, $request));
    }

    public function testItRejectsAnOverrideNamingAnUnknownSource(): void
    {
        $mysql = $this->buildSource('mysql', true);
        $pool = $this->buildPool(['mysql' => [$mysql, 10]]);
        $request = $this->buildRequest(source: 'unknown');
        $resolver = new SourceResolver($pool, $this->definitionRegistry);

        $this->expectException(NoSourceAvailableException::class);

        $resolver->resolve($this->dataset, $request);
    }

    public function testItPicksTheFirstSupportingSourceInPoolOrder(): void
    {
        $mysql = $this->buildSource('mysql', false);
        $opensearch = $this->buildSource('opensearch', true);
        $pool = $this->buildPool([
            'mysql' => [$mysql, 10],
            'opensearch' => [$opensearch, 20],
        ]);
        $request = $this->buildRequest();
        $resolver = new SourceResolver($pool, $this->definitionRegistry);

        self::assertSame($opensearch, $resolver->resolve($this->dataset, $request));
    }

    public function testItSkipsSourcesExcludedByThePresetSourcesRestriction(): void
    {
        $excluded = $this->buildSource('excluded', true);
        $included = $this->buildSource('included', true);
        $pool = $this->buildPool([
            'excluded' => [$excluded, 10],
            'included' => [$included, 20],
        ]);
        $request = $this->buildRequest(preset: 'sales_overview');
        $this->definitionRegistry = $this->createStub(DefinitionRegistry::class);
        $this->definitionRegistry->method('getPreset')->willReturn($this->buildPreset(['included']));
        $resolver = new SourceResolver($pool, $this->definitionRegistry);

        self::assertSame($included, $resolver->resolve($this->dataset, $request));
    }

    public function testItThrowsWhenNoSourceSupportsTheRequest(): void
    {
        $mysql = $this->buildSource('mysql', false);
        $pool = $this->buildPool(['mysql' => [$mysql, 10]]);
        $request = $this->buildRequest();
        $resolver = new SourceResolver($pool, $this->definitionRegistry);

        $this->expectException(NoSourceAvailableException::class);

        $resolver->resolve($this->dataset, $request);
    }

    /**
     * @param array<string,array{0: DataSourceInterface, 1: int}> $entries
     */
    private function buildPool(array $entries): DataSourcePool
    {
        $config = [];
        foreach ($entries as $code => [$source, $sortOrder]) {
            $config[$code] = ['source' => $source, 'sortOrder' => $sortOrder];
        }

        return new DataSourcePool($config);
    }

    private function buildSource(string $code, bool $supports): DataSourceInterface
    {
        $source = $this->createStub(DataSourceInterface::class);
        $source->method('getCode')->willReturn($code);
        $source->method('supports')->willReturn($supports);

        return $source;
    }

    private function buildRequest(?string $source = null, ?string $preset = null): ReportRequestInterface
    {
        $request = $this->createStub(ReportRequestInterface::class);
        $request->method('getSource')->willReturn($source);
        $request->method('getPreset')->willReturn($preset);

        return $request;
    }

    /**
     * @param string[] $sources
     */
    private function buildPreset(array $sources): Preset
    {
        return new Preset(
            code: 'sales_overview',
            area: 'sales',
            label: 'Sales overview',
            dataset: 'sales_orders',
            metrics: [],
            dimensions: [],
            filters: [],
            sort: [],
            compareTo: 'none',
            dateRange: null,
            sources: $sources,
        );
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
            metrics: [],
            dimensions: [],
        );
    }
}
