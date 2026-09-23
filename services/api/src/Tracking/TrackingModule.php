<?php

declare(strict_types=1);

namespace Analytics\Tracking;

use Analytics\Kernel\Module;
use Analytics\Kernel\Settings;
use Analytics\Tracking\Application\DailySaltProvider;
use Analytics\Tracking\Application\Enrichment\ReferrerClassifier;
use Analytics\Tracking\Application\Enrichment\UserAgentClassifier;
use Analytics\Tracking\Application\EventSink;
use Analytics\Tracking\Application\GeoLocator;
use Analytics\Tracking\Application\ScriptBundleBuilder;
use Analytics\Tracking\Http\TrackingController;
use Analytics\Tracking\Infrastructure\DbalDailySaltProvider;
use Analytics\Tracking\Infrastructure\MaxMindGeoLocator;
use Analytics\Tracking\Infrastructure\RedisDailySaltProvider;
use Analytics\Tracking\Infrastructure\RedisQueueEventSink;
use Analytics\Tracking\Infrastructure\SyncEventSink;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\Interfaces\RouteCollectorProxyInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;

final class TrackingModule extends Module
{
    public function definitions(Settings $settings): array
    {
        return [
            ReferrerClassifier::class => factory(static fn(Settings $s): ReferrerClassifier => new ReferrerClassifier($s->projectDir . '/resources/referrers')),
            UserAgentClassifier::class => factory(static function (Settings $s): UserAgentClassifier {
                $cache = null;
                if ($s->isProd() && class_exists(\DeviceDetector\Cache\PSR6Bridge::class)) {
                    $cache = new \DeviceDetector\Cache\PSR6Bridge(new \Symfony\Component\Cache\Adapter\PhpFilesAdapter('device_detector', 0, $s->cacheDir));
                }

                return new UserAgentClassifier($cache);
            }),
            GeoLocator::class => factory(static fn(Settings $s): GeoLocator => new MaxMindGeoLocator($s->geoDbPath)),
            DailySaltProvider::class => factory(static function (Settings $s, ContainerInterface $c): DailySaltProvider {
                $redis = $c->get('redis');
                if ($redis instanceof \Predis\ClientInterface) {
                    return new RedisDailySaltProvider($redis);
                }
                $provider = $c->get(DbalDailySaltProvider::class);
                \assert($provider instanceof DailySaltProvider);

                return $provider;
            }),
            RedisQueueEventSink::class => factory(static function (ContainerInterface $c): RedisQueueEventSink {
                $redis = $c->get('redis');
                if (!$redis instanceof \Predis\ClientInterface) {
                    throw new \RuntimeException('INGEST_MODE=queue requires REDIS_DSN.');
                }

                return new RedisQueueEventSink($redis);
            }),
            EventSink::class => factory(static function (Settings $s, ContainerInterface $c): EventSink {
                $sink = $c->get($s->ingestMode === 'queue' ? RedisQueueEventSink::class : SyncEventSink::class);
                \assert($sink instanceof EventSink);

                return $sink;
            }),
            ScriptBundleBuilder::class => autowire()
                ->constructorParameter('trackerPath', $settings->projectDir . '/resources/tracker/tracker.js')
                ->constructorParameter('bannerPath', $settings->projectDir . '/resources/tracker/banner.js')
                ->constructorParameter('sourceUrl', $settings->sourceUrl)
                ->constructorParameter('logger', get(LoggerInterface::class))
                ->constructorParameter('strict', $settings->isProd()),
        ];
    }

    /** @param RouteCollectorProxyInterface<\Psr\Container\ContainerInterface|null> $group */
    public function trackingRoutes(RouteCollectorProxyInterface $group): void
    {
        $group->get('/{publicKey:pk_[A-Za-z0-9]{21}}.js', [TrackingController::class, 'script']);
        $group->post('/e', [TrackingController::class, 'collect']);
        $group->options('/e', [TrackingController::class, 'preflight']);
        $group->post('/forget', [TrackingController::class, 'forget']);
        $group->options('/forget', [TrackingController::class, 'preflight']);
    }

    public function commands(): array
    {
        return [
            Console\SaltRotateCommand::class,
            Console\GeoUpdateCommand::class,
            Console\GeoLookupCommand::class,
            Console\QueueWorkCommand::class,
        ];
    }
}
