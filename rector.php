<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/app',
    ])
    ->withSkip([
        __DIR__ . '/app/generated/*',
        __DIR__ . '/app/.foundry/*',
        __DIR__ . '/vendor/*',
    ])
    ->withImportNames(
        importShortClasses: false,
        removeUnusedImports: true,
    );
