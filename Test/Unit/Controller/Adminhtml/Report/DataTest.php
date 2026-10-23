<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Controller\Adminhtml\Report;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\Data\ReportResultInterface;
use MageOS\AdvancedReport\Api\ReportRunnerInterface;
use MageOS\AdvancedReport\Controller\Adminhtml\Report\Data;
use MageOS\AdvancedReport\Model\DataSource\QueryTimeoutException;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\ModuleGuard;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Sales\CountedStatusesDescriber;
use MageOS\AdvancedReport\Model\Security\ErrorLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(Data::class)]
final class DataTest extends TestCase
{
    private ReportRunnerInterface&Stub $runner;
    private ReportRequestFactory&Stub $requestFactory;
    private ModuleGuard&Stub $guard;
    private ErrorLogger $logger;
    private JsonResult&MockObject $jsonResult;
    private RequestInterface&Stub $request;
    private DefinitionRegistry&Stub $registry;
    private Dataset $dataset;
    private CountedStatusesDescriber&Stub $describer;
    private ReportRequestInterface&Stub $reportRequest;

    protected function setUp(): void
    {
        $this->runner = $this->createStub(ReportRunnerInterface::class);
        $this->requestFactory = $this->createStub(ReportRequestFactory::class);
        $this->reportRequest = $this->createStub(ReportRequestInterface::class);
        $this->requestFactory->method('fromArray')->willReturn($this->reportRequest);
        $this->dataset = new Dataset(
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
        $this->registry = $this->createStub(DefinitionRegistry::class);
        $this->registry->method('getDataset')->willReturn($this->dataset);
        $this->describer = $this->createStub(CountedStatusesDescriber::class);
        $this->guard = $this->createStub(ModuleGuard::class);
        $this->logger = $this->createStub(ErrorLogger::class);
        $this->jsonResult = $this->createMock(JsonResult::class);
        $this->request = $this->createStub(RequestInterface::class);
    }

    public function testItReturnsTheReportResultAsJson(): void
    {
        $result = $this->createStub(ReportResultInterface::class);
        $result->method('toArray')->willReturn(['rows' => [1]]);
        $this->runner->method('run')->willReturn($result);
        $this->request->method('getParam')->willReturn('{"dataset":"sales_orders"}');
        $this->jsonResult->expects(self::once())->method('setData')->with(['rows' => [1], 'counted_statuses' => null])->willReturnSelf();
        $this->jsonResult->expects(self::never())->method('setHttpResponseCode');

        self::assertSame($this->jsonResult, $this->controller()->execute());
    }

    public function testItAddsTheCountedStatusesDescriptionToTheReportResponse(): void
    {
        $result = $this->createStub(ReportResultInterface::class);
        $result->method('toArray')->willReturn(['rows' => [1]]);
        $this->runner->method('run')->willReturn($result);
        $this->describer->method('describe')->willReturn('Counted: complete');
        $this->request->method('getParam')->willReturn('{"dataset":"sales_orders"}');
        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['rows' => [1], 'counted_statuses' => 'Counted: complete'])->willReturnSelf();

        $this->controller()->execute();
    }

    public function testItDescribesCountedStatusesForTheRequestedDatasetAndStoreIds(): void
    {
        $result = $this->createStub(ReportResultInterface::class);
        $result->method('toArray')->willReturn([]);
        $this->runner->method('run')->willReturn($result);
        $this->reportRequest->method('getDataset')->willReturn('sales_orders');
        $this->reportRequest->method('getStoreIds')->willReturn([1, 2]);
        $describer = $this->createMock(CountedStatusesDescriber::class);
        $describer->expects(self::once())->method('describe')->with($this->dataset, [1, 2])->willReturn('x');
        $this->describer = $describer;
        $this->request->method('getParam')->willReturn('{"dataset":"sales_orders"}');
        $this->jsonResult->expects(self::once())->method('setData')->willReturnSelf();

        $this->controller()->execute();
    }

    public function testItReturnsNullCountedStatusesWhenTheDatasetHasNoCountedStatusFilter(): void
    {
        $result = $this->createStub(ReportResultInterface::class);
        $result->method('toArray')->willReturn(['rows' => []]);
        $this->runner->method('run')->willReturn($result);
        $this->describer->method('describe')->willReturn(null);
        $this->request->method('getParam')->willReturn('{"dataset":"catalog_stock"}');
        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['rows' => [], 'counted_statuses' => null])->willReturnSelf();

        $this->controller()->execute();
    }

    public function testItReturns400WithTheMessageForInvalidRequests(): void
    {
        $this->runner->method('run')->willThrowException(new InvalidRequestException(new Phrase('Bad metric.')));

        $this->assertError(400, 'Bad metric.');
    }

    public function testItReturns403WhenTheDatasetIsNotAllowed(): void
    {
        $this->runner->method('run')->willThrowException(new AuthorizationException(new Phrase('Not allowed.')));

        $this->assertError(403, 'Not allowed.');
    }

    public function testItReturns504OnQueryTimeout(): void
    {
        $this->runner->method('run')->willThrowException(new QueryTimeoutException(new Phrase('Too slow.')));

        $this->assertError(504, 'Too slow.');
    }

    public function testItReturns500WithoutExposingInternalsAndLogsTheException(): void
    {
        $this->runner->method('run')->willThrowException(new \RuntimeException('SELECT secret FROM x'));
        $this->logger = $this->createMock(ErrorLogger::class);
        $this->logger->expects(self::once())->method('log');

        $this->assertError(500, 'An error occurred while running the report.');
    }

    public function testItReturns400OnAMissingOrMalformedRequestParameter(): void
    {
        $this->request->method('getParam')->willReturn('{not json');
        $this->assertError(400, 'The report request is missing or malformed.', false);
    }

    public function testItReturns400WhenTheRequestParameterIsMissing(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->assertError(400, 'The report request is missing or malformed.', false);
    }

    public function testItReturnsNotFoundWhenTheModuleIsDisabled(): void
    {
        $this->guard->method('assertEnabled')->willThrowException(new NotFoundException(new Phrase('Nope.')));
        $this->jsonResult->expects(self::never())->method('setData');

        $this->expectException(NotFoundException::class);
        $this->controller()->execute();
    }

    private function assertError(int $code, string $message, bool $withParam = true): void
    {
        if ($withParam) {
            $this->request->method('getParam')->willReturn('{"dataset":"sales_orders"}');
        }
        $this->jsonResult->expects(self::once())->method('setHttpResponseCode')->with($code)->willReturnSelf();
        $this->jsonResult->expects(self::once())->method('setData')
            ->with(['error' => true, 'message' => $message])->willReturnSelf();

        self::assertSame($this->jsonResult, $this->controller()->execute());
    }

    private function controller(): Data
    {
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturn($this->jsonResult);

        return new Data(
            $context,
            $factory,
            new Json(),
            $this->requestFactory,
            $this->runner,
            $this->guard,
            $this->logger,
            $this->registry,
            $this->describer,
        );
    }
}
