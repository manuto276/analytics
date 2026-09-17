<?php

declare(strict_types=1);

namespace Analytics\Shared\Doctrine;

use Analytics\Kernel\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;

final class EntityManagerFactory
{
    /** @param list<string> $paths */
    public static function create(Connection $connection, Settings $settings, array $paths): EntityManagerInterface
    {
        $cache = $settings->isProd()
            ? new PhpFilesAdapter('doctrine', 0, $settings->cacheDir)
            : new ArrayAdapter();

        $config = ORMSetup::createAttributeMetadataConfig($paths, !$settings->isProd(), null, $cache);
        $config->enableNativeLazyObjects(true);
        $config->setSchemaAssetsFilter(SchemaAssets::isOrmAsset(...));
        $connection->getConfiguration()->setSchemaAssetsFilter(SchemaAssets::isOrmAsset(...));

        return new EntityManager($connection, $config);
    }
}
