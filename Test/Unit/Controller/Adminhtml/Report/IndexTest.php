<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Controller\Adminhtml\Report;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Phrase;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use MageOS\AdvancedReport\Controller\Adminhtml\Report\Index;
use MageOS\AdvancedReport\Model\ModuleGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Index::class)]
final class IndexTest extends TestCase
{
    public function testItRendersTheIndexPageWithThePageTitle(): void
    {
        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('set')->with('Advanced Report');
        $config = $this->createStub(PageConfig::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($config);
        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($page);

        $controller = new Index($this->createStub(Context::class), $factory, $this->createStub(ModuleGuard::class));

        self::assertSame($page, $controller->execute());
    }

    public function testItReturnsNotFoundOnTheIndexPageWhenTheModuleIsDisabled(): void
    {
        $guard = $this->createStub(ModuleGuard::class);
        $guard->method('assertEnabled')->willThrowException(new NotFoundException(new Phrase('Page not found.')));
        $factory = $this->createMock(PageFactory::class);
        $factory->expects(self::never())->method('create');

        $controller = new Index($this->createStub(Context::class), $factory, $guard);

        $this->expectException(NotFoundException::class);
        $controller->execute();
    }
}
