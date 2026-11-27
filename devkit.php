<?php

declare(strict_types=1);

// KaririCode Devkit (kcode) installs the toolchain into .kcode/ and runs it
// with the configs committed at the root (ADR-015). Versions are exact, so
// every run uses the toolchain the gates were last verified with (§32).
// psr/cache and psr/simple-cache are not tools: the tests and the analysers
// need them for Bridge\Psr6Bridge and Bridge\Psr16Bridge, which users enable
// by installing the interface package (suggest).
return [
    'root_configs' => true,
    'tools' => [
        'phpunit' => '12.5.37',
        'phpstan' => '2.3.7',
        'psalm' => '6.19.1',
        'php-cs-fixer' => '3.95.27',
        'infection/infection' => '0.35.5',
        'phpstan/phpstan-strict-rules' => '2.1.2',
        'tomasvotruba/cognitive-complexity' => '1.2.0',
        'psr/cache' => '3.0.0',
        'psr/simple-cache' => '3.0.0',
    ],
];
