<?php

declare(strict_types=1);

namespace Analytics\Kernel\Console;

use Analytics\Kernel\AppFactory;
use Analytics\Kernel\ContainerFactory;
use Analytics\Kernel\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:warmup', description: 'Compiles the container, routes and Doctrine metadata')]
final class CacheWarmupCommand extends Command
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ContainerInterface $container,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!is_dir($this->settings->cacheDir)) {
            mkdir($this->settings->cacheDir, 0750, true);
        }
        // Compiled container (prod only) and route collector.
        $container = $this->settings->isProd() ? ContainerFactory::create($this->settings) : $this->container;
        AppFactory::create($container);
        $count = \count($this->entityManager->getMetadataFactory()->getAllMetadata());
        $output->writeln(\sprintf('Cache warmed up (%s, %d entities).', $this->settings->env, $count));

        return self::SUCCESS;
    }
}
