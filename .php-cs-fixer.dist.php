<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return new Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@auto' => true,
        '@auto:risky' => true,
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        'global_namespace_import' => ['import_classes' => true],
        'phpdoc_to_comment' => [
            'ignored_tags' => ['noinspection'],
        ],
        // Pest binds $this to test closures, which fails for static ones.
        'static_lambda' => false,
    ])
    ->setFinder(
        new Finder()
            ->in(__DIR__)
    )
;
