<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Set\ValueObject\LevelSetList;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnNeverTypeRector;

return RectorConfig::configure()
    ->withFileExtensions(['php'])
    ->withImportNames(importShortClasses: false)
    ->withParallel()
    ->withPaths(array_map(
        static fn (string $dir): string => __DIR__ . '/' . $dir,
        ['Api', 'Console', 'Controller', 'Model', 'Observer', 'Test', 'ViewModel']
    ))
    ->withSets([
        LevelSetList::UP_TO_PHP_83,
        PHPUnitSetList::COMPOSER_BASED,
    ])
    ->withPreparedSets(typeDeclarations: true)
    ->withSkip([
        ReturnNeverTypeRector::class,
        // Needs the Magento integration test framework, which the standalone install does not ship.
        __DIR__ . '/Test/Integration',
    ]);
