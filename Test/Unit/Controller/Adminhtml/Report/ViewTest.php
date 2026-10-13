<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Controller\Adminhtml\Report;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use MageOS\AdvancedReport\Controller\Adminhtml\Report\View;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Exploration\InitialStateResolver;
use MageOS\AdvancedReport\Model\ModuleGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(View::class)]
final class ViewTest extends TestCase
{
    public function testItRedirectsToTheIndexWhenTheSavedReportIsNotVisible(): void
    {
        $resolver = $this->createStub(InitialStateResolver::class);
        $resolver->method('resolve')->willThrowException(new NoSuchEntityException(new Phrase('Gone')));
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects(self::once())->method('addErrorMessage')->with('Gone');
        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')->with('*/*/index')->willReturnSelf();
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);
        $pages = $this->createMock(PageFactory::class);
        $pages->expects(self::never())->method('create');

        $result = $this->controller($resolver, $pages, $this->createStub(ModuleGuard::class), $messages, $redirectFactory)
            ->execute();

        self::assertSame($redirect, $result);
    }

    public function testItReturnsNotFoundWhenTheModuleIsDisabled(): void
    {
        $guard = $this->createStub(ModuleGuard::class);
        $guard->method('assertEnabled')->willThrowException(new NotFoundException(new Phrase('Page not found.')));
        $resolver = $this->createMock(InitialStateResolver::class);
        $resolver->expects(self::never())->method('resolve');

        $this->expectException(NotFoundException::class);
        $this->controller($resolver, $this->createStub(PageFactory::class), $guard)->execute();
    }

    public function testItTitlesThePageWithTheSavedReportName(): void
    {
        $title = $this->runForTitle(['id' => '3'], ['request' => [], 'saved' => ['name' => 'My report']]);

        self::assertSame('My report', $title);
    }

    public function testItTitlesThePageWithTheTranslatedPresetLabel(): void
    {
        $registry = $this->createStub(DefinitionRegistry::class);
        $registry->method('getPreset')->willReturn($this->preset('Sales overview'));

        $title = $this->runForTitle(['preset' => 'sales'], ['request' => [], 'saved' => null], $registry);

        self::assertSame('Sales overview', $title);
    }

    public function testItTitlesThePageAdvancedReportForAnEmptyExploration(): void
    {
        self::assertSame('Advanced Report', $this->runForTitle([], ['request' => [], 'saved' => null]));
    }

    public function testItPrefersTheSavedReportNameWhenBothIdAndPresetAreGiven(): void
    {
        $registry = $this->createStub(DefinitionRegistry::class);
        $registry->method('getPreset')->willReturn($this->preset('Sales overview'));

        $title = $this->runForTitle(
            ['id' => '3', 'preset' => 'sales'],
            ['request' => [], 'saved' => ['name' => 'My report']],
            $registry
        );

        self::assertSame('My report', $title);
    }

    public function testItFallsBackToAdvancedReportWhenThePresetIsUnknownToTheRegistry(): void
    {
        $registry = $this->createStub(DefinitionRegistry::class);
        $registry->method('getPreset')->willThrowException(new NoSuchDefinitionException(new Phrase('Unknown')));

        $title = $this->runForTitle(['preset' => 'nope'], ['request' => [], 'saved' => null], $registry);

        self::assertSame('Advanced Report', $title);
    }

    /**
     * @param array<string,string> $params
     * @param array<string,mixed> $state
     */
    private function runForTitle(array $params, array $state, ?DefinitionRegistry $registry = null): string
    {
        $resolver = $this->createStub(InitialStateResolver::class);
        $resolver->method('resolve')->willReturn($state);
        $captured = null;
        $title = $this->createStub(Title::class);
        $title->method('set')->willReturnCallback(static function ($value) use (&$captured, $title): Stub {
            $captured = (string) $value;

            return $title;
        });
        $config = $this->createStub(PageConfig::class);
        $config->method('getTitle')->willReturn($title);
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($config);
        $pages = $this->createStub(PageFactory::class);
        $pages->method('create')->willReturn($page);

        $this->controller(
            $resolver,
            $pages,
            $this->createStub(ModuleGuard::class),
            params: $params,
            registry: $registry,
        )->execute();

        return (string) $captured;
    }

    private function preset(string $label): Preset
    {
        return new Preset('sales', 'sales', $label, 'orders', [], [], [], [], 'none', null, ['mysql']);
    }

    /**
     * @param array<string, mixed>|null $params
     */
    private function controller(
        InitialStateResolver $resolver,
        PageFactory $pages,
        ModuleGuard $guard,
        ?ManagerInterface $messages = null,
        ?RedirectFactory $redirectFactory = null,
        ?array $params = null,
        ?DefinitionRegistry $registry = null,
    ): View {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $name) => $params === null ? '3' : ($params[$name] ?? null)
        );
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages ?? $this->createStub(ManagerInterface::class));
        $context->method('getResultRedirectFactory')->willReturn(
            $redirectFactory ?? $this->createStub(RedirectFactory::class)
        );

        return new View(
            $context,
            $pages,
            $guard,
            $resolver,
            $this->createStub(Session::class),
            $registry ?? $this->createStub(DefinitionRegistry::class),
        );
    }
}
