<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\NotFoundException;
use MageOS\AdvancedReport\Api\SavedReportRepositoryInterface;
use MageOS\AdvancedReport\Model\ModuleGuard;

/**
 * Deletes a saved report owned by the current admin.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const string ADMIN_RESOURCE = 'MageOS_AdvancedReport::saved_manage';

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param ModuleGuard $moduleGuard
     * @param SavedReportRepositoryInterface $repository
     * @param Session $authSession
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ModuleGuard $moduleGuard,
        private readonly SavedReportRepositoryInterface $repository,
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

        try {
            $report = $this->repository->getById((int) $this->getRequest()->getParam('id'));
            if ($report->getAdminUserId() !== (int) $this->authSession->getUser()?->getId()) {
                return $this->error(403, (string) __('Only the owner can delete this saved report.'));
            }
            $this->repository->delete($report);
        } catch (NoSuchEntityException) {
            return $this->error(404, (string) __('The saved report does not exist.'));
        } catch (CouldNotDeleteException $e) {
            return $this->error(400, $e->getMessage());
        }

        return $this->resultJsonFactory->create()->setData(['success' => true]);
    }

    private function error(int $code, string $message): Json
    {
        return $this->resultJsonFactory->create()
            ->setHttpResponseCode($code)
            ->setData(['error' => true, 'message' => $message]);
    }
}
