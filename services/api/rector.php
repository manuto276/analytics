<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\BooleanNot\SimplifyDeMorganBinaryRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\CodingStyle\Rector\FuncCall\FunctionFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\Instanceof_\Rector\Ternary\FlipNegatedTernaryInstanceofRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php84\Rector\Foreach_\ForeachToArrayAnyRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/migrations'])
    ->withPhpSets(php84: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true, earlyReturn: true)
    ->withSkip([
        // `$x === null` reads better than `!$x instanceof Foo` in guard clauses.
        FlipTypeControlToUseExclusiveTypeRector::class,
        FlipNegatedTernaryInstanceofRector::class,
        // Named static helpers document intent; they do not need to become instance methods.
        LocallyCalledStaticMethodToNonStaticRector::class,
        // First-class callables are used where they read well, not everywhere.
        FunctionFirstClassCallableRector::class,
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class,
        // Multi-line closures stay closures.
        ClosureToArrowFunctionRector::class,
        // Entities and value objects keep documented properties with attributes.
        ClassPropertyAssignToConstructorPromotionRector::class,
        SimplifyDeMorganBinaryRector::class,
        ForeachToArrayAnyRector::class,
    ])
    ->withCache(__DIR__ . '/var/cache/rector');
