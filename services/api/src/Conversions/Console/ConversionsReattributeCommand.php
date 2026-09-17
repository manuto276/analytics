<?php

declare(strict_types=1);

namespace Analytics\Conversions\Console;

use Analytics\Conversions\Application\AttributionResolver;
use Analytics\Reporting\Application\ReportCache;
use Analytics\Shared\Jobs\JobRunner;
use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteRepository;
use Analytics\Tracking\Application\DirtyDayMarker;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'conversions:reattribute', description: 'Recomputes the attribution snapshot of stored conversions')]
final class ConversionsReattributeCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AttributionResolver $attribution,
        private readonly SiteRepository $sites,
        private readonly JobRunner $jobs,
        private readonly ReportCache $cache,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Site id or public key')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Only conversions from this local day');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ref = Types::string($input->getOption('site'));
        $site = ctype_digit($ref) ? $this->sites->find((int) $ref) : $this->sites->findByPublicKey($ref);
        if ($site === null) {
            $output->writeln('<error>Site not found (use --site).</error>');

            return self::INVALID;
        }
        $from = Types::string($input->getOption('from'), '1970-01-01');

        $result = $this->jobs->run('conversions:reattribute', function () use ($site, $from): array {
            $updated = 0;
            $lastId = 0;
            do {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT id, local_day, visitor_id, customer_ref, occurred_at, attr_channel FROM conversions
                      WHERE site_id = ? AND local_day >= ? AND id > ? ORDER BY id LIMIT 500',
                    [$site->id(), $from, $lastId],
                );
                foreach ($rows as $row) {
                    $lastId = Types::int($row['id']);
                    $attribution = $this->attribution->resolve(
                        $site->id(),
                        Types::nullableString($row['visitor_id']),
                        Types::nullableString($row['customer_ref']),
                        new \DateTimeImmutable(Types::string($row['occurred_at']), new \DateTimeZone('UTC')),
                    );
                    $this->connection->update('conversions', $attribution, ['id' => $lastId]);
                    if ($attribution['attr_channel'] !== $row['attr_channel']) {
                        DirtyDayMarker::mark($this->connection, $site->id(), Types::string($row['local_day']), $this->clock->now());
                    }
                    ++$updated;
                }
            } while (\count($rows) === 500);
            $this->cache->invalidateSite($site->id());

            return ['conversions' => $updated];
        }, 3600);

        $output->writeln(\sprintf('conversions:reattribute %s (%s)', $result['status'], json_encode($result['stats'], \JSON_THROW_ON_ERROR)));

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
