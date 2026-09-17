<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application\Enrichment;

/**
 * Normalises page URLs: lowercase host, allow-listed query parameters only, no click ids,
 * no fragment (unless hash routing), PII scrubbed, bounded lengths.
 */
final class UrlSanitizer
{
    public const array CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'dclid', 'msclkid', 'fbclid', 'ttclid', 'twclid', 'li_fat_id', 'yclid', 'igshid', 'mc_eid', '_hsenc', '_hsmi', 'epik', 'rdt_cid', 'sccid', 'srsltid'];
    public const int MAX_PATH = 1024;
    public const int MAX_QUERY = 1024;

    /**
     * @param list<string> $allowedParams
     *
     * @return array{host: string, path: string, query: ?string, params: array<string, string>}|null
     *         params holds all raw query parameters (lowercased keys) for UTM/click-id detection
     */
    public static function sanitize(string $url, array $allowedParams, bool $hashRouting): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        if ($hashRouting && isset($parts['fragment']) && str_starts_with($parts['fragment'], '/')) {
            $fragmentPath = explode('?', $parts['fragment'], 2)[0];
            $path = rtrim($path, '/') . '/#' . $fragmentPath;
        }
        $path = PiiScrubber::scrubPath(self::decodeUnreserved($path));
        if (mb_strlen($path) > self::MAX_PATH) {
            $path = mb_substr($path, 0, self::MAX_PATH);
        }

        $params = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            foreach (explode('&', $parts['query']) as $pair) {
                if ($pair === '') {
                    continue;
                }
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $key = strtolower(urldecode($k));
                if ($key === '' || isset($params[$key])) {
                    continue;
                }
                $params[$key] = mb_substr(urldecode($v), 0, 512);
            }
        }

        $allowed = array_map('strtolower', $allowedParams);
        $kept = [];
        foreach ($params as $key => $value) {
            if (\in_array($key, self::CLICK_IDS, true) || !\in_array($key, $allowed, true)) {
                continue;
            }
            $kept[$key] = PiiScrubber::scrub(mb_substr(trim($value), 0, 100));
        }
        ksort($kept);
        $query = $kept === [] ? null : http_build_query($kept, '', '&', \PHP_QUERY_RFC3986);
        if ($query !== null && \strlen($query) > self::MAX_QUERY) {
            $query = substr($query, 0, self::MAX_QUERY);
        }

        return ['host' => $host, 'path' => $path, 'query' => $query, 'params' => $params];
    }

    /** Stable 8-byte identifier of a page (host + path). */
    public static function pageHash(string $host, string $path): string
    {
        return substr(hash('sha256', $host . "\0" . $path, true), 0, 8);
    }

    private static function decodeUnreserved(string $path): string
    {
        return (string) preg_replace_callback('/%([0-9A-Fa-f]{2})/', static function (array $m): string {
            $char = \chr((int) hexdec($m[1]));

            return preg_match('/[A-Za-z0-9\-._~]/', $char) === 1 ? $char : '%' . strtoupper($m[1]);
        }, $path);
    }
}
