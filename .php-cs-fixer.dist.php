<?php
return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        '@PHP82Migration' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'ordered_imports' => true,
        'no_unused_imports' => true,
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => true,
        'return_type_declaration' => ['space_before' => 'none'],
        // Matches the project-wide config (@PSR2), so both report the same anonymous class style.
        'class_definition' => ['space_before_parenthesis' => false],
    ])
    ->setFinder(
        (new PhpCsFixer\Finder())
            ->in(array_map(
                static fn (string $dir): string => __DIR__ . '/' . $dir,
                ['Api', 'Console', 'Controller', 'Model', 'Observer', 'Test', 'ViewModel']
            ))
    );
