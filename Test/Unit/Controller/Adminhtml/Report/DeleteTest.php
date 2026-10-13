<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Controller\Adminhtml\Report;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Phrase;
use Magento\User\Model\User;
use MageOS\AdvancedReport\Api\Data\SavedReportInterface;
use MageOS\AdvancedReport\Api\SavedReportRepositoryInterface;
use MageOS\AdvancedReport\Controller\Adminhtml\Report\Delete;
use MageOS\AdvancedReport\Model\ModuleGuard;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(Delete::class)]
#[AllowMockObjectsWithoutExpectations]
final class DeleteTest extends TestCase
{
    private Json&Stub $json;
    private ModuleGuard&Stub $guard;
    private SavedReportRepositoryInterface&MockObject $repository;
    private Delete $controller;
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

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn('7');
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
        $request->method('getParam')->willReturn('5');
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);

        $this->controller = new Delete($context, $jsonFactory, $this->guard, $this->repository, $session);
    }

    private function report(int $owner): SavedReportInterface
    {
        $report = $this->createStub(SavedReportInterface::class);
        $report->method('getAdminUserId')->willReturn($owner);

        return $report;
    }

    public function testItDeletesAnOwnedReport(): void
    {
        $report = $this->report(7);
        $this->repository->expects(self::atLeastOnce())->method('getById')->with(5)->willReturn($report);
        $this->repository->expects(self::once())->method('delete')->with($report)->willReturn(true);

        $this->controller->execute();

        self::assertSame(['success' => true], $this->body);
    }

    public function testItRefusesDeletingReportsOwnedByAnotherAdmin(): void
    {
        $this->repository->method('getById')->willReturn($this->report(99));
        $this->repository->expects(self::never())->method('delete');

        $this->controller->execute();

        self::assertSame(403, $this->httpCode);
        self::assertIsArray($this->body);
        self::assertTrue($this->body['error']);
    }

    public function testItReturnsNotFoundWhenTheModuleIsDisabled(): void
    {
        $this->guard->method('assertEnabled')
            ->willThrowException(new NotFoundException(new Phrase('Page not found.')));

        $this->expectException(NotFoundException::class);
        $this->controller->execute();
    }
}
