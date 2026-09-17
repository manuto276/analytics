<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application\Enrichment;

use DeviceDetector\ClientHints;
use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use Jaybizzle\CrawlerDetect\CrawlerDetect;

/**
 * Reduces a User-Agent to browser/OS family + major version and a device class. The full UA is
 * only held in memory; results are memoised per process by a hash of the UA.
 */
final class UserAgentClassifier
{
    private const int MEMO_SIZE = 500;

    /** @var array<string, UserAgentInfo> */
    private array $memo = [];
    private ?CrawlerDetect $crawlers = null;

    public function __construct(private readonly ?\DeviceDetector\Cache\CacheInterface $cache = null) {}

    public function classify(string $userAgent, ?int $screenWidth = null): UserAgentInfo
    {
        $key = hash('xxh128', $userAgent) . ':' . ($screenWidth === null ? '' : ($screenWidth < 768 ? 's' : ($screenWidth < 1024 ? 'm' : 'l')));
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        if (\count($this->memo) >= self::MEMO_SIZE) {
            $this->memo = [];
        }

        return $this->memo[$key] = $this->detect($userAgent, $screenWidth);
    }

    private function detect(string $userAgent, ?int $screenWidth): UserAgentInfo
    {
        if (trim($userAgent) === '') {
            return new UserAgentInfo(true, null, null, null, null, 'other');
        }
        $this->crawlers ??= new CrawlerDetect();
        if ($this->crawlers->isCrawler($userAgent)) {
            return new UserAgentInfo(true, null, null, null, null, 'other');
        }

        AbstractDeviceParser::setVersionTruncation(AbstractDeviceParser::VERSION_TRUNCATION_MAJOR);
        $dd = new DeviceDetector($userAgent, ClientHints::factory([]));
        if ($this->cache !== null) {
            $dd->setCache($this->cache);
        }
        $dd->discardBotInformation();
        $dd->parse();
        if ($dd->isBot()) {
            return new UserAgentInfo(true, null, null, null, null, 'other');
        }

        $browser = $dd->getClient('name');
        $browserVersion = $dd->getClient('version');
        $os = $dd->getOs('name');
        $osVersion = $dd->getOs('version');
        $device = match (true) {
            $dd->isTablet() => 'tablet',
            $dd->isSmartphone() || $dd->isFeaturePhone() || $dd->isPhablet() => 'mobile',
            $dd->isDesktop() => 'desktop',
            default => 'other',
        };
        if ($device === 'other' && $screenWidth !== null && $screenWidth > 0) {
            $device = $screenWidth < 768 ? 'mobile' : ($screenWidth < 1024 ? 'tablet' : 'desktop');
        }

        return new UserAgentInfo(
            isBot: false,
            browser: \is_string($browser) && $browser !== '' && $browser !== 'UNK' ? mb_substr($browser, 0, 32) : null,
            browserMajor: self::major($browserVersion),
            os: \is_string($os) && $os !== '' && $os !== 'UNK' ? mb_substr($os, 0, 32) : null,
            osMajor: self::major($osVersion),
            device: $device,
        );
    }

    private static function major(mixed $version): ?int
    {
        if (!\is_string($version) || preg_match('/^(\d{1,4})/', $version, $m) !== 1) {
            return null;
        }

        return min(65535, (int) $m[1]);
    }
}
