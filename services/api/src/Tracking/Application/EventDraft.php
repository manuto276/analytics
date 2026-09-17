<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application;

use Analytics\Tracking\Domain\Channel;
use Analytics\Tracking\Domain\ConsentStatKind;
use Analytics\Tracking\Domain\EventType;
use Analytics\Tracking\Domain\TrackingLevel;

/**
 * A fully enriched, anonymised event ready to be stored (or queued). It never contains the
 * full IP address or the full User-Agent.
 */
final readonly class EventDraft
{
    /**
     * @param array<string, string|int|float|bool> $props
     */
    public function __construct(
        public int $siteId,
        /** 12 raw bytes */
        public string $uid,
        public EventType $type,
        public TrackingLevel $level,
        public ?string $name,
        public \DateTimeImmutable $occurredAt,
        public \DateTimeImmutable $receivedAt,
        public string $localDay,
        /** 63-bit daily visitor hash (daily_hash sites) */
        public ?int $visitorHash,
        /** 16 raw bytes: daily hash key (daily_hash sites) */
        public ?string $hashKey,
        /** 16 raw bytes: session id (consented level) */
        public ?string $sessionKey,
        /** 16 raw bytes: visitor id (consented level) */
        public ?string $visitorId,
        public string $host,
        public string $path,
        /** 8 raw bytes */
        public string $pageHash,
        public ?string $query,
        public Channel $channel,
        public ?string $source,
        public ?string $referrerHost,
        public ?string $utmSource,
        public ?string $utmMedium,
        public ?string $utmCampaign,
        public ?string $utmContent,
        public ?string $utmTerm,
        /** 8 raw bytes identifying an external source, null for direct/internal */
        public ?string $sourceKey,
        public ?string $browser,
        public ?int $browserMajor,
        public ?string $os,
        public ?int $osMajor,
        public ?string $device,
        public ?string $country,
        public ?string $contentKey,
        public ?int $engagedMs,
        public ?int $scrollPct,
        public array $props,
        public int $consentVersion,
        public ?ConsentStatKind $consentStat,
        public bool $consentReceipt,
    ) {}

    /** Key used to find the visit: session id on the consented level, otherwise the daily hash. */
    public function visitKey(): ?string
    {
        return $this->level === TrackingLevel::Consented ? $this->sessionKey : $this->hashKey;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $b64 = static fn(?string $v): ?string => $v === null ? null : base64_encode($v);

        return [
            'site' => $this->siteId,
            'uid' => base64_encode($this->uid),
            't' => $this->type->value,
            'l' => $this->level->value,
            'n' => $this->name,
            'oa' => $this->occurredAt->format('Y-m-d H:i:s.v'),
            'ra' => $this->receivedAt->format('Y-m-d H:i:s.v'),
            'day' => $this->localDay,
            'vh' => $this->visitorHash,
            'hk' => $b64($this->hashKey),
            'sk' => $b64($this->sessionKey),
            'vid' => $b64($this->visitorId),
            'host' => $this->host,
            'path' => $this->path,
            'ph' => base64_encode($this->pageHash),
            'q' => $this->query,
            'ch' => $this->channel->value,
            'src' => $this->source,
            'ref' => $this->referrerHost,
            'us' => $this->utmSource,
            'um' => $this->utmMedium,
            'uc' => $this->utmCampaign,
            'uo' => $this->utmContent,
            'ut' => $this->utmTerm,
            'srk' => $b64($this->sourceKey),
            'br' => $this->browser,
            'brm' => $this->browserMajor,
            'os' => $this->os,
            'osm' => $this->osMajor,
            'dev' => $this->device,
            'cc' => $this->country,
            'ck' => $this->contentKey,
            'ms' => $this->engagedMs,
            'sp' => $this->scrollPct,
            'p' => $this->props,
            'cv' => $this->consentVersion,
            'cs' => $this->consentStat?->value,
            'rc' => $this->consentReceipt,
        ];
    }

    /** @param array<array-key, mixed> $a */
    public static function fromArray(array $a): self
    {
        $bin = static function (mixed $v): ?string {
            if (!\is_string($v)) {
                return null;
            }
            $raw = base64_decode($v, true);

            return $raw === false ? null : $raw;
        };
        $str = static fn(mixed $v): ?string => \is_string($v) ? $v : null;
        $int = static fn(mixed $v): ?int => \is_int($v) ? $v : null;
        $utc = new \DateTimeZone('UTC');
        $props = [];
        if (\is_array($a['p'] ?? null)) {
            foreach ($a['p'] as $k => $v) {
                if (\is_string($v) || \is_int($v) || \is_float($v) || \is_bool($v)) {
                    $props[(string) $k] = $v;
                }
            }
        }

        return new self(
            siteId: \is_int($a['site'] ?? null) ? $a['site'] : throw new \UnexpectedValueException('Invalid draft.'),
            uid: $bin($a['uid'] ?? null) ?? throw new \UnexpectedValueException('Invalid draft.'),
            type: EventType::from(\is_string($a['t'] ?? null) ? $a['t'] : ''),
            level: TrackingLevel::from(\is_string($a['l'] ?? null) ? $a['l'] : ''),
            name: $str($a['n'] ?? null),
            occurredAt: new \DateTimeImmutable($str($a['oa'] ?? null) ?? 'now', $utc),
            receivedAt: new \DateTimeImmutable($str($a['ra'] ?? null) ?? 'now', $utc),
            localDay: $str($a['day'] ?? null) ?? throw new \UnexpectedValueException('Invalid draft.'),
            visitorHash: $int($a['vh'] ?? null),
            hashKey: $bin($a['hk'] ?? null),
            sessionKey: $bin($a['sk'] ?? null),
            visitorId: $bin($a['vid'] ?? null),
            host: $str($a['host'] ?? null) ?? '',
            path: $str($a['path'] ?? null) ?? '/',
            pageHash: $bin($a['ph'] ?? null) ?? str_repeat("\0", 8),
            query: $str($a['q'] ?? null),
            channel: Channel::from(\is_string($a['ch'] ?? null) ? $a['ch'] : 'direct'),
            source: $str($a['src'] ?? null),
            referrerHost: $str($a['ref'] ?? null),
            utmSource: $str($a['us'] ?? null),
            utmMedium: $str($a['um'] ?? null),
            utmCampaign: $str($a['uc'] ?? null),
            utmContent: $str($a['uo'] ?? null),
            utmTerm: $str($a['ut'] ?? null),
            sourceKey: $bin($a['srk'] ?? null),
            browser: $str($a['br'] ?? null),
            browserMajor: $int($a['brm'] ?? null),
            os: $str($a['os'] ?? null),
            osMajor: $int($a['osm'] ?? null),
            device: $str($a['dev'] ?? null),
            country: $str($a['cc'] ?? null),
            contentKey: $str($a['ck'] ?? null),
            engagedMs: $int($a['ms'] ?? null),
            scrollPct: $int($a['sp'] ?? null),
            props: $props,
            consentVersion: $int($a['cv'] ?? null) ?? 0,
            consentStat: \is_string($a['cs'] ?? null) ? ConsentStatKind::tryFrom($a['cs']) : null,
            consentReceipt: ($a['rc'] ?? false) === true,
        );
    }
}
