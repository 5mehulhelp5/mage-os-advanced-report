<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\View\Result\PageFactory;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Exploration\InitialStateResolver;
use MageOS\AdvancedReport\Model\ModuleGuard;

/**
 * Hosts the exploration JS app for a preset (`preset`), a saved report (`id`) or an empty exploration.
 */
class View extends Action implements HttpGetActionInterface
{
    public const string ADMIN_RESOURCE = 'MageOS_AdvancedReport::reports';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param ModuleGuard $moduleGuard
     * @param InitialStateResolver $initialStateResolver
     * @param Session $authSession
     * @param DefinitionRegistry $definitionRegistry
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly ModuleGuard $moduleGuard,
        private readonly InitialStateResolver $initialStateResolver,
        private readonly Session $authSession,
        private readonly DefinitionRegistry $definitionRegistry,
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     * @throws NotFoundException
     */
    public function execute(): ResultInterface
    {
        $this->moduleGuard->assertEnabled();
        $id = (string) $this->getRequest()->getParam('id');
        $presetCode = (string) $this->getRequest()->getParam('preset') ?: null;
        try {
            $state = $this->initialStateResolver->resolve(
                $presetCode,
                $id !== '' ? (int) $id : null,
                (int) $this->authSession->getUser()?->getId(),
            );
        } catch (NoSuchEntityException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());

            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }
        $page = $this->resultPageFactory->create();
        $page->getConfig()->getTitle()->set(
            $this->resolveTitle($state['saved']['name'] ?? null, $presetCode)
        );

        return $page;
    }

    private function resolveTitle(?string $savedName, ?string $presetCode): string
    {
        if ($savedName !== null) {
            return $savedName;
        }
        if ($presetCode !== null) {
            try {
                return (string) __($this->definitionRegistry->getPreset($presetCode)->label);
            } catch (NoSuchDefinitionException) {
                // Fall through to the default title.
            }
        }

        return (string) __('Advanced Report');
    }
}
