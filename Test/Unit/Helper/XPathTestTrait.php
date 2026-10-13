<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Helper;

use DOMNameSpaceNode;
use DOMNode;
use DOMNodeList;
use DOMXPath;

/**
 * Runs XPath queries that fail the test on an invalid expression instead of returning `false`.
 *
 * Requires the using class to extend {@see \PHPUnit\Framework\TestCase}.
 */
trait XPathTestTrait
{
    /**
     * @return DOMNodeList<DOMNameSpaceNode|DOMNode>
     */
    protected static function queryNodes(DOMXPath $xpath, string $expression, ?DOMNode $context = null): DOMNodeList
    {
        $nodes = $xpath->query($expression, $context);
        self::assertNotFalse($nodes, 'Invalid XPath expression: ' . $expression);

        return $nodes;
    }
}
