<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\SavedReport;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\AdvancedReport\Api\Data\SavedReportInterface;
use MageOS\AdvancedReport\Api\Data\SavedReportSearchResultsInterfaceFactory;
use MageOS\AdvancedReport\Model\ResourceModel\SavedReport as SavedReportResource;
use MageOS\AdvancedReport\Model\ResourceModel\SavedReport\Collection;
use MageOS\AdvancedReport\Model\ResourceModel\SavedReport\CollectionFactory;
use MageOS\AdvancedReport\Model\SavedReport\SavedReport;
use MageOS\AdvancedReport\Model\SavedReport\SavedReportFactory;
use MageOS\AdvancedReport\Model\SavedReport\SavedReportRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class SavedReportRepositoryTest extends TestCase
{
    private SavedReportResource&MockObject $resource;
    private SavedReportFactory&MockObject $factory;
    private CollectionFactory&MockObject $collectionFactory;
    private SavedReportRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(SavedReportResource::class);
        $this->factory = $this->createMock(SavedReportFactory::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->repository = new SavedReportRepository(
            $this->resource,
            $this->factory,
            $this->collectionFactory,
            $this->createStub(SavedReportSearchResultsInterfaceFactory::class),
            $this->createStub(CollectionProcessorInterface::class),
        );
    }

    public function testItSavesAReportAndReturnsItWithAnId(): void
    {
        $report = $this->createStub(SavedReport::class);
        $this->resource->expects(self::once())->method('save')->with($report)
            ->willReturnCallback(function () use ($report): SavedReportResource {
                $report->method('getId')->willReturn(5);

                return $this->resource;
            });

        $saved = $this->repository->save($report);

        self::assertSame($report, $saved);
        self::assertSame(5, $saved->getId());
    }

    public function testItThrowsNoSuchEntityExceptionForAnUnknownId(): void
    {
        $this->factory->method('create')->willReturn($this->createStub(SavedReport::class));

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById(99);
    }

    public function testItReturnsOwnAndSharedReportsForAUser(): void
    {
        $own = $this->createStub(SavedReportInterface::class);
        $shared = $this->createStub(SavedReportInterface::class);
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('addFieldToFilter')
            ->with(['admin_user_id', 'is_shared'], [['eq' => 7], ['eq' => 1]]);
        $collection->method('getItems')->willReturn([3 => $own, 4 => $shared]);
        $this->collectionFactory->method('create')->willReturn($collection);

        self::assertSame([$own, $shared], $this->repository->getVisibleForUser(7));
    }

    public function testItWrapsPersistenceErrorsInCouldNotSaveExceptionAndCouldNotDeleteException(): void
    {
        $report = $this->createStub(SavedReport::class);
        $this->resource->method('save')->willThrowException(new RuntimeException('boom'));
        $this->resource->method('delete')->willThrowException(new RuntimeException('boom'));

        try {
            $this->repository->save($report);
            self::fail('Expected CouldNotSaveException');
        } catch (CouldNotSaveException) {
            $this->expectException(CouldNotDeleteException::class);
            $this->repository->delete($report);
        }
    }
}
