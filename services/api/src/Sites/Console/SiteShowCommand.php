<?php

declare(strict_types=1);

namespace Analytics\Sites\Console;

use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteRepository;
use Analytics\Sites\Application\SiteService;
use Analytics\Sites\Application\SnippetRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'site:show', description: 'Shows a site (id or public key) as JSON with its snippet')]
final class SiteShowCommand extends Command
{
    public function __construct(private readonly SiteRepository $sites, private readonly SnippetRenderer $snippets)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::REQUIRED, 'Site id or public key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ref = Types::string($input->getArgument('site'));
        $site = ctype_digit($ref) ? $this->sites->find((int) $ref) : $this->sites->findByPublicKey($ref);
        if ($site === null) {
            $output->writeln('<error>Site not found.</error>');

            return self::FAILURE;
        }
        $data = SiteService::toArray($site) + ['snippet' => $this->snippets->render($site->publicKey, $site->trackerGlobal)['html']];
        $output->writeln(json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
