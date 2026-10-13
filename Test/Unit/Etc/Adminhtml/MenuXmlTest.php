<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc\Adminhtml;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MageOS\AdvancedReport\Test\Unit\Helper\XPathTestTrait;
use PHPUnit\Framework\TestCase;

final class MenuXmlTest extends TestCase
{
    use XPathTestTrait;

    private const string MENU_XML_PATH = __DIR__ . '/../../../../etc/adminhtml/menu.xml';

    private function loadXPath(): DOMXPath
    {
        $document = new DOMDocument();
        $document->load(self::MENU_XML_PATH);

        return new DOMXPath($document);
    }

    public function testItDeclaresTheMenuItemBoundToTheReportsAclResourceAndIndexAction(): void
    {
        $xpath = $this->loadXPath();
        $nodes = self::queryNodes($xpath, '//add[@resource="MageOS_AdvancedReport::reports"]');

        self::assertSame(1, $nodes->length);
        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node);
        self::assertSame('advancedreport/report/index', $node->getAttribute('action'));
    }
}
