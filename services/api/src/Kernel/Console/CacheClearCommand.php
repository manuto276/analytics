<?php

declare(strict_types=1);

namespace Analytics\Kernel\Console;

use Analytics\Kernel\Settings;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cache:clear', description: 'Removes compiled caches and clears the application cache pool')]
final class CacheClearCommand extends Command
{
    public function __construct(private readonly Settings $settings, private readonly AdapterInterface $pool)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $this->settings->cacheDir;
        if (is_dir($dir)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                \assert($item instanceof \SplFileInfo);
                if ($item->getFilename() === '.gitkeep') {
                    continue;
                }
                $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        $this->pool->clear();
        $output->writeln('Cache cleared.');

        return self::SUCCESS;
    }
}
