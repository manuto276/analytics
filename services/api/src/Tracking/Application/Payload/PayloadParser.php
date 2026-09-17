<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application\Payload;

use Analytics\Shared\Crypto\Base64Url;
use Analytics\Tracking\Domain\ConsentStatKind;
use Analytics\Tracking\Domain\EventType;
use Analytics\Tracking\Domain\TrackingLevel;

/**
 * Hand-written validator for payload v1 (docs/api/tracking-payload.v1.schema.json).
 * Structural errors reject the whole batch; individually malformed events are dropped.
 */
final class PayloadParser
{
    /** Current version N and N-1 are accepted (only v1 exists so far). */
    public const array SUPPORTED_VERSIONS = [1];
    public const int MAX_EVENTS = 50;
    public const int MAX_URL = 2048;
    public const int MAX_PROPS = 10;
    public const int MAX_PROP_KEY = 32;
    public const int MAX_PROP_VALUE = 100;
    public const int MAX_AGE_MS = 86_400_000;
    public const string NAME_PATTERN = '/^[a-z0-9_:.-]{1,64}$/';
    public const string CONTENT_KEY_PATTERN = '/^[A-Za-z0-9_:.\/-]{1,128}$/';
    /**
     * Property keys end up inside a JSON path (`$."<key>"`) built by the rollup SQL, so they are
     * restricted to characters that are safe there. The empty key is reserved: `rollup_events_daily`
     * uses `prop_key = ''` as the "no property" marker row.
     */
    public const string PROP_KEY_PATTERN = '/^[A-Za-z0-9_:.-]{1,' . self::MAX_PROP_KEY . '}$/';

    /** Number of events dropped by the last parse() call. */
    public int $dropped = 0;

    /**
     * @param array<array-key, mixed> $data
     */
    public function parse(array $data): ParsedPayload
    {
        $this->dropped = 0;
        $version = $data['v'] ?? null;
        if (!\is_int($version)) {
            throw new PayloadException('invalid_payload', 'Missing payload version.');
        }
        if (!\in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw new PayloadException('unsupported_version', \sprintf('Payload version %d is not supported.', $version));
        }
        $key = $data['k'] ?? null;
        if (!\is_string($key) || preg_match('/^pk_[A-Za-z0-9]{21}$/', $key) !== 1) {
            throw new PayloadException('invalid_payload', 'Invalid site key.');
        }
        $level = \is_string($data['l'] ?? null) ? TrackingLevel::tryFrom($data['l']) : null;
        if ($level === null) {
            throw new PayloadException('invalid_payload', 'Invalid tracking level.');
        }
        $events = $data['e'] ?? null;
        if (!\is_array($events) || !array_is_list($events) || $events === []) {
            throw new PayloadException('invalid_payload', 'Missing events.');
        }
        if (\count($events) > self::MAX_EVENTS) {
            throw new PayloadException('too_many_events', \sprintf('At most %d events per batch.', self::MAX_EVENTS));
        }

        $visitorId = null;
        $sessionId = null;
        if ($level === TrackingLevel::Consented) {
            $visitorId = self::id16($data['vid'] ?? null);
            $sessionId = self::id16($data['sid'] ?? null);
            if ($visitorId === null || $sessionId === null) {
                // A consented batch without valid ids is treated as base level.
                $level = TrackingLevel::Base;
                $visitorId = null;
                $sessionId = null;
            }
        }

        $consentVersion = $data['cv'] ?? 0;
        $screenWidth = $data['sw'] ?? null;

        $parsed = [];
        $seen = [];
        foreach ($events as $event) {
            $item = \is_array($event) ? $this->event($event) : null;
            if ($item === null || isset($seen[$item->uid])) {
                ++$this->dropped;
                continue;
            }
            $seen[$item->uid] = true;
            $parsed[] = $item;
        }

        return new ParsedPayload(
            version: $version,
            publicKey: $key,
            level: $level,
            visitorId: $visitorId,
            sessionId: $sessionId,
            consentVersion: \is_int($consentVersion) && $consentVersion >= 0 ? $consentVersion : 0,
            screenWidth: \is_int($screenWidth) && $screenWidth >= 0 && $screenWidth <= 100000 ? $screenWidth : null,
            events: $parsed,
        );
    }

