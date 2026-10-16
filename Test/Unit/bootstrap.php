<?php

declare(strict_types=1);

/**
 * Bootstrap for the standalone module install (phpunit.xml.dist, composer test:unit).
 *
 * Inside a Magento install the unit framework generates factories and proxies on demand;
 * here the same autoloader from magento/framework does it, so tests can mock them.
 */

use Magento\Framework\Code\Generator\Io;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesInterfaceGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader;
use Magento\Framework\TestFramework\Unit\Autoloader\ProxyGenerator;

$moduleDir = \dirname(__DIR__, 2);

require $moduleDir . '/vendor/autoload.php';

$generatedCodeAutoloader = new GeneratedClassesAutoloader(
    [
        new ExtensionAttributesGenerator(),
        new ExtensionAttributesInterfaceGenerator(),
        new FactoryGenerator(),
        new ProxyGenerator(),
    ],
    new Io(new File(), $moduleDir . '/.phpunit.cache/generated')
);
spl_autoload_register(static function (string $className) use ($generatedCodeAutoloader): void {
    $generatedCodeAutoloader->load($className);
});
