<?php

declare(strict_types=1);

namespace Analytics\Kernel;

/**
 * Immutable runtime settings built from environment variables.
 */
final readonly class Settings
{
    public const array INGEST_MODES = ['sync', 'queue'];

    /**
     * @param list<string>          $trustedProxies CIDR ranges or addresses
     * @param array<string, string> $encryptionKeys key id => raw 32-byte key (last entry is the active key)
     */
    public function __construct(
        public string $env,
        public string $appUrl,
        public string $projectDir,
        public string $databaseUrl,
        public ?string $redisDsn,
        public string $redisPrefix,
        public array $trustedProxies,
        public string $ingestMode,
        public bool $dbPartitioning,
        public string $logLevel,
        public string $hmacSecret,
        public array $encryptionKeys,
        public ?string $mailerDsn,
        public string $mailFrom,
        public string $storageDir,
        public string $logDir,
        public string $cacheDir,
        public string $geoDbPath,
        public string $geoDbUrl,
        public string $sourceUrl,
        public ?string $opsToken,
        public ?string $testClock,
        public int $retentionMonths,
    ) {}

    /**
     * @param array<string, mixed> $env
     */
    public static function fromEnvironment(array $env, string $projectDir): self
    {
        $get = static function (string $key, ?string $default = null) use ($env): ?string {
            $value = $env[$key] ?? null;
            if (!\is_string($value) || $value === '') {
                return $default;
            }

            return $value;
        };

        $appEnv = $get('APP_ENV', 'prod') ?? 'prod';
        if (!\in_array($appEnv, ['prod', 'dev', 'test'], true)) {
            throw new \InvalidArgumentException(\sprintf('APP_ENV must be prod, dev or test, got "%s".', $appEnv));
        }

        $databaseUrl = $get('DATABASE_URL');
        if ($databaseUrl === null) {
            $host = $get('DB_HOST', '127.0.0.1');
            $port = $get('DB_PORT', '3306');
            $name = $get('DB_NAME', 'analytics');
            $user = rawurlencode($get('DB_USER', 'analytics') ?? '');
            $pass = rawurlencode($get('DB_PASSWORD', '') ?? '');
            $databaseUrl = \sprintf('mysql://%s:%s@%s:%s/%s', $user, $pass, $host, $port, $name);
        }

        $ingestMode = $get('INGEST_MODE', 'sync') ?? 'sync';
        if (!\in_array($ingestMode, self::INGEST_MODES, true)) {
            throw new \InvalidArgumentException('INGEST_MODE must be sync or queue.');
        }

        $secret = $get('APP_SECRET');
        if ($secret === null) {
            if ($appEnv === 'prod') {
                throw new \InvalidArgumentException('APP_SECRET is required (run: bin/analytics secrets:generate).');
            }
            $secret = base64_encode(str_repeat("\x01", 32));
        }
        $hmacSecret = self::decodeKey($secret, 'APP_SECRET');

        $keys = [];
        $keySpec = $get('APP_ENCRYPTION_KEYS');
        if ($keySpec === null) {
            if ($appEnv === 'prod') {
                throw new \InvalidArgumentException('APP_ENCRYPTION_KEYS is required (run: bin/analytics secrets:generate).');
            }
            $keySpec = 'k1:' . base64_encode(str_repeat("\x02", 32));
        }
        foreach (explode(',', $keySpec) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $parts = explode(':', $pair, 2);
            if (\count($parts) !== 2 || preg_match('/^[a-z0-9]{1,8}$/', $parts[0]) !== 1) {
                throw new \InvalidArgumentException('APP_ENCRYPTION_KEYS must be "id:base64key[,id:base64key]" with ids [a-z0-9]{1,8}.');
            }
            $keys[$parts[0]] = self::decodeKey($parts[1], 'APP_ENCRYPTION_KEYS');
        }
        if ($keys === []) {
            throw new \InvalidArgumentException('APP_ENCRYPTION_KEYS contains no key.');
        }

        $proxies = array_values(array_filter(array_map('trim', explode(',', $get('TRUSTED_PROXIES', '') ?? '')), static fn(string $p): bool => $p !== ''));

        $storageDir = $get('STORAGE_DIR', $projectDir . '/var/storage') ?? $projectDir . '/var/storage';

        return new self(
            env: $appEnv,
            appUrl: rtrim($get('APP_URL', 'http://localhost:8080') ?? '', '/'),
            projectDir: $projectDir,
            databaseUrl: $databaseUrl,
            redisDsn: $get('REDIS_DSN'),
            redisPrefix: rtrim($get('REDIS_PREFIX', 'an') ?? 'an', ':') . ':',
            trustedProxies: $proxies,
            ingestMode: $ingestMode,
            dbPartitioning: filter_var($get('DB_PARTITIONING', 'true'), \FILTER_VALIDATE_BOOL),
            logLevel: $get('LOG_LEVEL', $appEnv === 'prod' ? 'warning' : 'debug') ?? 'warning',
            hmacSecret: $hmacSecret,
            encryptionKeys: $keys,
            mailerDsn: $get('MAILER_DSN'),
            mailFrom: $get('MAIL_FROM', 'analytics@localhost') ?? 'analytics@localhost',
            storageDir: $storageDir,
            logDir: $get('LOG_DIR', $projectDir . '/var/log') ?? $projectDir . '/var/log',
            cacheDir: $get('CACHE_DIR', $projectDir . '/var/cache') ?? $projectDir . '/var/cache',
            geoDbPath: $get('GEO_DB_PATH', $storageDir . '/geo/dbip-country-lite.mmdb') ?? '',
            geoDbUrl: $get('GEO_DB_URL', 'https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz') ?? '',
            sourceUrl: $get('SOURCE_URL', 'https://github.com/manuto276/analytics') ?? '',
            opsToken: $get('OPS_TOKEN'),
            testClock: $appEnv === 'test' ? $get('APP_TEST_CLOCK') : null,
            retentionMonths: max(1, (int) ($get('RETENTION_MONTHS', '13') ?? '13')),
        );
    }

    public function isProd(): bool
    {
        return $this->env === 'prod';
    }

    public function isTest(): bool
    {
        return $this->env === 'test';
    }

    public function appHost(): string
    {
        return strtolower((string) parse_url($this->appUrl, \PHP_URL_HOST));
    }

    public function appOrigin(): string
    {
        $parts = parse_url($this->appUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'localhost';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($scheme . '://' . $host . $port);
    }

    public function usesHttps(): bool
    {
        return str_starts_with($this->appUrl, 'https://');
    }

    private static function decodeKey(string $value, string $name): string
    {
        $raw = base64_decode($value, true);
        if ($raw === false || \strlen($raw) !== 32) {
            throw new \InvalidArgumentException(\sprintf('%s keys must be base64-encoded 32-byte values.', $name));
        }

        return $raw;
    }
}
