<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Serialize\Serializer\Json;
use MageOS\AdvancedReport\Api\ReportRunnerInterface;
use MageOS\AdvancedReport\Model\DataSource\QueryTimeoutException;
use MageOS\AdvancedReport\Model\DataSource\ReportConnectionUnavailableException;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\ModuleGuard;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Sales\CountedStatusesDescriber;
use MageOS\AdvancedReport\Model\Security\ErrorLogger;

/**
 * Runs a report request and returns the result as JSON.
 *
 * Body is form-encoded: `form_key` and `request` (the report request as a JSON string).
 */
class Data extends Action implements HttpPostActionInterface
{
    public const string ADMIN_RESOURCE = 'MageOS_AdvancedReport::reports';

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Json $serializer
     * @param ReportRequestFactory $requestFactory
     * @param ReportRunnerInterface $reportRunner
     * @param ModuleGuard $moduleGuard
     * @param ErrorLogger $errorLogger
     * @param DefinitionRegistry $registry
     * @param CountedStatusesDescriber $describer
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Json $serializer,
        private readonly ReportRequestFactory $requestFactory,
        private readonly ReportRunnerInterface $reportRunner,
        private readonly ModuleGuard $moduleGuard,
        private readonly ErrorLogger $errorLogger,
        private readonly DefinitionRegistry $registry,
        private readonly CountedStatusesDescriber $describer,
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     * @throws NotFoundException
     */
    public function execute(): JsonResult
    {
        $this->moduleGuard->assertEnabled();
        $result = $this->resultJsonFactory->create();

        try {
            $payload = $this->decodePayload();
            $request = $this->requestFactory->fromArray($payload);
            $report = $this->reportRunner->run($request);
            $data = $report->toArray();
            $data['counted_statuses'] = $this->describer->describe(
                $this->registry->getDataset($request->getDataset()),
                $request->getStoreIds()
            );

            return $result->setData($data);
        } catch (InvalidRequestException $e) {
            return $this->error($result, 400, $e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->error($result, 403, $e->getMessage());
        } catch (QueryTimeoutException $e) {
            return $this->error($result, 504, $e->getMessage());
        } catch (ReportConnectionUnavailableException $e) {
            $this->errorLogger->log($e, 'advanced_report_data');

            return $this->error($result, 503, $e->getMessage());
        } catch (\Throwable $e) {
            $this->errorLogger->log($e, 'advanced_report_data');

            return $this->error($result, 500, (string) __('An error occurred while running the report.'));
        }
    }

    /**
     * @throws InvalidRequestException
     * @return array<string,mixed>
     */
    private function decodePayload(): array
    {
        $raw = $this->getRequest()->getParam('request');
        $payload = null;
        if (\is_string($raw) && $raw !== '') {
            try {
                $payload = $this->serializer->unserialize($raw);
            } catch (\InvalidArgumentException) {
                $payload = null;
            }
        }
        if (!\is_array($payload)) {
            throw new InvalidRequestException(__('The report request is missing or malformed.'));
        }

        return $payload;
    }

    private function error(JsonResult $result, int $code, string $message): JsonResult
    {
        return $result->setHttpResponseCode($code)->setData(['error' => true, 'message' => $message]);
    }
}
