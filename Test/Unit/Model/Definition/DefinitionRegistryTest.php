<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Definition;

use Magento\Framework\Config\DataInterface;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DatasetAvailabilityInterface;
use MageOS\AdvancedReport\Model\Definition\DatasetExtenderInterface;
use MageOS\AdvancedReport\Model\Definition\DefinitionFactory;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\DefinitionValidator;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(DefinitionRegistry::class)]
#[CoversClass(DefinitionFactory::class)]
final class DefinitionRegistryTest extends TestCase
{
    public function testItBuildsDatasetValueObjectsFromConvertedConfig(): void
    {
        $registry = $this->createRegistry([
            'datasets' => ['sales_orders' => $this->salesOrdersDatasetRaw()],
            'presets' => [],
        ]);

        $dataset = $registry->getDataset('sales_orders');

        self::assertSame('sales_orders', $dataset->code);
        self::assertSame('Orders', $dataset->label);
        self::assertSame('MageOS_AdvancedReport::sales', $dataset->aclResource);
        self::assertSame('sales', $dataset->area);
        self::assertFalse($dataset->dateless);
        self::assertSame('sales_order', $dataset->baseTable);
        self::assertSame('main', $dataset->baseAlias);
        self::assertNull($dataset->baseProvider);

        $join = $dataset->getJoin('payment');
        self::assertSame('sales_order_payment', $join->table);
        self::assertSame('payment.parent_id = main.entity_id', $join->condition);

        $field = $dataset->getField('grand_total');
        self::assertSame(FieldType::MONEY, $field->type);
        self::assertTrue($field->isMappedForSource('mysql'));
        self::assertSame('main.base_grand_total', $field->sourceMappings['mysql']->expression);

        $metric = $dataset->getMetric('orders');
        self::assertSame(AggregateFunction::COUNT, $metric->aggregate);
        self::assertTrue($metric->isAggregate());

        $derived = $dataset->getMetric('aov');
        self::assertTrue($derived->isDerived());
        self::assertSame('net_sales / orders', $derived->formula);

        $dimension = $dataset->getDimension('order_date');
        self::assertSame([Granularity::DAY, Granularity::WEEK], $dimension->getGranularities());

        self::assertCount(1, $registry->getDatasets());
    }

    public function testItBuildsPresetValueObjectsFilteredByArea(): void
    {
        $registry = $this->createRegistry([
            'datasets' => [],
            'presets' => [
                'sales_overview' => $this->salesOverviewPresetRaw(),
                'stock_overview' => $this->stockOverviewPresetRaw(),
            ],
        ]);

        $allPresets = $registry->getPresets();
        self::assertCount(2, $allPresets);

        $salesPresets = $registry->getPresets('sales');
        self::assertCount(1, $salesPresets);
        $salesPreset = $registry->getPreset('sales_overview');
        self::assertSame('sales', $salesPreset->area);
        self::assertSame('sales_orders', $salesPreset->dataset);
        self::assertSame(['orders'], $salesPreset->metrics);
        self::assertSame('previous_period', $salesPreset->compareTo);
        self::assertSame(Granularity::DAY, $salesPreset->dimensions[0]['granularity']);
        self::assertSame('desc', $salesPreset->sort[0]['direction']);

        $catalogPresets = $registry->getPresets('catalog');
        self::assertCount(1, $catalogPresets);
        $catalogPreset = $registry->getPreset('stock_overview');
        self::assertSame('catalog', $catalogPreset->area);
        self::assertSame('none', $catalogPreset->compareTo);
    }

    public function testItCreatesPresetWithDescriptionFromConvertedData(): void
    {
        $raw = $this->salesOverviewPresetRaw();
        $raw['description'] = 'Revenue and orders over time';
        $registry = $this->createRegistry(['datasets' => [], 'presets' => ['sales_overview' => $raw]]);

        self::assertSame('Revenue and orders over time', $registry->getPreset('sales_overview')->description);
    }

    public function testItCreatesPresetWithEmptyDescriptionWhenDataHasNone(): void
    {
        $registry = $this->createRegistry([
            'datasets' => [],
            'presets' => ['sales_overview' => $this->salesOverviewPresetRaw()],
        ]);

        self::assertSame('', $registry->getPreset('sales_overview')->description);
    }

    public function testItThrowsNoSuchDefinitionExceptionForUnknownDatasetOrPreset(): void
    {
        $registry = $this->createRegistry([
            'datasets' => ['sales_orders' => $this->salesOrdersDatasetRaw()],
            'presets' => ['sales_overview' => $this->salesOverviewPresetRaw()],
        ]);

        $caughtForDataset = false;
        try {
            $registry->getDataset('unknown');
        } catch (NoSuchDefinitionException) {
            $caughtForDataset = true;
        }
        self::assertTrue($caughtForDataset, 'Expected NoSuchDefinitionException for an unknown dataset code.');

        $this->expectException(NoSuchDefinitionException::class);
        $registry->getPreset('unknown');
    }

