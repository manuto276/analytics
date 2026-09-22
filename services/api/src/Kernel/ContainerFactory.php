<?php

declare(strict_types=1);

namespace Analytics\Kernel;

use DI\Container;
use DI\ContainerBuilder;

final class ContainerFactory
{
    /**
     * @param array<string, mixed> $overrides definitions replacing module ones (tests)
     */
    public static function create(Settings $settings, array $overrides = []): Container
    {
        \Analytics\Shared\Doctrine\Partitioning::configure($settings->dbPartitioning);

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->useAttributes(false);

        if ($settings->isProd() && $overrides === []) {
            $builder->enableCompilation(self::compilationDir($settings), self::compiledClass($settings));
        }

        $builder->addDefinitions([Settings::class => $settings]);
        foreach (Modules::all() as $module) {
            $builder->addDefinitions($module->definitions($settings));
        }
        if ($overrides !== []) {
            $builder->addDefinitions($overrides);
        }

        return $builder->build();
    }

    /**
     * The compiled container holds absolute paths (the tracker bundle, the SPA, the migrations), and
     * PHP-DI reuses a compiled file for as long as it exists. Keying the directory by the project
     * directory means a container compiled somewhere else is never picked up: not one compiled in the
     * deploy's temporary releases/.tmp-<TS> before it was renamed, and not one left in a CACHE_DIR
     * shared across releases.
     */
    public static function compilationDir(Settings $settings): string
    {
        return $settings->cacheDir . '/container/' . self::pathKey($settings);
    }

    /** The class name carries the same key, so two compiled containers never clash in one process. */
    public static function compiledClass(Settings $settings): string
    {
        return 'CompiledContainer_' . self::pathKey($settings);
    }

    private static function pathKey(Settings $settings): string
    {
        return substr(hash('sha256', $settings->projectDir), 0, 16);
    }
}
