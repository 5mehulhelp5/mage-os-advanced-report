<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\ViewModel;

use Magento\Backend\Model\Auth\Session;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Locale\ResolverInterface as LocaleResolverInterface;
use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Exploration\InitialStateResolver;
use MageOS\AdvancedReport\ViewModel\Exploration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Exploration::class)]
final class ExplorationTest extends TestCase
{
    public function testItExposesOnlyDatasetsTheAdminCanAccess(): void
    {
        $config = $this->config(['MageOS_AdvancedReport::sales']);

        self::assertSame(['sales_orders'], array_column($config['datasets'], 'code'));
        $dataset = $config['datasets'][0];
        self::assertSame(['net_sales'], array_column($dataset['metrics'], 'code'));
        self::assertSame(['day'], $dataset['dimensions'][0]['granularities']);
        self::assertSame(['eq', 'neq', 'in', 'nin'], $dataset['filters'][0]['operators']);
    }

    public function testItExposesPermissionsAndEndpointUrls(): void
    {
        $config = $this->config(['MageOS_AdvancedReport::sales', 'MageOS_AdvancedReport::export']);

        self::assertSame(
            ['canSave' => false, 'canShare' => false, 'canExport' => true, 'canOverrideSource' => false],
            $config['permissions']
        );
        self::assertSame('advancedreport/report/export', $config['urls']['export']);
        self::assertSame(['a' => 1], $config['request']);
        self::assertSame(1, $config['firstDayOfWeek']);
        self::assertSame('it_IT', $config['locale']);
    }

    public function testItExposesTheConfigUrlWhenTheAdminMayEditTheConfiguration(): void
    {
        $urls = $this->config(['MageOS_AdvancedReport::config'])['urls'];

        self::assertSame('adminhtml/system_config/edit?section=mageos_advancedreport', $urls['config']);
    }

    public function testItSetsTheConfigUrlToNullWhenTheAdminMayNotEditTheConfiguration(): void
    {
        self::assertNull($this->config([])['urls']['config']);
    }

    public function testItAddsTheIsAjaxQueryParameterToTheDataSaveAndDeleteUrls(): void
    {
        $urls = $this->config([])['urls'];

        self::assertSame('advancedreport/report/data?isAjax=true', $urls['data']);
        self::assertSame('advancedreport/report/save?isAjax=true', $urls['save']);
        self::assertSame('advancedreport/report/delete?isAjax=true', $urls['delete']);
        self::assertStringNotContainsString('isAjax', $urls['export']);
    }

    public function testItExposesTheAlternateDimensionOfThePresetInTheRequest(): void
    {
        $preset = new Preset(
            'perf',
            'catalog',
            'Perf',
            'sales_orders',
            ['net_sales'],
            [['code' => 'parent_sku', 'granularity' => null]],
            [],
            [],
            'none',
            null,
            ['mysql'],
            null,
            'sku'
        );

        self::assertSame(
            ['primary' => 'parent_sku', 'alternate' => 'sku'],
            $this->config([], ['preset' => 'perf'], $preset)['alternateDimension']
        );
        self::assertNull($this->config([])['alternateDimension']);
    }

    public function testItReturnsTheDescriptionOfThePresetThePageOpensWith(): void
    {
        $viewModel = $this->viewModel([], ['preset' => 'perf'], $this->preset('Slow products'));

        self::assertSame('Slow products', $viewModel->getPresetDescription());
    }

    public function testItReturnsThePresetDescriptionForASavedReportBuiltFromAPreset(): void
    {
        $viewModel = $this->viewModel(
            [],
            ['preset' => 'perf', 'metrics' => ['net_sales']],
            $this->preset('Slow products'),
            ['id' => 5, 'name' => 'Mine', 'is_shared' => false, 'is_owner' => true]
        );

        self::assertSame('Slow products', $viewModel->getPresetDescription());
    }

    public function testItReturnsAnEmptyDescriptionWhenNoPresetIsSelected(): void
    {
        self::assertSame('', $this->viewModel([])->getPresetDescription());
    }

    public function testItReturnsAnEmptyDescriptionWhenThePresetIsUnknown(): void
    {
        $viewModel = $this->viewModel([], ['preset' => 'gone'], null, null, true);

        self::assertSame('', $viewModel->getPresetDescription());
    }

