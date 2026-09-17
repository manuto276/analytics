<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/migrations'])
    ->withPhpSets(php84: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true, earlyReturn: true)
    ->withSkip([
        Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector::class,
    ])
    ->withCache(__DIR__ . '/var/cache/rector');
