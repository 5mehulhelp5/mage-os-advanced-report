<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Sales;

use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ImplicitFilterPool;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\ResourceModel\OrderStatusLabels;
use MageOS\AdvancedReport\Model\Sales\CountedStatusesDescriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CountedStatusesDescriber::class)]
#[AllowMockObjectsWithoutExpectations]
final class CountedStatusesDescriberTest extends TestCase
{
    public function testItReturnsNullForDatasetsWithoutTheCountedStatusFilter(): void
    {
        $describer = $this->describer([], []);

        self::assertNull($describer->describe($this->dataset(false), []));
    }

    public function testItDoesNotLoadLabelsForDatasetsWithoutTheCountedStatusFilter(): void
    {
        $labels = $this->createMock(OrderStatusLabels::class);
        $labels->expects(self::never())->method('getLabels');
        $describer = new CountedStatusesDescriber($this->config([]), $labels);

        $describer->describe($this->dataset(false), []);
    }

    public function testItDescribesAllStatusesExceptTheCanceledLabelWhenNoStatusesAreConfigured(): void
    {
        $describer = $this->describer([], ['canceled' => 'Annullato']);

        self::assertSame(
            'Counted orders: all statuses except Annullato',
            $describer->describe($this->dataset(true), [])
        );
    }

    public function testItListsTheLabelsOfTheConfiguredStatusesInConfiguredOrder(): void
    {
        $describer = $this->describer(
            ['processing', 'complete'],
            ['complete' => 'Complete', 'processing' => 'Processing', 'canceled' => 'Canceled']
        );

        self::assertSame('Counted orders: Processing, Complete', $describer->describe($this->dataset(true), []));
    }

    public function testItUsesTheStoreLabelsWhenExactlyOneStoreIsInScope(): void
    {
        $labels = $this->createMock(OrderStatusLabels::class);
        $labels->expects(self::once())->method('getLabels')->with(3)->willReturn(['complete' => 'Fatto']);
        $describer = new CountedStatusesDescriber($this->config(['complete']), $labels);

        self::assertSame('Counted orders: Fatto', $describer->describe($this->dataset(true), [3]));
    }

    public function testItUsesTheAdminLabelsWhenAllOrSeveralStoresAreInScope(): void
    {
        $labels = $this->createMock(OrderStatusLabels::class);
        $labels->expects(self::exactly(2))->method('getLabels')->with(null)->willReturn([]);
        $describer = new CountedStatusesDescriber($this->config(['complete']), $labels);

        $describer->describe($this->dataset(true), []);
        $describer->describe($this->dataset(true), [1, 2]);
    }

    public function testItFallsBackToTheStatusCodeWhenAStatusHasNoLabel(): void
    {
        $describer = $this->describer(['complete', 'custom', 'empty'], ['complete' => 'Complete', 'empty' => '']);

        self::assertSame(
            'Counted orders: Complete, custom, empty',
            $describer->describe($this->dataset(true), [])
        );
    }

    /**
     * @param string[] $statuses
     * @param array<string,string> $labelMap
     */
    private function describer(array $statuses, array $labelMap): CountedStatusesDescriber
    {
        $labels = $this->createStub(OrderStatusLabels::class);
        $labels->method('getLabels')->willReturn($labelMap);

        return new CountedStatusesDescriber($this->config($statuses), $labels);
    }

    /**
     * @param string[] $statuses
     */
    private function config(array $statuses): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getCountedStatuses')->willReturn($statuses);

        return $config;
    }

    private function dataset(bool $counted): Dataset
    {
        $filters = $counted ? [ImplicitFilterPool::COUNTED_STATUS => true] : [];

        return new Dataset('sales_orders', 'Orders', 'acl', 'sales', false, null, null, 'provider', $filters, [], [], [], []);
    }
}
