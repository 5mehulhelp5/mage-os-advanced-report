<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\ViewModel;

use Magento\Backend\Model\Auth\Session;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\User\Model\User;
use MageOS\AdvancedReport\Api\Data\SavedReportInterface;
use MageOS\AdvancedReport\Api\SavedReportRepositoryInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\ViewModel\ReportList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportList::class)]
final class ReportListTest extends TestCase
{
    public function testItGroupsPresetsByArea(): void
    {
        $areas = $this->build(
            [$this->preset('a', 'sales', 'sales_orders'), $this->preset('b', 'catalog', 'catalog_stock')],
            [],
            ['MageOS_AdvancedReport::sales', 'MageOS_AdvancedReport::catalog'],
        )->getAreas();

        self::assertSame(['catalog', 'sales'], array_keys($areas));
        self::assertSame('Sales', $areas['sales']['label']);
        self::assertSame(['Preset a'], array_column($areas['sales']['presets'], 'label'));
        self::assertSame(['Preset b'], array_column($areas['catalog']['presets'], 'label'));
    }

    public function testItHidesPresetsWhoseDatasetTheAdminCannotAccess(): void
    {
        $areas = $this->build(
            [
                $this->preset('orders', 'sales', 'sales_orders'),
                $this->preset('perf', 'catalog', 'sales_items'),
                $this->preset('stock', 'catalog', 'catalog_stock'),
            ],
            [],
            ['MageOS_AdvancedReport::catalog'],
        )->getAreas();

        self::assertSame(['catalog'], array_keys($areas));
        self::assertSame(['Preset stock'], array_column($areas['catalog']['presets'], 'label'));
    }

    public function testItListsOwnAndSharedSavedReportsFilteredByDatasetPermission(): void
    {
        $areas = $this->build(
            [],
            [
                $this->saved(1, 'Mine', 'sales_orders', false),
                $this->saved(2, 'Shared', 'sales_orders', true),
                $this->saved(3, 'Stock', 'catalog_stock', true),
                $this->saved(4, 'Gone', 'unknown', true),
            ],
            ['MageOS_AdvancedReport::sales'],
        )->getAreas();

        self::assertSame(['sales'], array_keys($areas));
        self::assertSame(['Mine', 'Shared'], array_column($areas['sales']['saved'], 'label'));
        self::assertSame([false, true], array_column($areas['sales']['saved'], 'shared'));
    }

    public function testItBuildsViewUrlsForPresetsAndSavedReports(): void
    {
        $areas = $this->build(
            [$this->preset('a', 'sales', 'sales_orders')],
            [$this->saved(7, 'Mine', 'sales_orders', false)],
            ['MageOS_AdvancedReport::sales'],
        )->getAreas();

        self::assertSame('advancedreport/report/view?preset=a', $areas['sales']['presets'][0]['url']);
        self::assertSame('advancedreport/report/view?id=7', $areas['sales']['saved'][0]['url']);
    }

    public function testItIncludesTranslatedDescriptionForSalesArea(): void
    {
        $areas = $this->build([$this->preset('a', 'sales', 'sales_orders')], [], ['MageOS_AdvancedReport::sales'])
            ->getAreas();

        self::assertSame(
            'Ready-made sales reports: revenue, orders and customer behaviour across channels, payments and shipping.',
            $areas['sales']['description']
        );
    }

    public function testItIncludesTranslatedDescriptionForCatalogArea(): void
    {
        $areas = $this->build([$this->preset('a', 'catalog', 'catalog_stock')], [], ['MageOS_AdvancedReport::catalog'])
            ->getAreas();

        self::assertSame(
            'Ready-made catalog reports: product sales, categories and stock health of your catalog.',
            $areas['catalog']['description']
        );
    }

    public function testItReturnsEmptyDescriptionForUnknownArea(): void
    {
        $areas = $this->build([$this->preset('a', 'other', 'sales_orders')], [], ['MageOS_AdvancedReport::sales'])
            ->getAreas();

        self::assertSame('', $areas['other']['description']);
    }

