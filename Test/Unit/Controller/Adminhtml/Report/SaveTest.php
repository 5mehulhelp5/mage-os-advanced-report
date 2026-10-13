<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Controller\Adminhtml\Report;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Phrase;
use Magento\User\Model\User;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\Data\SavedReportInterface;
use MageOS\AdvancedReport\Api\Data\SavedReportInterfaceFactory;
use MageOS\AdvancedReport\Api\SavedReportRepositoryInterface;
use MageOS\AdvancedReport\Controller\Adminhtml\Report\Save;
use MageOS\AdvancedReport\Model\ModuleGuard;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\RequestValidator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(Save::class)]
#[AllowMockObjectsWithoutExpectations]
final class SaveTest extends TestCase
{
    private const int USER_ID = 7;

    /** @var array<string,mixed> */
    private array $params = [];
    private Json&Stub $json;
    private ModuleGuard&Stub $guard;
    private SavedReportRepositoryInterface&MockObject $repository;
    private SavedReportInterfaceFactory&MockObject $reportFactory;
    private ReportRequestFactory&Stub $requestFactory;
    private RequestValidator&Stub $validator;
    private AuthorizationInterface&Stub $authorization;
    private bool $allowSharing = true;
    private ?int $httpCode = null;
    /** @var array<string,mixed>|null */
    private ?array $body = null;

    protected function setUp(): void
    {
        $this->json = $this->createStub(Json::class);
        $this->json->method('setHttpResponseCode')->willReturnCallback(function (int $code): Json {
            $this->httpCode = $code;

            return $this->json;
        });
        $this->json->method('setData')->willReturnCallback(function (array $data): Json {
            $this->body = $data;

            return $this->json;
        });
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($this->json);

        $this->guard = $this->createStub(ModuleGuard::class);
        $this->repository = $this->createMock(SavedReportRepositoryInterface::class);
        $this->reportFactory = $this->createMock(SavedReportInterfaceFactory::class);
        $this->requestFactory = $this->createStub(ReportRequestFactory::class);
        $this->validator = $this->createStub(RequestValidator::class);
        $this->authorization = $this->createStub(AuthorizationInterface::class);
        $this->authorization->method('isAllowed')->willReturnCallback(fn (): bool => $this->allowSharing);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn((string) self::USER_ID);
        $session = new class($user) extends Session {
            public function __construct(private readonly object $user)
            {
            }

            /**
             * @param array<mixed> $args
             */
            public function __call($method, $args)
            {
                return $this->user;
            }
        };

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParams')->willReturnCallback(fn (): array => $this->params);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);

        $this->controller = new Save(
            $context,
            $jsonFactory,
            $this->guard,
            $this->repository,
            $this->reportFactory,
            $this->requestFactory,
            $this->validator,
            $this->authorization,
            $session,
        );
    }

    private Save $controller;

    /**
     * @return array<string,mixed>
     */
    private function validParams(): array
    {
        return [
            'name' => 'My report',
            'description' => 'desc',
            'is_shared' => '0',
            'request' => '{"dataset":"sales_orders","metrics":["revenue"]}',
        ];
    }

    private function stubRequestParsing(): void
    {
        $reportRequest = $this->createStub(ReportRequestInterface::class);
        $reportRequest->method('getDataset')->willReturn('sales_orders');
        $this->requestFactory->method('fromArray')->willReturn($reportRequest);
        $this->requestFactory->method('toArray')->willReturn(['dataset' => 'sales_orders']);
    }

    private function report(int $owner, int $id = 5): SavedReportInterface&MockObject
    {
        $report = $this->createMock(SavedReportInterface::class);
        $report->method('getAdminUserId')->willReturn($owner);
        $report->method('getId')->willReturn($id);
        $report->method('getName')->willReturn('My report');
        foreach (['setName', 'setDescription', 'setDatasetCode', 'setDefinition', 'setIsShared', 'setPresetCode',
            'setAdminUserId'] as $setter) {
            $report->method($setter)->willReturnSelf();
        }

        return $report;
    }

    public function testItCreatesASavedReportOwnedByTheCurrentAdmin(): void
    {
        $this->params = $this->validParams();
        $this->stubRequestParsing();
        $report = $this->report(self::USER_ID);
        $report->expects(self::once())->method('setAdminUserId')->with(self::USER_ID)->willReturnSelf();
        $report->expects(self::once())->method('setDatasetCode')->with('sales_orders')->willReturnSelf();
        $this->reportFactory->method('create')->willReturn($report);
        $this->repository->expects(self::once())->method('save')->with($report)->willReturn($report);

        $this->controller->execute();

        self::assertSame(['id' => 5, 'name' => 'My report'], $this->body);
    }

    public function testItUpdatesAnExistingReportOwnedByTheCurrentAdmin(): void
    {
        $this->params = $this->validParams() + ['id' => '5'];
        $this->stubRequestParsing();
        $report = $this->report(self::USER_ID);
        $this->repository->expects(self::atLeastOnce())->method('getById')->with(5)->willReturn($report);
        $this->reportFactory->expects(self::never())->method('create');
        $this->repository->expects(self::once())->method('save')->with($report)->willReturn($report);

        $this->controller->execute();

        self::assertSame(['id' => 5, 'name' => 'My report'], $this->body);
    }

    public function testItRefusesUpdatingReportsOwnedByAnotherAdmin(): void
    {
        $this->params = $this->validParams() + ['id' => '5'];
        $this->stubRequestParsing();
        $this->repository->method('getById')->willReturn($this->report(99));
        $this->repository->expects(self::never())->method('save');

        $this->controller->execute();

        self::assertSame(403, $this->httpCode);
        self::assertIsArray($this->body);
        self::assertTrue($this->body['error']);
    }

    public function testItRefusesSharingWithoutTheSharePermission(): void
    {
        $this->allowSharing = false;
        $this->params = ['is_shared' => '1'] + $this->validParams();
        $this->stubRequestParsing();
        $this->repository->expects(self::never())->method('save');

        $this->controller->execute();

        self::assertSame(403, $this->httpCode);
    }

    public function testItRejectsMissingNamesAndInvalidRequestPayloads(): void
    {
        $this->params = ['name' => ' '] + $this->validParams();
        $this->controller->execute();
        self::assertSame(400, $this->httpCode);

        $this->params = ['request' => 'not-json'] + $this->validParams();
        $this->httpCode = null;
        $this->controller->execute();
        self::assertSame(400, $this->httpCode);

        $this->params = ['name' => str_repeat('a', 256)] + $this->validParams();
        $this->httpCode = null;
        $this->controller->execute();
        self::assertSame(400, $this->httpCode);
    }

    public function testItRejectsStructurallyInvalidRequests(): void
    {
        $this->params = $this->validParams();
        $this->requestFactory->method('fromArray')
            ->willThrowException(new InvalidRequestException(new Phrase('bad')));

        $this->controller->execute();

        self::assertSame(400, $this->httpCode);
        self::assertIsArray($this->body);
        self::assertSame('bad', $this->body['message']);
    }

    public function testItRefusesDatasetsTheAdminCannotAccess(): void
    {
        $this->params = $this->validParams();
        $this->stubRequestParsing();
        $this->validator->method('validate')
            ->willThrowException(new AuthorizationException(new Phrase('denied')));

        $this->controller->execute();

        self::assertSame(403, $this->httpCode);
    }

    public function testItReturnsNotFoundWhenTheModuleIsDisabled(): void
    {
        $this->guard->method('assertEnabled')
            ->willThrowException(new NotFoundException(new Phrase('Page not found.')));

        $this->expectException(NotFoundException::class);
        $this->controller->execute();
    }
}
