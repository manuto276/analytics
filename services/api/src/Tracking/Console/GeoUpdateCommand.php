<?php

declare(strict_types=1);

namespace Analytics\Tracking\Console;

use Analytics\Kernel\Settings;
use Analytics\Shared\Jobs\JobRunner;
use MaxMind\Db\Reader;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'geo:update', description: 'Downloads the DB-IP Lite Country database (CC BY 4.0) and swaps it in atomically')]
final class GeoUpdateCommand extends Command
{
    public function __construct(private readonly Settings $settings, private readonly JobRunner $jobs, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Download even if the current file is from this month')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Override the download URL (%s = YYYY-MM)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $this->settings->geoDbPath;
        $now = $this->clock->now();
        if ($input->getOption('force') !== true && is_file($target) && date('Y-m', (int) filemtime($target)) === $now->format('Y-m')) {
            $output->writeln('Geo database is current (use --force to download again).');

            return self::SUCCESS;
        }
        $urlOption = $input->getOption('url');
        $template = \is_string($urlOption) && $urlOption !== '' ? $urlOption : $this->settings->geoDbUrl;

        $result = $this->jobs->run('geo:update', function () use ($template, $target, $now): array {
            $dir = \dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create ' . $dir);
            }
            $errors = [];
            foreach ([$now, $now->modify('first day of previous month')] as $month) {
                $url = \sprintf($template, $month->format('Y-m'));
                $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'analytics-geo-update']]));
                if (!\is_string($data) || $data === '') {
                    $errors[] = $url;
                    continue;
                }
                if (str_ends_with((string) parse_url($url, \PHP_URL_PATH), '.gz')) {
                    $data = @gzdecode($data);
                    if (!\is_string($data)) {
                        throw new \RuntimeException('Downloaded file is not gzip: ' . $url);
                    }
                }
                $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
                file_put_contents($tmp, $data);
                try {
                    $reader = new Reader($tmp);
                    $meta = $reader->metadata();
                    $reader->close();
                } catch (\Throwable $e) {
                    @unlink($tmp);

                    throw new \RuntimeException('Invalid database: ' . $e->getMessage(), 0, $e);
                }
                chmod($tmp, 0640);
                if (!rename($tmp, $target)) {
                    @unlink($tmp);

                    throw new \RuntimeException('Cannot move database into place.');
                }

                return ['url' => $url, 'bytes' => \strlen($data), 'build_epoch' => $meta->buildEpoch, 'database_type' => $meta->databaseType];
            }

            throw new \RuntimeException('Download failed: ' . implode(', ', $errors));
        }, 900);

        $output->writeln('geo:update ' . $result['status'] . ($result['message'] !== null ? ': ' . $result['message'] : ''));

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