    /** @param array<array-key, mixed> $e */
    private function event(array $e): ?ParsedEvent
    {
        $uid = \is_string($e['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{16}$/', $e['id']) === 1 ? Base64Url::decode($e['id']) : null;
        $type = \is_string($e['t'] ?? null) ? EventType::tryFrom($e['t']) : null;
        $url = self::url($e['u'] ?? null);
        if ($uid === null || \strlen($uid) !== 12 || $type === null || $url === null) {
            return null;
        }
        $age = $e['a'] ?? 0;
        $age = \is_int($age) ? max(0, min($age, self::MAX_AGE_MS)) : 0;

        $name = null;
        $props = [];
        if ($type === EventType::Custom) {
            $name = \is_string($e['n'] ?? null) && preg_match(self::NAME_PATTERN, $e['n']) === 1 ? $e['n'] : null;
            $props = self::props($e['p'] ?? null);
            if ($name === null || $props === null) {
                return null;
            }
        }

        $consentStat = null;
        if ($type === EventType::ConsentStat) {
            $consentStat = \is_string($e['cs'] ?? null) ? ConsentStatKind::tryFrom($e['cs']) : null;
            if ($consentStat === null) {
                return null;
            }
        }

        $engaged = null;
        $scroll = null;
        if ($type === EventType::Engagement) {
            $engaged = \is_int($e['ms'] ?? null) && $e['ms'] >= 0 ? min($e['ms'], self::MAX_AGE_MS) : 0;
            $scroll = \is_int($e['sp'] ?? null) ? max(0, min(100, $e['sp'])) : null;
        }

        $contentKey = \is_string($e['ck'] ?? null) && preg_match(self::CONTENT_KEY_PATTERN, $e['ck']) === 1 ? $e['ck'] : null;

        return new ParsedEvent(
            uid: $uid,
            type: $type,
            url: $url,
            referrer: self::url($e['r'] ?? null),
            ageMs: $age,
            name: $name,
            props: $props,
            contentKey: $contentKey,
            engagedMs: $engaged,
            scrollPct: $scroll,
            consentStat: $consentStat,
            landingUrl: $type === EventType::ConsentUpgrade ? self::url($e['lu'] ?? null) : null,
            landingReferrer: $type === EventType::ConsentUpgrade ? self::url($e['lr'] ?? null) : null,
        );
    }

    private static function url(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '' || \strlen($value) > self::MAX_URL) {
            return null;
        }
        $scheme = parse_url($value, \PHP_URL_SCHEME);
        $host = parse_url($value, \PHP_URL_HOST);
        if (!\is_string($scheme) || !\in_array(strtolower($scheme), ['http', 'https'], true) || !\is_string($host) || $host === '') {
            return null;
        }

        return $value;
    }

    private static function id16(mixed $value): ?string
    {
        if (!\is_string($value) || preg_match('/^[A-Za-z0-9_-]{22}$/', $value) !== 1) {
            return null;
        }
        $raw = Base64Url::decode($value);

        return $raw !== null && \strlen($raw) === 16 ? $raw : null;
    }

    /** @return array<string, string|int|float|bool>|null */
    private static function props(mixed $value): ?array
    {
        if ($value === null) {
            return [];
        }
        if (!\is_array($value) || ($value !== [] && array_is_list($value)) || \count($value) > self::MAX_PROPS) {
            return null;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $key = (string) $key;
            if (preg_match(self::PROP_KEY_PATTERN, $key) !== 1) {
                return null;
            }
            $valid = match (true) {
                \is_string($item) => mb_strlen($item) <= self::MAX_PROP_VALUE,
                \is_float($item) => is_finite($item),
                \is_int($item), \is_bool($item) => true,
                default => false,
            };
            if (!$valid) {
                return null;
            }
            \assert(\is_string($item) || \is_int($item) || \is_float($item) || \is_bool($item));
            $out[$key] = $item;
        }

        return $out;
    }
}