    public function testItIncludesPresetDescriptionInPresetItems(): void
    {
        $areas = $this->build(
            [$this->preset('a', 'sales', 'sales_orders', 'Some text')],
            [],
            ['MageOS_AdvancedReport::sales'],
        )->getAreas();

        self::assertSame('Some text', $areas['sales']['presets'][0]['description']);
    }

    public function testItReturnsEmptyPresetDescriptionWhenPresetHasNone(): void
    {
        $areas = $this->build([$this->preset('a', 'sales', 'sales_orders')], [], ['MageOS_AdvancedReport::sales'])
            ->getAreas();

        self::assertSame('', $areas['sales']['presets'][0]['description']);
    }

    public function testItLabelsTheCustomersAreaWithItsTitleAndDescription(): void
    {
        $areas = $this->build(
            [$this->preset('c', 'customers', 'customer_orders')],
            [],
            ['MageOS_AdvancedReport::customers'],
        )->getAreas();

        self::assertSame('Customers', (string)$areas['customers']['label']);
        self::assertStringStartsWith('Ready-made customer reports', (string)$areas['customers']['description']);
    }

    public function testItHidesTheCustomersAreaWhenTheAdminLacksTheCustomersAcl(): void
    {
        $areas = $this->build(
            [$this->preset('c', 'customers', 'customer_orders')],
            [],
            ['MageOS_AdvancedReport::sales'],
        )->getAreas();

        self::assertArrayNotHasKey('customers', $areas);
    }

    /**
     * @param Preset[] $presets
     * @param SavedReportInterface[] $saved
     * @param string[] $allowed
     */
    private function build(array $presets, array $saved, array $allowed): ReportList
    {
        $datasets = [
            'sales_orders' => 'MageOS_AdvancedReport::sales',
            'sales_items' => 'MageOS_AdvancedReport::sales',
            'catalog_stock' => 'MageOS_AdvancedReport::catalog',
            'customer_orders' => 'MageOS_AdvancedReport::customers',
        ];
        $areaOf = ['sales_orders' => 'sales', 'sales_items' => 'catalog', 'catalog_stock' => 'catalog',
            'customer_orders' => 'customers'];
        $registry = $this->createStub(DefinitionRegistry::class);
        $registry->method('getPresets')->willReturn($presets);
        $registry->method('getDataset')->willReturnCallback(
            fn (string $code): Dataset => isset($datasets[$code])
                ? new Dataset($code, $code, $datasets[$code], $areaOf[$code], false, null, null, null, [], [], [], [], [])
                : throw new NoSuchDefinitionException(__('No such dataset'))
        );
        $repository = $this->createStub(SavedReportRepositoryInterface::class);
        $repository->method('getVisibleForUser')->willReturn($saved);
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => \in_array($resource, $allowed, true)
        );
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn('5');
        $session = (new class() extends Session {
            public ?object $user = null;

            public function __construct()
            {
            }

            /**
             * @param array<mixed> $args
             */
            public function __call($method, $args)
            {
                return $this->user;
            }
        });
        $session->user = $user;
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $path, array $params): string => $path . '?' . http_build_query($params)
        );

        return new ReportList($registry, $repository, $authorization, $session, $url);
    }

    private function preset(string $code, string $area, string $dataset, string $description = ''): Preset
    {
        return new Preset(
            $code,
            $area,
            'Preset ' . $code,
            $dataset,
            [],
            [],
            [],
            [],
            'none',
            null,
            ['mysql'],
            description: $description,
        );
    }

    private function saved(int $id, string $name, string $dataset, bool $shared): SavedReportInterface
    {
        $saved = $this->createStub(SavedReportInterface::class);
        $saved->method('getId')->willReturn($id);
        $saved->method('getName')->willReturn($name);
        $saved->method('getDatasetCode')->willReturn($dataset);
        $saved->method('isShared')->willReturn($shared);

        return $saved;
    }
}
