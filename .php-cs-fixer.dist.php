<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude([
        'var',
        'vendor',
        'docs',
        '.rpi-tracking',
        '.claude',
        'bin/harness',
    ])
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'php_unit_method_casing' => ['case' => 'snake_case'],
    ])
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache')
    ->setFinder($finder)
;
