<?php

declare(strict_types=1);

namespace Analytics\Sites\Console;

use Analytics\Audit\Application\Actor;
use Analytics\Audit\Application\AuditLogger;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteService;
use Analytics\Sites\Application\SnippetRenderer;
use Analytics\Sites\Domain\VisitorHashMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'site:create', description: 'Creates a site and prints its public key and snippet')]
final class SiteCreateCommand extends Command
{
    public function __construct(private readonly SiteService $sites, private readonly SnippetRenderer $snippets, private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Site name')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Domain; prefix with "*." to include subdomains')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'IANA time zone', 'UTC')
            ->addOption('hash-mode', null, InputOption::VALUE_REQUIRED, 'daily_hash | pageviews_only', 'daily_hash')
            ->addOption('cookie-domain', null, InputOption::VALUE_REQUIRED, 'Cookie domain for the cookie level')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'ISO 4217 currency', 'EUR');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $rawDomains */
        $rawDomains = $input->getOption('domain');
        $domains = [];
        foreach ($rawDomains as $domain) {
            // SiteService parses the "*." prefix (DomainMatcher::parseEntry), exactly as for the API.
            $domains[] = ['host' => $domain, 'include_subdomains' => false];
        }
        $mode = VisitorHashMode::tryFrom(Types::string($input->getOption('hash-mode')));
        if ($mode === null) {
            $output->writeln('<error>--hash-mode must be daily_hash or pageviews_only.</error>');

            return self::INVALID;
        }
        try {
            $cookieDomain = $input->getOption('cookie-domain');
            $site = $this->sites->create(Types::string($input->getOption('name')), $domains, Types::string($input->getOption('timezone')), $mode, \is_string($cookieDomain) ? $cookieDomain : null, Types::string($input->getOption('currency')));
        } catch (ApiProblem $e) {
            foreach ($e->errors as $field => $messages) {
                $output->writeln(\sprintf('<error>%s: %s</error>', $field, implode(' ', $messages)));
            }

            return self::FAILURE;
        }
        $this->audit->log('site.created', Actor::console(), $site->id(), 'site', $site->id());
        $snippet = $this->snippets->render($site->publicKey, $site->trackerGlobal);
        $output->writeln(\sprintf('Site "%s" created (id %d).', $site->name, $site->id()));
        $output->writeln('Public key: ' . $site->publicKey);
        $output->writeln('Snippet:');
        $output->writeln($snippet['html'], OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
