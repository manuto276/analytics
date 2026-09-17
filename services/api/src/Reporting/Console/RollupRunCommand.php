<?php

declare(strict_types=1);

namespace Analytics\Reporting\Console;

use Analytics\Reporting\Application\ReportCache;
use Analytics\Reporting\Application\Rollup\RollupRunner;
use Analytics\Shared\Jobs\JobRunner;
use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rollup:run', description: 'Rebuilds the rollups of the days marked dirty by ingestion')]
final class RollupRunCommand extends Command
{
    public function __construct(
        private readonly RollupRunner $runner,
        private readonly JobRunner $jobs,
        private readonly SiteRepository $sites,
        private readonly ReportCache $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Site id or public key')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum days per run', (string) RollupRunner::BATCH);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $siteId = null;
        $site = $input->getOption('site');
        if (\is_string($site) && $site !== '') {
            $entity = ctype_digit($site) ? $this->sites->find((int) $site) : $this->sites->findByPublicKey($site);
            if ($entity === null) {
                $output->writeln('<error>Site not found.</error>');

                return self::FAILURE;
            }
            $siteId = $entity->id();
        }
        $limit = max(1, Types::int($input->getOption('limit'), RollupRunner::BATCH));

        $result = $this->jobs->run('rollup:run', function () use ($siteId, $limit): array {
            $stats = $this->runner->runDirty($siteId, $limit);
            if ($siteId !== null) {
                $this->cache->invalidateSite($siteId);
            }

            return $stats;
        }, 1800);

        $output->writeln(\sprintf('rollup:run %s (%s)', $result['status'], json_encode($result['stats'], \JSON_THROW_ON_ERROR)), OutputInterface::VERBOSITY_VERBOSE);

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