    public function testItBuildsDefinitionsOnlyOncePerInstance(): void
    {
        $configData = $this->createMock(DataInterface::class);
        $configData->expects(self::once())
            ->method('get')
            ->willReturn([
                'datasets' => ['sales_orders' => $this->minimalDatasetRaw()],
                'presets' => ['sales_overview' => $this->minimalPresetRaw()],
            ]);

        $registry = new DefinitionRegistry($configData, new DefinitionFactory(), $this->passthroughValidator());

        $registry->getDataset('sales_orders');
        $registry->getDatasets();
        $registry->getPreset('sales_overview');
        $registry->getPresets();
        $registry->getConfigVersion();
    }

    public function testItReturnsAStableConfigVersionHashThatChangesWhenConfigChanges(): void
    {
        $configA = ['datasets' => ['sales_orders' => $this->minimalDatasetRaw()], 'presets' => []];
        $configB = ['datasets' => ['sales_orders' => $this->minimalDatasetRaw('catalog')], 'presets' => []];

        $registryA1 = $this->createRegistry($configA);
        $registryA2 = $this->createRegistry($configA);
        $registryB = $this->createRegistry($configB);

        self::assertSame($registryA1->getConfigVersion(), $registryA2->getConfigVersion());
        self::assertNotSame($registryA1->getConfigVersion(), $registryB->getConfigVersion());
        self::assertSame($registryA1->getConfigVersion(), $registryA1->getConfigVersion());
    }

    public function testItAppliesDatasetExtendersBeforeValidation(): void
    {
        $converted = ['datasets' => ['sales_orders' => $this->salesOrdersDatasetRaw()], 'presets' => []];
        $extender = $this->createMock(DatasetExtenderInterface::class);
        $extender->expects(self::once())->method('extend')->willReturnCallback(
            static fn (Dataset $dataset): Dataset => new Dataset(
                'extended',
                $dataset->label,
                $dataset->aclResource,
                $dataset->area,
                $dataset->dateless,
                $dataset->baseTable,
                $dataset->baseAlias,
                $dataset->baseProvider,
                $dataset->implicitFilters,
                $dataset->fields,
                $dataset->joins,
                $dataset->metrics,
                $dataset->dimensions,
            )
        );

        $registry = $this->createRegistry($converted, [$extender]);

        self::assertSame('extended', $registry->getDataset('sales_orders')->code);
    }

