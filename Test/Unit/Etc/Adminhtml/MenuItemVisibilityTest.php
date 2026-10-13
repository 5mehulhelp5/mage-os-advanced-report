<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc\Adminhtml;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Magento\Backend\Model\Menu\Item;
use Magento\Backend\Model\Menu\Item\Validator;
use Magento\Backend\Model\MenuFactory;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\AdvancedReport\Test\Unit\Helper\XPathTestTrait;
use PHPUnit\Framework\TestCase;

final class MenuItemVisibilityTest extends TestCase
{
    use XPathTestTrait;

    private const string MENU_XML_PATH = __DIR__ . '/../../../../etc/adminhtml/menu.xml';

    /**
     * @return array<string,string>
     */
    private function readMenuItemData(): array
    {
        $document = new DOMDocument();
        $document->load(self::MENU_XML_PATH);
        $xpath = new DOMXPath($document);

        $node = self::queryNodes($xpath, '//add[@resource="MageOS_AdvancedReport::reports"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $node);

        return [
            'id' => $node->getAttribute('id'),
            'title' => $node->getAttribute('title'),
            'resource' => $node->getAttribute('resource'),
            'dependsOnConfig' => $node->getAttribute('dependsOnConfig'),
        ];
    }

    public function testItHidesTheMenuItemWhenTheModuleIsDisabledInConfiguration(): void
    {
        $menuItemData = $this->readMenuItemData();
        self::assertNotSame('', $menuItemData['dependsOnConfig'], 'Menu item must declare a dependsOnConfig path');

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('isSetFlag')
            ->with($menuItemData['dependsOnConfig'], ScopeInterface::SCOPE_STORE)
            ->willReturn(false);

        $moduleManager = $this->createStub(Manager::class);
        $moduleManager->method('isOutputEnabled')->willReturn(true);

        $item = new Item(
            new Validator(),
            $this->createStub(AuthorizationInterface::class),
            $scopeConfig,
            $this->createStub(MenuFactory::class),
            $this->createStub(UrlInterface::class),
            $this->createStub(ModuleListInterface::class),
            $moduleManager,
            $menuItemData
        );

        self::assertTrue($item->isDisabled());
    }
}
