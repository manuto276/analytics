<?php

declare(strict_types=1);

namespace Analytics\Kernel;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\ConsoleRunner as MigrationsConsoleRunner;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Console\Command as OrmCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;

final class ConsoleApplicationFactory
{
    public static function create(ContainerInterface $container): Application
    {
        $build = $container->get(BuildInfo::class);
        \assert($build instanceof BuildInfo);
        $application = new Application('analytics', $build->version . ' (' . substr($build->commit, 0, 12) . ')');

        $map = [];
        foreach (Modules::all() as $module) {
            foreach ($module->commands() as $class) {
                $attribute = new \ReflectionClass($class)->getAttributes(\Symfony\Component\Console\Attribute\AsCommand::class)[0] ?? null;
                if ($attribute === null) {
                    throw new \LogicException($class . ' needs #[AsCommand].');
                }
                /** @var \Symfony\Component\Console\Attribute\AsCommand $meta */
                $meta = $attribute->newInstance();
                $map[$meta->name] = $class;
            }
        }
        $application->setCommandLoader(new ContainerCommandLoader($container, $map));

        $dependencyFactory = $container->get(DependencyFactory::class);
        \assert($dependencyFactory instanceof DependencyFactory);
        MigrationsConsoleRunner::addCommands($application, $dependencyFactory);

        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $provider = new SingleManagerProvider($em);
        $application->addCommands([
            new OrmCommand\InfoCommand($provider),
            new OrmCommand\SchemaTool\UpdateCommand($provider),
        ]);

        return $application;
    }

    public static function migrationsDependencyFactory(Connection $connection, Settings $settings, EntityManagerInterface $em): DependencyFactory
    {
        $config = new ConfigurationArray([
            'table_storage' => ['table_name' => 'doctrine_migration_versions'],
            'migrations_paths' => ['Analytics\\Migrations' => $settings->projectDir . '/migrations'],
            'all_or_nothing' => false,
            'transactional' => false,
            'check_database_platform' => true,
            'organize_migrations' => 'none',
        ]);

        return DependencyFactory::fromEntityManager($config, new \Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager($em));
    }
}
