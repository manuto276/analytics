<?php

declare(strict_types=1);

namespace Analytics\Tests\Support\Scenario;

use Analytics\Reporting\Application\Rollup\RollupRunner;
use Analytics\Sites\Application\SiteSnapshot;
use Analytics\Sites\Domain\Site;
use Analytics\Tests\Support\Factory;
use Analytics\Tracking\Application\Seeder;
use DI\Container;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deterministic multi-channel dataset shared by integration, report and load tests.
 */
final class SeededDataset
{
    public const int DAYS = 40;
    public const int VISITS_PER_DAY = 12;
    public const int SEED = 424242;

    public Site $site;
    public SiteSnapshot $snapshot;
    /** @var array{visits: int, events: int, conversions: int} */
    public array $stats;

    public function __construct(private readonly Container $container, Factory $factory, \DateTimeImmutable $lastDay, int $days = self::DAYS, int $visitsPerDay = self::VISITS_PER_DAY)
    {
        $this->site = $factory->site([
            'cookieLevelEnabled' => true,
            'contentContactEvents' => ['contact_form'],
            'timezone' => 'Europe/Rome',
        ], ['www.example.com']);
        $this->snapshot = SiteSnapshot::fromSite($this->site);

        $seeder = $this->container->get(Seeder::class);
        \assert($seeder instanceof Seeder);
        $this->stats = $seeder->seed($this->snapshot, $lastDay->setTimezone($this->snapshot->timezone())->setTime(0, 0), $days, $visitsPerDay, self::SEED);

        $em = $this->container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
    }

    public function buildRollups(): void
    {
        $runner = $this->container->get(RollupRunner::class);
        \assert($runner instanceof RollupRunner);
        do {
            $result = $runner->runDirty($this->site->id());
        } while ($result['days'] > 0);
    }
}
