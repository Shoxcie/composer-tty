<?php

declare(strict_types=1);

use Pest\Rector\Set\PestSetList;
use Rector\CodeQuality\Rector\CallLike\AddNameToBooleanArgumentRector;
use Rector\Config\RectorConfig;

/** @noinspection PhpUnhandledExceptionInspection */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/.php-cs-fixer.dist.php',
        __DIR__.'/rector.php',
    ])
    ->withPhpSets()
    ->withAttributesSets()
    ->withIndent()
    ->withImportNames()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        naming: true,
        namedArgs: true,
        rectorPreset: true,
    )
    ->withComposerBased(phpunit: true)
    ->withSets([
        PestSetList::CODING_STYLE,
    ])
    ->withSkip([
        // As of Rector 2.6.7, it names arguments even for @no-named-arguments APIs (e.g. PHP CS Fixer's Config), which PHPStan rejects.
        AddNameToBooleanArgumentRector::class,
    ])
;
