<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\NotFoundException;
use MageOS\AdvancedReport\Api\Data\SavedReportInterface;
use MageOS\AdvancedReport\Api\Data\SavedReportInterfaceFactory;
use MageOS\AdvancedReport\Api\SavedReportRepositoryInterface;
use MageOS\AdvancedReport\Model\ModuleGuard;
use MageOS\AdvancedReport\Model\Query\InvalidRequestException;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\RequestValidator;

/**
 * Creates or updates a saved report owned by the current admin.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const string ADMIN_RESOURCE = 'MageOS_AdvancedReport::saved_manage';
    private const string SHARE_RESOURCE = 'MageOS_AdvancedReport::saved_share';
    private const int NAME_MAX_LENGTH = 255;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param ModuleGuard $moduleGuard
     * @param SavedReportRepositoryInterface $repository
     * @param SavedReportInterfaceFactory $savedReportFactory
     * @param ReportRequestFactory $requestFactory
     * @param RequestValidator $requestValidator
     * @param AuthorizationInterface $authorization
     * @param Session $authSession
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ModuleGuard $moduleGuard,
        private readonly SavedReportRepositoryInterface $repository,
        private readonly SavedReportInterfaceFactory $savedReportFactory,
        private readonly ReportRequestFactory $requestFactory,
        private readonly RequestValidator $requestValidator,
        private readonly AuthorizationInterface $authorization,
        private readonly Session $authSession,
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     * @throws NotFoundException
     */
    public function execute(): Json
    {
        $this->moduleGuard->assertEnabled();
        $params = $this->getRequest()->getParams();

        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return $this->error(400, (string) __('A name of 1 to %1 characters is required.', self::NAME_MAX_LENGTH));
        }

        $definition = json_decode((string) ($params['request'] ?? ''), true);
        if (!\is_array($definition)) {
            return $this->error(400, (string) __('The report request payload is invalid.'));
        }

        $isShared = !empty($params['is_shared']);
        if ($isShared && !$this->authorization->isAllowed(self::SHARE_RESOURCE)) {
            return $this->error(403, (string) __('You are not allowed to share reports.'));
        }

        try {
            $request = $this->requestFactory->fromArray($definition);
            $this->requestValidator->validate($request);
        } catch (AuthorizationException $e) {
            return $this->error(403, $e->getMessage());
        } catch (InvalidRequestException $e) {
            return $this->error(400, $e->getMessage());
        }

        $userId = (int) $this->authSession->getUser()?->getId();
        try {
            $report = $this->loadOrCreate((int) ($params['id'] ?? 0), $userId);
        } catch (NoSuchEntityException) {
            return $this->error(404, (string) __('The saved report does not exist.'));
        } catch (AuthorizationException $e) {
            return $this->error(403, $e->getMessage());
        }

        $description = trim((string) ($params['description'] ?? ''));
        $presetCode = (string) ($params['preset_code'] ?? '');
        $report->setName($name)
            ->setDescription($description !== '' ? $description : null)
            ->setDatasetCode($request->getDataset())
            ->setDefinition($this->requestFactory->toArray($request))
            ->setIsShared($isShared)
            ->setPresetCode($presetCode !== '' ? $presetCode : null);

        try {
            $saved = $this->repository->save($report);
        } catch (CouldNotSaveException $e) {
            return $this->error(400, $e->getMessage());
        }

        return $this->resultJsonFactory->create()->setData(['id' => (int) $saved->getId(), 'name' => $saved->getName()]);
    }

    /**
     * @throws NoSuchEntityException
     * @throws AuthorizationException
     */
    private function loadOrCreate(int $id, int $userId): SavedReportInterface
    {
        if ($id === 0) {
            return $this->savedReportFactory->create()->setAdminUserId($userId);
        }

        $report = $this->repository->getById($id);
        if ($report->getAdminUserId() !== $userId) {
            throw new AuthorizationException(__('Only the owner can modify this saved report.'));
        }

        return $report;
    }

    private function error(int $code, string $message): Json
    {
        return $this->resultJsonFactory->create()
            ->setHttpResponseCode($code)
            ->setData(['error' => true, 'message' => $message]);
    }
}