    public function testItReturnsAnEmptyDescriptionWhenThePresetHasNoDescription(): void
    {
        $viewModel = $this->viewModel([], ['preset' => 'perf'], $this->preset(''));

        self::assertSame('', $viewModel->getPresetDescription());
    }

    public function testItResolvesTheInitialStateOnlyOnceForConfigAndDescription(): void
    {
        $resolver = $this->createMock(InitialStateResolver::class);
        $resolver->expects(self::once())->method('resolve')
            ->willReturn(['request' => ['preset' => 'perf'], 'saved' => null]);
        $viewModel = $this->viewModel([], [], $this->preset('Slow products'), null, false, $resolver);

        self::assertSame('Slow products', $viewModel->getPresetDescription());
        self::assertSame(['preset' => 'perf'], json_decode($viewModel->getJsConfig(), true)['request']);
    }

    public function testItReturnsAnEmptyDescriptionWhenTheInitialStateCannotBeResolved(): void
    {
        $resolver = $this->createStub(InitialStateResolver::class);
        $resolver->method('resolve')->willThrowException(new NoSuchEntityException(new Phrase('Gone')));
        $viewModel = $this->viewModel([], [], null, null, false, $resolver);

        self::assertSame('', $viewModel->getPresetDescription());
    }

    private function preset(string $description): Preset
    {
        return new Preset(
            'perf',
            'catalog',
            'Perf',
            'sales_orders',
            ['net_sales'],
            [['code' => 'parent_sku', 'granularity' => null]],
            [],
            [],
            'none',
            null,
            ['mysql'],
            description: $description
        );
    }

    /**
     * @param string[] $allowed
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function config(array $allowed, array $request = ['a' => 1], ?Preset $preset = null): array
    {
        return json_decode(
            $this->viewModel($allowed, $request, $preset)->getJsConfig(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param string[] $allowed
     * @param array<string,mixed> $request
     * @param array<string,mixed>|null $saved
     */
    private function viewModel(
        array $allowed,
        array $request = ['a' => 1],
        ?Preset $preset = null,
        ?array $saved = null,
        bool $unknownPreset = false,
        ?InitialStateResolver $resolver = null,
    ): Exploration {
        $orders = new Dataset(
            'sales_orders',
            'Orders',
            'MageOS_AdvancedReport::sales',
            'sales',
            false,
            null,
            null,
            null,
            [],
            ['status' => new Field('status', 'Status', FieldType::STRING)],
            [],
            [
                'net_sales' => new Metric('net_sales', 'Net', null, 'money', false, false, AggregateFunction::SUM),
                'hid' => new Metric('hid', 'Hid', null, 'money', true, false, AggregateFunction::SUM),
                'virt' => new Metric('virt', 'Virt', null, 'number', false, false, null, null, null, null, true),
            ],
            ['created' => new Dimension('created', 'Created', 'created_at', [Granularity::DAY])],
        );
        $stock = new Dataset(
            'catalog_stock',
            'Stock',
            'MageOS_AdvancedReport::catalog',
            'catalog',
            true,
            null,
            null,
            null,
            [],
            [],
            [],
            [],
            [],
        );
        $registry = $this->createStub(DefinitionRegistry::class);
        $registry->method('getDatasets')->willReturn(['sales_orders' => $orders, 'catalog_stock' => $stock]);
        if ($preset !== null) {
            $registry->method('getPreset')->willReturn($preset);
        }
        if ($unknownPreset) {
            $registry->method('getPreset')->willThrowException(new NoSuchDefinitionException(new Phrase('No')));
        }
        if ($resolver === null) {
            $resolver = $this->createStub(InitialStateResolver::class);
            $resolver->method('resolve')->willReturn(['request' => $request, 'saved' => $saved]);
        }
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => \in_array($resource, $allowed, true)
        );
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $path, ?array $params = null): string => $path
                . (isset($params['_query']) ? '?' . http_build_query($params['_query']) : '')
                . (isset($params['section']) ? '?section=' . $params['section'] : '')
        );
        $appConfig = $this->createStub(Config::class);
        $appConfig->method('getFirstDayOfWeek')->willReturn(1);
        $localeResolver = $this->createStub(LocaleResolverInterface::class);
        $localeResolver->method('getLocale')->willReturn('it_IT');

        return new Exploration(
            $registry,
            $resolver,
            $authorization,
            $this->createStub(Session::class),
            $url,
            $this->createStub(RequestInterface::class),
            $appConfig,
            $localeResolver,
        );
    }
}
