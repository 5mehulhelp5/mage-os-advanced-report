<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Controller\Adminhtml\Report;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Controller\Adminhtml\Report\Export;
use MageOS\AdvancedReport\Model\DataSource\QueryTimeoutException;
use MageOS\AdvancedReport\Model\Export\CsvExporter;
use MageOS\AdvancedReport\Model\ModuleGuard;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Security\ErrorLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(Export::class)]
final class ExportTest extends TestCase
{
    private ModuleGuard&Stub $guard;
    private AuthorizationInterface $authorization;

    protected function setUp(): void
    {
        $this->guard = $this->createStub(ModuleGuard::class);
        $this->authorization = $this->createStub(AuthorizationInterface::class);
    }

    public function testItDeniesExportWithoutTheExportPermission(): void
    {
        $this->authorization = $this->createMock(AuthorizationInterface::class);
        $this->authorization->expects(self::once())->method('isAllowed')
            ->with('MageOS_AdvancedReport::export')->willReturn(false);

        $isAllowed = new \ReflectionMethod(Export::class, '_isAllowed');

        self::assertFalse($isAllowed->invoke($this->controller()));
    }

    public function testItReturnsNotFoundWhenTheModuleIsDisabled(): void
    {
        $this->guard->method('assertEnabled')->willThrowException(new NotFoundException(new Phrase('Nope.')));

        $this->expectException(NotFoundException::class);
        $this->controller()->execute();
    }

    public function testItStreamsTheFileAndRemovesItAfterDownload(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([
            ['request', null, '{"dataset":"sales_orders"}'],
            ['include_totals', null, '1'],
        ]);
        $report = $this->createStub(ReportRequestInterface::class);
        $report->method('getDataset')->willReturn('sales_orders');
        $factory = $this->createStub(ReportRequestFactory::class);
        $factory->method('fromArray')->willReturn($report);
        $exporter = $this->createMock(CsvExporter::class);
        $exporter->expects(self::once())->method('exportToFile')->with($report, true)
            ->willReturn('export/mageos_advancedreport/abc.csv');
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('20260929-101500');
        $response = $this->createStub(ResponseInterface::class);
        $files = $this->createMock(FileFactory::class);
        $files->expects(self::once())->method('create')->with(
            'advanced-report-sales_orders-20260929-101500.csv',
            ['type' => 'filename', 'value' => 'export/mageos_advancedreport/abc.csv', 'rm' => true],
            DirectoryList::VAR_DIR,
            'text/csv',
        )->willReturn($response);

        self::assertSame($response, $this->controller($request, $files, $factory, $exporter, $dateTime)->execute());
    }

    public function testItShowsTheTimeoutMessageWhenTheExportQueryTimesOut(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['request', null, '{"dataset":"sales_orders"}']]);
        $exporter = $this->createStub(CsvExporter::class);
        $exporter->method('exportToFile')->willThrowException(new QueryTimeoutException(new Phrase('Too slow.')));
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects(self::once())->method('addErrorMessage')->with('Too slow.');

        $this->controller($request, null, null, $exporter, null, $messages)->execute();
    }

    private function controller(
        ?RequestInterface $request = null,
        ?FileFactory $files = null,
        ?ReportRequestFactory $factory = null,
        ?CsvExporter $exporter = null,
        ?DateTime $dateTime = null,
        ?ManagerInterface $messages = null,
    ): Export {
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request ?? $this->createStub(RequestInterface::class));
        $context->method('getAuthorization')->willReturn($this->authorization);
        $context->method('getMessageManager')->willReturn($messages ?? $this->createStub(ManagerInterface::class));
        $context->method('getResultRedirectFactory')->willReturn($this->redirectFactory());

        return new Export(
            $context,
            $files ?? $this->createStub(FileFactory::class),
            new Json(),
            $factory ?? $this->createStub(ReportRequestFactory::class),
            $exporter ?? $this->createStub(CsvExporter::class),
            $this->guard,
            $dateTime ?? $this->createStub(DateTime::class),
            $this->createStub(ErrorLogger::class),
        );
    }

    private function redirectFactory(): RedirectFactory
    {
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $factory = $this->createStub(RedirectFactory::class);
        $factory->method('create')->willReturn($redirect);

        return $factory;
    }
}
