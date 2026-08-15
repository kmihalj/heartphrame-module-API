<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/heartphrame-manifest.php',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        instanceOf: true,
        earlyReturn: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
    )
    ->withSkip([
        // HR: Opcionalne integracije moraju ostati class-stringovi bez tvrde ovisnosti.
        // EN: Optional integrations must remain class strings without a hard dependency.
        StringClassNameToClassConstantRector::class => [
            __DIR__ . '/src/Controller/ApiKeyController.php',
            __DIR__ . '/src/Service/ApiKeyRequestNotifier.php',
            __DIR__ . '/src/Service/ApiMenuIntegration.php',
        ],
    ]);
