<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc\Adminhtml;

use DOMDocument;
use DOMXPath;
use MageOS\AdvancedReport\Test\Unit\Helper\XPathTestTrait;
use PHPUnit\Framework\TestCase;

final class ReportViewLayoutTest extends TestCase
{
    use XPathTestTrait;

    private const string LAYOUT_PATH = __DIR__ . '/../../../../view/adminhtml/layout/advancedreport_report_view.xml';
    private const string ACTIONS_TEMPLATE_PATH = __DIR__ . '/../../../../view/adminhtml/templates/report/actions.phtml';
    private const string ACTIONS_BLOCK = 'advancedreport.report.view.actions';

    private function loadXPath(): DOMXPath
    {
        $document = new DOMDocument();
        $document->load(self::LAYOUT_PATH);

        return new DOMXPath($document);
    }

    public function testItAddsTheActionsBlockToThePageActionsToolbar(): void
    {
        $nodes = self::queryNodes(
            $this->loadXPath(),
            '//referenceBlock[@name="page.actions.toolbar"]/block[@name="' . self::ACTIONS_BLOCK . '"]'
        );

        self::assertSame(1, $nodes->length);
    }

    public function testItRendersTheActionsBlockWithTheActionsTemplate(): void
    {
        $nodes = self::queryNodes(
            $this->loadXPath(),
            '//block[@name="' . self::ACTIONS_BLOCK . '"]'
            . '[@class="Magento\Framework\View\Element\Template"]'
            . '[@template="MageOS_AdvancedReport::report/actions.phtml"]'
        );

        self::assertSame(1, $nodes->length);
    }

    public function testItKeepsTheExplorationBlockInTheContentContainer(): void
    {
        $nodes = self::queryNodes(
            $this->loadXPath(),
            '//referenceContainer[@name="content"]/block[@name="advancedreport.report.view"]'
        );

        self::assertSame(1, $nodes->length);
    }

    public function testItKeepsTheReportMenuItemActive(): void
    {
        $nodes = self::queryNodes(
            $this->loadXPath(),
            '//referenceBlock[@name="menu"]/action[@method="setActive"]'
            . '/argument[@name="itemId"][.="MageOS_AdvancedReport::report"]'
        );

        self::assertSame(1, $nodes->length);
    }

    public function testActionsTemplateOutputsASingleMountPointWithTheIsolateBinding(): void
    {
        $content = (string) file_get_contents(self::ACTIONS_TEMPLATE_PATH);

        self::assertSame(1, substr_count($content, 'id="advancedreport-actions"'));
        self::assertStringContainsString('data-bind="advancedreportIsolate: true"', $content);
    }

    public function testActionsMountDoesNotCarryThePageActionsButtonsClass(): void
    {
        $content = (string) file_get_contents(self::ACTIONS_TEMPLATE_PATH);

        self::assertStringNotContainsString('page-actions-buttons', $content);
    }
}
