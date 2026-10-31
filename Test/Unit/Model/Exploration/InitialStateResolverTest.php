<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Exploration;

use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\Data\SavedReportInterface;
use MageOS\AdvancedReport\Api\SavedReportRepositoryInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Exploration\InitialStateResolver;
use MageOS\AdvancedReport\Model\Query\PresetRequestBuilder;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Security\DatasetAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InitialStateResolver::class)]
final class InitialStateResolverTest extends TestCase
{
    public function testItBuildsTheInitialRequestFromAPreset(): void
    {
        $result = $this->resolver()->resolve('overview', null, 5);

        self::assertSame(['from' => 'preset'], $result['request']);
        self::assertNull($result['saved']);
    }

    public function testItBuildsTheInitialRequestFromAVisibleSavedReport(): void
    {
        $result = $this->resolver(saved: $this->saved(9, true))->resolve(null, 3, 5);

        self::assertSame(['dataset' => 'sales_orders'], $result['request']);
        self::assertSame(
            ['id' => 3, 'name' => 'Mine', 'is_shared' => true, 'is_owner' => false],
            $result['saved']
        );
    }

    public function testItRejectsASavedReportThatIsNotVisible(): void
    {
        $this->expectException(NoSuchEntityException::class);

        $this->resolver(saved: $this->saved(9, false))->resolve(null, 3, 5);
    }

    public function testItRejectsAnUnknownPreset(): void
    {
        $this->expectException(NoSuchEntityException::class);

        $this->resolver()->resolve('missing', null, 5);
    }

    public function testItOpensAnEmptyExplorationOnTheFirstAllowedDataset(): void
    {
        $result = $this->resolver()->resolve(null, null, 5);

        self::assertSame('sales_orders', $result['request']['dataset']);
        self::assertSame([], $result['request']['metrics']);
    }

    public function testItThrowsNotFoundWhenOpeningASavedReportOfAnInaccessibleDataset(): void
    {
        $this->expectException(NoSuchEntityException::class);

        $this->resolver(saved: $this->saved(5, true), firstDatasetDenied: true)->resolve(null, 3, 5);
    }

    public function testItThrowsNotFoundWhenOpeningAPresetOfAnInaccessibleDataset(): void
    {
        $this->expectException(NoSuchEntityException::class);

        $this->resolver(firstDatasetDenied: true)->resolve('overview', null, 5);
    }

    public function testItThrowsNotFoundWhenOpeningAPresetWhoseExtraDatasetIsDenied(): void
    {
        $this->expectException(NoSuchEntityException::class);

        $this->resolver(presetDenied: true)->resolve('overview', null, 5);
    }

    public function testItStartsAnEmptyExplorationOnTheFirstAccessibleDataset(): void
    {
        $result = $this->resolver(firstDatasetDenied: true)->resolve(null, null, 5);

        self::assertSame('sales_items', $result['request']['dataset']);
    }

    private function saved(int $owner, bool $shared): SavedReportInterface
    {
        $saved = $this->createStub(SavedReportInterface::class);
        $saved->method('getId')->willReturn('3');
        $saved->method('getName')->willReturn('Mine');
        $saved->method('getAdminUserId')->willReturn($owner);
        $saved->method('isShared')->willReturn($shared);
        $saved->method('getDatasetCode')->willReturn('sales_orders');
        $saved->method('getDefinition')->willReturn(['dataset' => 'sales_orders']);

        return $saved;
    }

    private function resolver(
        ?SavedReportInterface $saved = null,
        bool $firstDatasetDenied = false,
        bool $presetDenied = false,
    ): InitialStateResolver {
        $dataset = new Dataset(
            'sales_orders',
            'Orders',
            'MageOS_AdvancedReport::sales',
            'sales',
            false,
            null,
            null,
            null,
            [],
            [],
            [],
            [],
            [],
        );
        $second = new Dataset(
            'sales_items',
            'Items',
            'MageOS_AdvancedReport::sales',
            'sales',
            false,
            null,
            null,
            null,
            [],
            [],
            [],
            [],
            [],
        );
        $preset = new Preset('overview', 'sales', 'Overview', 'sales_orders', ['m'], [], [], [], 'none', null, []);
        $registry = $this->createStub(DefinitionRegistry::class);
        $registry->method('getDataset')->willReturnCallback(
            static fn (string $code): Dataset => $code === 'sales_items' ? $second : $dataset
        );
        $registry->method('getDatasets')->willReturn(['sales_orders' => $dataset, 'sales_items' => $second]);
        $registry->method('getPreset')->willReturnCallback(
            static fn (string $code): Preset => $code === 'overview'
                ? $preset
                : throw new NoSuchDefinitionException(__('No'))
        );
        $repository = $this->createStub(SavedReportRepositoryInterface::class);
        if ($saved !== null) {
            $repository->method('getById')->willReturn($saved);
        }
        $builder = $this->createStub(PresetRequestBuilder::class);
        $builder->method('build')->willReturn($this->createStub(ReportRequestInterface::class));
        $factory = $this->createStub(ReportRequestFactory::class);
        $factory->method('toArray')->willReturn(['from' => 'preset']);
        $datasetAccess = $this->createStub(DatasetAccess::class);
        $datasetAccess->method('isAllowed')->willReturnCallback(
            static fn (Dataset $candidate): bool => !($firstDatasetDenied && $candidate->code === 'sales_orders')
        );
        $datasetAccess->method('isPresetAllowed')->willReturn(!$presetDenied);

        return new InitialStateResolver($registry, $repository, $builder, $factory, $datasetAccess);
    }
}
