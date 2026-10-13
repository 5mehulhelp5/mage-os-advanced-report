<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MageOS\AdvancedReport\Test\Unit\Helper\XPathTestTrait;
use PHPUnit\Framework\TestCase;

final class AclXmlTest extends TestCase
{
    use XPathTestTrait;

    private const string ACL_XML_PATH = __DIR__ . '/../../../etc/acl.xml';

    private function loadXPath(): DOMXPath
    {
        $document = new DOMDocument();
        $document->load(self::ACL_XML_PATH);

        return new DOMXPath($document);
    }

    public function testItDeclaresAllSevenChildAclResourcesUnderMageOSAdvancedReportReports(): void
    {
        $xpath = $this->loadXPath();
        $nodes = self::queryNodes(
            $xpath,
            '//resource[@id="MageOS_AdvancedReport::reports"]/resource'
        );

        $ids = [];
        foreach ($nodes as $node) {
            self::assertInstanceOf(DOMElement::class, $node);
            $ids[] = $node->getAttribute('id');
        }

        self::assertSame(
            [
                'MageOS_AdvancedReport::sales',
                'MageOS_AdvancedReport::catalog',
                'MageOS_AdvancedReport::customers',
                'MageOS_AdvancedReport::saved_manage',
                'MageOS_AdvancedReport::saved_share',
                'MageOS_AdvancedReport::export',
                'MageOS_AdvancedReport::config',
            ],
            $ids
        );
    }
}
