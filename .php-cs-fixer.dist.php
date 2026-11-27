<?php

declare(strict_types=1);

// PER Coding Style: the spec pins 3.1 (E-045); php-cs-fixer ships 3.0 as its
// newest set, whose rules cover the E-046 corrections (fn( without a space,
// a blank line after namespace, no hard line limit).
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3x0' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/benchmark', __DIR__ . '/tools'])
            ->exclude('contract-2.11-fixture')
            ->append([__FILE__]),
    );
