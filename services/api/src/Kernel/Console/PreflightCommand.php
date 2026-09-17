<?php

declare(strict_types=1);

namespace Analytics\Kernel\Console;

use Analytics\Kernel\Settings;
use Analytics\Shared\Types;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:preflight', description: 'Checks PHP version, extensions, configuration, writable directories and the database')]
final class PreflightCommand extends Command
{
    public const array REQUIRED_EXTENSIONS = ['pdo_mysql', 'sodium', 'intl', 'mbstring', 'json'];
    public const array RECOMMENDED_EXTENSIONS = ['opcache'];

    public function __construct(private readonly Settings $settings, private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('skip-db', null, InputOption::VALUE_NONE, 'Do not connect to the database');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'JSON output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checks = [];
        $add = static function (string $name, bool $ok, string $detail, bool $required = true) use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'required' => $required, 'detail' => $detail];
        };

        $add('php_version', version_compare(\PHP_VERSION, '8.4.1', '>='), 'PHP ' . \PHP_VERSION . ' (>= 8.4.1 required)');
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $add('ext_' . $ext, \extension_loaded($ext), $ext);
        }
        foreach (self::RECOMMENDED_EXTENSIONS as $ext) {
            $add('ext_' . $ext, \extension_loaded($ext) || \extension_loaded('Zend OPcache'), $ext . ' (recommended)', false);
        }
        $add('argon2id', \defined('PASSWORD_ARGON2ID') || \function_exists('sodium_crypto_pwhash'), 'argon2id password hashing (password_hash or sodium fallback)');
        $add('app_url', filter_var($this->settings->appUrl, \FILTER_VALIDATE_URL) !== false, 'APP_URL=' . $this->settings->appUrl);
        $host = $this->settings->appHost();
        $isLoopback = \in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost');
        if ($this->settings->isProd() && !$isLoopback) {
            $add('app_url_https', $this->settings->usesHttps(), 'APP_URL uses https');
        } elseif ($this->settings->isProd()) {
            $add('app_url_https', $this->settings->usesHttps(), 'APP_URL is a loopback address (https not required)', false);
        }
        foreach ([$this->settings->cacheDir, $this->settings->logDir, $this->settings->storageDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            $add('writable:' . basename($dir), is_dir($dir) && is_writable($dir), $dir);
        }

        if ($input->getOption('skip-db') !== true) {
            try {
                $version = Types::string($this->connection->fetchOne('SELECT VERSION()'));
                $add('database', true, 'connected');
                $numeric = (string) preg_replace('/[^0-9.].*$/', '', $version);
                $add('mysql_version', version_compare($numeric, '8.4.0', '>=') || str_contains(strtolower($version), 'mariadb') === false && version_compare($numeric, '8.0.0', '>='), 'MySQL ' . $version . ' (8.4 recommended)', version_compare($numeric, '8.0.0', '>='));
            } catch (\Throwable $e) {
                $add('database', false, $e->getMessage());
            }
        }

        $failed = array_filter($checks, static fn(array $c): bool => $c['required'] && !$c['ok']);
        if ($input->getOption('json') === true) {
            $output->writeln(json_encode(['ok' => $failed === [], 'checks' => $checks], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $check) {
                $output->writeln(\sprintf('%s %-24s %s', $check['ok'] ? '[ok]  ' : ($check['required'] ? '[FAIL]' : '[warn]'), $check['name'], $check['detail']));
            }
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
