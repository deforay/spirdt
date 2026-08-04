<?php

// Minimal PHP-CS-Fixer config. Warn-only in CI for now (see ci.yml). The
// goal is to surface drift, not to enforce a hard gate or auto-rewrite the
// codebase — so the rule set is intentionally small.

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/bin',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

$config = new PhpCsFixer\Config();
$config
    ->setRiskyAllowed(false)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRules([
        // PSR-12 baseline — the safe, near-universal default.
        '@PSR12' => true,

        // Small wins on top of PSR-12, all non-risky:
        'no_unused_imports'           => true,    // catches dead `use` lines
        'ordered_imports'             => ['sort_algorithm' => 'alpha'],
        'no_extra_blank_lines'        => true,    // no triple newlines
        'single_quote'                => true,    // string consistency
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'array_syntax'                => ['syntax' => 'short'],
        'no_trailing_whitespace'      => true,
        'no_whitespace_in_blank_line' => true,
    ]);

return $config;
