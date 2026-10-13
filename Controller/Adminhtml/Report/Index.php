<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\View\Result\PageFactory;
use MageOS\AdvancedReport\Model\ModuleGuard;

/**
 * Lists presets and saved reports.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const string ADMIN_RESOURCE = 'MageOS_AdvancedReport::reports';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param ModuleGuard $moduleGuard
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly ModuleGuard $moduleGuard,
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
        $page = $this->resultPageFactory->create();
        $page->getConfig()->getTitle()->set((string) __('Advanced Report'));

        return $page;
    }
}
