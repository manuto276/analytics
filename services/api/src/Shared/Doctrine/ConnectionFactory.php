<?php

declare(strict_types=1);

namespace Analytics\Shared\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

final class ConnectionFactory
{
    public static function create(string $databaseUrl): Connection
    {
        $params = new DsnParser(['mysql' => 'pdo_mysql', 'mysqli' => 'pdo_mysql'])->parse($databaseUrl);
        $params['charset'] = 'utf8mb4';
        $params['driverOptions'] = [
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
            \Pdo\Mysql::ATTR_INIT_COMMAND => "SET time_zone = '+00:00', SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'",
        ];
        $params['serverVersion'] = '8.4.0';

        $config = new \Doctrine\DBAL\Configuration();
        $config->setSchemaAssetsFilter(static fn(string|\Doctrine\DBAL\Schema\AbstractAsset $asset): bool => true);

        return DriverManager::getConnection($params, $config);
    }
}
