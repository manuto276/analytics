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
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->useAttributes(false);

        if ($settings->isProd() && $overrides === []) {
            $builder->enableCompilation($settings->cacheDir . '/container');
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
}
