<?php

declare(strict_types=1);

namespace Analytics\Kernel;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;

use function DI\factory;

final class KernelModule extends Module
{
    public function definitions(Settings $settings): array
    {
        return [
            DependencyFactory::class => factory(static fn(Connection $c, Settings $s, EntityManagerInterface $em): DependencyFactory => ConsoleApplicationFactory::migrationsDependencyFactory($c, $s, $em)),
        ];
    }

    public function commands(): array
    {
        return [
            Console\PreflightCommand::class,
            Console\CacheWarmupCommand::class,
            Console\CacheClearCommand::class,
            Console\SecretsGenerateCommand::class,
            Console\OrmValidateSchemaCommand::class,
        ];
    }
}
