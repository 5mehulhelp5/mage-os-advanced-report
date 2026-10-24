<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use MageOS\AdvancedReport\Model\DataSource\QueryTimeoutException;
use MageOS\AdvancedReport\Model\DataSource\ReportConnectionUnavailableException;
use MageOS\AdvancedReport\Model\Export\CsvExporter;
use MageOS\AdvancedReport\Model\ModuleGuard;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Security\ErrorLogger;

/**
 * Downloads the given report request as CSV.
 *
 * Posted as a regular (non-AJAX) form: `form_key`, `request` (the report request as a JSON
 * string) and optional `include_totals` (1 to append the totals row).
 */
class Export extends Action implements HttpPostActionInterface
{
    public const string ADMIN_RESOURCE = 'MageOS_AdvancedReport::export';

    /**
     * @param Context $context
     * @param FileFactory $fileFactory
     * @param Json $serializer
     * @param ReportRequestFactory $requestFactory
     * @param CsvExporter $exporter
     * @param ModuleGuard $moduleGuard
     * @param DateTime $dateTime
     * @param ErrorLogger $errorLogger
     */
    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly Json $serializer,
        private readonly ReportRequestFactory $requestFactory,
        private readonly CsvExporter $exporter,
        private readonly ModuleGuard $moduleGuard,
        private readonly DateTime $dateTime,
        private readonly ErrorLogger $errorLogger,
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     * @throws NotFoundException
     */
    public function execute(): ResponseInterface|ResultInterface
    {
        $this->moduleGuard->assertEnabled();

        try {
            $request = $this->requestFactory->fromArray($this->decodePayload());
            $path = $this->exporter->exportToFile($request, (bool) $this->getRequest()->getParam('include_totals'));
            $fileName = \sprintf('advanced-report-%s-%s.csv', $request->getDataset(), $this->dateTime->gmtDate('Ymd-His'));

            return $this->fileFactory->create(
                $fileName,
                ['type' => 'filename', 'value' => $path, 'rm' => true],
                DirectoryList::VAR_DIR,
                'text/csv'
            );
        } catch (QueryTimeoutException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        } catch (ReportConnectionUnavailableException $e) {
            $this->errorLogger->log($e, 'advanced_report_export');
            $this->messageManager->addErrorMessage($e->getMessage());

            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        } catch (\Throwable $e) {
            $this->errorLogger->log($e, 'advanced_report_export');
            $this->messageManager->addErrorMessage(__('The report could not be exported.'));

            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decodePayload(): array
    {
        $raw = $this->getRequest()->getParam('request');
        $payload = \is_string($raw) && $raw !== '' ? $this->serializer->unserialize($raw) : null;
        if (!\is_array($payload)) {
            throw new \InvalidArgumentException('The report request is missing or malformed.');
        }

        return $payload;
    }
}
