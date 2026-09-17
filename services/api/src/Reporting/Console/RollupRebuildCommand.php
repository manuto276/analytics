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

#[AsCommand(name: 'rollup:rebuild', description: 'Rebuilds rollups for a date range (use --recompute-days after a time zone change)')]
final class RollupRebuildCommand extends Command
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
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First local day (YYYY-MM-DD)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last local day (YYYY-MM-DD)')
            ->addOption('recompute-days', null, InputOption::VALUE_NONE, 'Recalculate local_day of raw rows from the site time zone first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $siteRef = Types::string($input->getOption('site'));
        $site = ctype_digit($siteRef) ? $this->sites->find((int) $siteRef) : $this->sites->findByPublicKey($siteRef);
        if ($site === null) {
            $output->writeln('<error>Site not found (use --site).</error>');

            return self::INVALID;
        }
        $from = Types::string($input->getOption('from'));
        $to = Types::string($input->getOption('to'));
        foreach ([$from, $to] as $day) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
                $output->writeln('<error>--from and --to must be dates (YYYY-MM-DD).</error>');

                return self::INVALID;
            }
        }
        $recompute = $input->getOption('recompute-days') === true;

        $result = $this->jobs->run('rollup:rebuild', function () use ($site, $from, $to, $recompute): array {
            $stats = $this->runner->rebuild($site->id(), new \DateTimeImmutable($from), new \DateTimeImmutable($to), $recompute);
            $this->cache->invalidateSite($site->id());

            return $stats;
        }, 7200);

        $output->writeln(\sprintf('rollup:rebuild %s (%s)', $result['status'], json_encode($result['stats'], \JSON_THROW_ON_ERROR)));

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
