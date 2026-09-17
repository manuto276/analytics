<?php

declare(strict_types=1);

namespace Analytics\Kernel\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'secrets:generate', description: 'Prints fresh APP_SECRET, APP_ENCRYPTION_KEYS and OPS_TOKEN values for .env')]
final class SecretsGenerateCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('APP_SECRET=' . base64_encode(random_bytes(32)));
        $output->writeln('APP_ENCRYPTION_KEYS=k' . date('ymd') . ':' . base64_encode(random_bytes(32)));
        $output->writeln('OPS_TOKEN=' . bin2hex(random_bytes(24)));

        return self::SUCCESS;
    }
}
