<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MageOS\AdvancedReport\Test\Unit\Helper\XPathTestTrait;
use PHPUnit\Framework\TestCase;

final class ModuleDependenciesTest extends TestCase
{
    use XPathTestTrait;

    private const string MODULE_XML_PATH = __DIR__ . '/../../../etc/module.xml';
    private const string COMPOSER_JSON_PATH = __DIR__ . '/../../../composer.json';

    /** Modules whose composer package name is not the lowercased module name. */
    private const array PACKAGE_OVERRIDES = [
        'Magento_CatalogInventory' => 'magento/module-catalog-inventory',
    ];

    public function testItDeclaresTheSameModuleDependenciesInModuleXmlSequenceAndComposerJsonRequire(): void
    {
        $document = new DOMDocument();
        $document->load(self::MODULE_XML_PATH);
        $xpath = new DOMXPath($document);

        $sequenceModules = [];
        foreach (self::queryNodes($xpath, '//sequence/module') as $node) {
            self::assertInstanceOf(DOMElement::class, $node);
            $sequenceModules[] = $node->getAttribute('name');
        }

        self::assertNotEmpty($sequenceModules);

        $composer = json_decode((string)file_get_contents(self::COMPOSER_JSON_PATH), true);
        $require = array_keys($composer['require']);

        foreach ($sequenceModules as $moduleName) {
            [$vendor, $name] = explode('_', $moduleName, 2);
            $packageName = self::PACKAGE_OVERRIDES[$moduleName] ?? strtolower($vendor) . '/module-' . strtolower($name);

            self::assertContains(
                $packageName,
                $require,
                \sprintf(
                    'Expected composer.json require to contain "%s" for module.xml sequence dependency "%s"',
                    $packageName,
                    $moduleName
                )
            );
        }
    }
}
