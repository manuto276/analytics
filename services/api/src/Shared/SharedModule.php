<?php

declare(strict_types=1);

namespace Analytics\Shared;

use Analytics\Kernel\BuildInfo;
use Analytics\Kernel\Module;
use Analytics\Kernel\Modules;
use Analytics\Kernel\Settings;
use Analytics\Shared\Clock\ClockFactory;
use Analytics\Shared\Crypto\KeyDerivation;
use Analytics\Shared\Crypto\SecretBox;
use Analytics\Shared\Doctrine\ConnectionFactory;
use Analytics\Shared\Doctrine\EntityManagerFactory;
use Analytics\Shared\Http\JsonResponder;
use Analytics\Shared\Http\Middleware\SameOriginMiddleware;
use Analytics\Shared\Http\Middleware\SecurityHeadersMiddleware;
use Analytics\Shared\Http\ProblemDetailsErrorHandler;
use Analytics\Shared\Log\IpScrubbingProcessor;
use Analytics\Shared\Net\ClientIpResolver;
use Analytics\Shared\RateLimit\RateLimiter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\RedisStore;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function DI\autowire;
use function DI\factory;

final class SharedModule extends Module
{
    public function definitions(Settings $settings): array
    {
        return [
            BuildInfo::class => factory(static fn(Settings $s): BuildInfo => BuildInfo::load($s->projectDir)),
            ClockInterface::class => factory(static fn(Settings $s): ClockInterface => ClockFactory::create($s->testClock)),
            ResponseFactoryInterface::class => autowire(ResponseFactory::class),
            JsonResponder::class => autowire(),
            LoggerInterface::class => factory(static function (Settings $s): LoggerInterface {
                $logger = new Logger('analytics');
                $level = match (strtolower($s->logLevel)) {
                    'debug' => Level::Debug,
                    'info' => Level::Info,
                    'notice' => Level::Notice,
                    'error' => Level::Error,
                    'critical' => Level::Critical,
                    default => Level::Warning,
                };
                if (\PHP_SAPI === 'cli' && $s->env !== 'test') {
                    $logger->pushHandler(new StreamHandler('php://stderr', Level::Warning));
                }
                if (is_dir($s->logDir) || @mkdir($s->logDir, 0750, true)) {
                    $handler = new StreamHandler($s->logDir . '/app-' . $s->env . '.log', $level);
                    $handler->setFormatter(new JsonFormatter());
                    $logger->pushHandler($handler);
                }
                $logger->pushProcessor(new PsrLogMessageProcessor());
                $logger->pushProcessor(new IpScrubbingProcessor());

                return $logger;
            }),
            ProblemDetailsErrorHandler::class => factory(static fn(JsonResponder $r, LoggerInterface $l, Settings $s): ProblemDetailsErrorHandler => new ProblemDetailsErrorHandler($r, $l, !$s->isProd())),
            Connection::class => factory(static fn(Settings $s): Connection => ConnectionFactory::create($s->databaseUrl)),
            EntityManagerInterface::class => factory(static function (Connection $c, Settings $s): EntityManagerInterface {
                $paths = [];
                foreach (Modules::all() as $module) {
                    array_push($paths, ...$module->entityPaths());
                }

                return EntityManagerFactory::create($c, $s, $paths);
            }),
            'redis' => factory(static function (Settings $s): ?\Predis\ClientInterface {
                if ($s->redisDsn === null) {
                    return null;
                }

                return new \Predis\Client($s->redisDsn, ['prefix' => 'an:', 'exceptions' => true]);
            }),
            AdapterInterface::class => factory(static function (Settings $s, Connection $c, ContainerInterface $container): AdapterInterface {
                if ($s->redisDsn !== null) {
                    return new RedisAdapter(RedisAdapter::createConnection($s->redisDsn, ['class' => \Predis\Client::class]), 'cache', 0);
                }
                if ($s->isTest()) {
                    return new ArrayAdapter();
                }

                return new DoctrineDbalAdapter($c, 'app', 0, ['db_table' => 'cache_items']);
            }),
            TagAwareAdapterInterface::class => factory(static fn(AdapterInterface $pool): TagAwareAdapterInterface => new TagAwareAdapter($pool)),
            LockFactory::class => factory(static function (Settings $s): LockFactory {
                if ($s->redisDsn !== null) {
                    return new LockFactory(new RedisStore(RedisAdapter::createConnection($s->redisDsn, ['class' => \Predis\Client::class])));
                }
                $dir = $s->storageDir . '/locks';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0750, true);
                }

                return new LockFactory(new FlockStore($dir));
            }),
            RateLimiter::class => factory(static function (AdapterInterface $pool, Settings $s): RateLimiter {
                return new RateLimiter(new CacheStorage($pool), [], true);
            }),
            SecretBox::class => factory(static fn(Settings $s): SecretBox => new SecretBox($s->encryptionKeys)),
            KeyDerivation::class => factory(static fn(Settings $s): KeyDerivation => new KeyDerivation($s->hmacSecret)),
            ClientIpResolver::class => factory(static fn(Settings $s): ClientIpResolver => new ClientIpResolver($s->trustedProxies)),
            SameOriginMiddleware::class => factory(static fn(Settings $s): SameOriginMiddleware => new SameOriginMiddleware($s->appOrigin())),
            SecurityHeadersMiddleware::class => factory(static function (Settings $s): SecurityHeadersMiddleware {
                $hashes = [];
                $file = $s->projectDir . '/config/csp.php';
                if (is_file($file)) {
                    /** @var mixed $loaded */
                    $loaded = require $file;
                    if (\is_array($loaded) && \is_array($loaded['script_hashes'] ?? null)) {
                        $hashes = array_values(array_filter($loaded['script_hashes'], 'is_string'));
                    }
                }

                return new SecurityHeadersMiddleware($s->usesHttps(), $hashes);
            }),
        ];
    }
}
