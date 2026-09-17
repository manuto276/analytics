<?php

declare(strict_types=1);

namespace Analytics\Kernel\Console;

use Analytics\Shared\Doctrine\SchemaAssets;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'orm:validate-schema', description: 'Validates the ORM mapping and that ORM tables match it (DBAL-only tables are ignored)')]
final class OrmValidateSchemaCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = new SchemaValidator($this->em)->validateMapping();
        foreach ($errors as $class => $messages) {
            foreach ($messages as $message) {
                $output->writeln(\sprintf('<error>%s: %s</error>', $class, $message));
            }
        }

        $sql = self::pendingSql($this->em);
        foreach ($sql as $statement) {
            $output->writeln('  ' . $statement);
        }
        if ($errors === [] && $sql === []) {
            $output->writeln('[OK] Mapping is valid and ORM tables are in sync.');

            return self::SUCCESS;
        }
        $output->writeln($sql === [] ? '[ERROR] Mapping is invalid.' : '[ERROR] ORM tables are not in sync with the mapping.');

        return self::FAILURE;
    }

    /** @return list<string> */
    public static function pendingSql(EntityManagerInterface $em): array
    {
        $config = $em->getConnection()->getConfiguration();
        $previous = $config->getSchemaAssetsFilter();
        $config->setSchemaAssetsFilter(static fn(string $asset): bool => SchemaAssets::isOrmAsset($asset) && !str_ends_with($asset, 'doctrine_migration_versions'));
        try {
            return array_values(new SchemaTool($em)->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()));
        } finally {
            $config->setSchemaAssetsFilter($previous);
        }
    }
}