    public function testItDropsAnUnavailableDatasetAndItsPresetsBeforeValidation(): void
    {
        $converted = [
            'datasets' => [
                'sales_orders' => $this->salesOrdersDatasetRaw(),
                'catalog_stock' => ['code' => 'catalog_stock'] + $this->minimalDatasetRaw('catalog'),
            ],
            'presets' => [
                'sales_overview' => $this->salesOverviewPresetRaw(),
                'stock_overview' => $this->stockOverviewPresetRaw(),
            ],
        ];
        $availability = $this->createStub(DatasetAvailabilityInterface::class);
        $availability->method('isAvailable')->willReturnCallback(
            static fn (Dataset $dataset): bool => $dataset->code !== 'catalog_stock'
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');
        $configData = $this->createStub(DataInterface::class);
        $configData->method('get')->willReturn($converted);
        $validator = $this->createMock(DefinitionValidator::class);
        $validator->expects(self::once())->method('validate')->willReturnCallback(
            function (array $datasets, array $presets): array {
                self::assertSame(['sales_orders'], array_keys($datasets));
                self::assertSame(['sales_overview'], array_keys($presets));

                return ['datasets' => $datasets, 'presets' => $presets];
            }
        );

        $registry = new DefinitionRegistry(
            $configData,
            new DefinitionFactory(),
            $validator,
            [],
            [],
            [$availability],
            $logger
        );

        self::assertSame(['sales_orders'], array_keys($registry->getDatasets()));
        self::assertSame(['sales_overview'], array_keys($registry->getPresets()));
    }

    /**
     * @param array<string,array<string,mixed>> $converted
     * @param DatasetExtenderInterface[] $extenders
     */
    private function createRegistry(array $converted, array $extenders = []): DefinitionRegistry
    {
        $configData = $this->createStub(DataInterface::class);
        $configData->method('get')->willReturn($converted);

        return new DefinitionRegistry($configData, new DefinitionFactory(), $this->passthroughValidator(), $extenders);
    }

    /**
     * A validator stub that leaves datasets/presets untouched: this test suite exercises
     * the registry's build pipeline mechanics, not validation semantics, which have their
     * own dedicated {@see \MageOS\AdvancedReport\Test\Unit\Model\Definition\DefinitionValidatorTest}.
     */
    private function passthroughValidator(): DefinitionValidator
    {
        $validator = $this->createStub(DefinitionValidator::class);
        $validator->method('validate')->willReturnCallback(
            static fn (array $datasets, array $presets): array => ['datasets' => $datasets, 'presets' => $presets]
        );

        return $validator;
    }

    /**
     * @return array<string,mixed>
     */
    private function salesOrdersDatasetRaw(): array
    {
        return [
            'code' => 'sales_orders',
            'label' => 'Orders',
            'aclResource' => 'MageOS_AdvancedReport::sales',
            'area' => 'sales',
            'dateless' => false,
            'sources' => [
                'mysql' => ['name' => 'mysql', 'table' => 'sales_order', 'alias' => 'main', 'provider' => null],
            ],
            'implicitFilters' => ['counted_status' => 'counted_status'],
            'joins' => [
                'payment' => [
                    'code' => 'payment',
                    'table' => 'sales_order_payment',
                    'alias' => 'payment',
                    'type' => 'left',
                    'condition' => 'payment.parent_id = main.entity_id',
                    'requires' => [],
                ],
            ],
            'fields' => [
                'grand_total' => [
                    'code' => 'grand_total',
                    'label' => 'Grand Total',
                    'type' => 'money',
                    'description' => null,
                    'sourceMappings' => [
                        'mysql' => [
                            'expression' => 'main.base_grand_total',
                            'expressionProvider' => null,
                            'joins' => [],
                        ],
                    ],
                ],
            ],
            'metrics' => [
                'orders' => [
                    'code' => 'orders',
                    'label' => 'Orders',
                    'description' => 'Number of orders',
                    'format' => 'integer',
                    'hidden' => false,
                    'lowerIsBetter' => false,
                    'aggregate' => 'count',
                    'fieldCode' => 'grand_total',
                    'conditionFieldCode' => null,
                    'formula' => null,
                    'virtual' => false,
                ],
                'aov' => [
                    'code' => 'aov',
                    'label' => 'AOV',
                    'description' => null,
                    'format' => 'money',
                    'hidden' => false,
                    'lowerIsBetter' => false,
                    'aggregate' => null,
                    'fieldCode' => null,
                    'conditionFieldCode' => null,
                    'formula' => 'net_sales / orders',
                    'virtual' => false,
                ],
            ],
            'dimensions' => [
                'order_date' => [
                    'code' => 'order_date',
                    'label' => 'Order date',
                    'fieldCode' => 'created_at',
                    'granularities' => ['day', 'week'],
                    'labelResolver' => null,
                    'nonAdditive' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function salesOverviewPresetRaw(): array
    {
        return [
            'code' => 'sales_overview',
            'area' => 'sales',
            'dataset' => 'sales_orders',
            'label' => 'Sales overview',
            'sources' => ['mysql'],
            'presetQuery' => null,
            'compareTo' => 'previous_period',
            'dateRange' => 'last_30_days',
            'alternateDimension' => null,
            'metrics' => ['orders'],
            'dimensions' => [
                ['code' => 'order_date', 'granularity' => 'day'],
            ],
            'filters' => [
                ['field' => 'store_id', 'operator' => 'in', 'value' => ['1', '2']],
            ],
            'sort' => [
                ['metric' => 'orders', 'direction' => 'desc'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function stockOverviewPresetRaw(): array
    {
        return [
            'code' => 'stock_overview',
            'area' => 'catalog',
            'dataset' => 'catalog_stock',
            'label' => 'Stock overview',
            'sources' => ['mysql'],
            'presetQuery' => null,
            'compareTo' => null,
            'dateRange' => null,
            'alternateDimension' => null,
            'metrics' => [],
            'dimensions' => [],
            'filters' => [],
            'sort' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function minimalDatasetRaw(string $area = 'sales'): array
    {
        return [
            'code' => 'sales_orders',
            'label' => 'Orders',
            'aclResource' => 'MageOS_AdvancedReport::sales',
            'area' => $area,
            'dateless' => false,
            'sources' => [
                'mysql' => ['name' => 'mysql', 'table' => 'sales_order', 'alias' => 'main', 'provider' => null],
            ],
            'implicitFilters' => [],
            'joins' => [],
            'fields' => [],
            'metrics' => [],
            'dimensions' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function minimalPresetRaw(): array
    {
        return [
            'code' => 'sales_overview',
            'area' => 'sales',
            'dataset' => 'sales_orders',
            'label' => 'Sales overview',
            'sources' => ['mysql'],
            'presetQuery' => null,
            'compareTo' => null,
            'dateRange' => null,
            'alternateDimension' => null,
            'metrics' => [],
            'dimensions' => [],
            'filters' => [],
            'sort' => [],
        ];
    }
}
