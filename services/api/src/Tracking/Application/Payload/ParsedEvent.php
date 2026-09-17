<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application\Payload;

use Analytics\Tracking\Domain\ConsentStatKind;
use Analytics\Tracking\Domain\EventType;

final readonly class ParsedEvent
{
    /**
     * @param array<string, string|int|float|bool> $props
     */
    public function __construct(
        /** 12 raw bytes */
        public string $uid,
        public EventType $type,
        public string $url,
        public ?string $referrer,
        public int $ageMs,
        public ?string $name,
        public array $props,
        public ?string $contentKey,
        public ?int $engagedMs,
        public ?int $scrollPct,
        public ?ConsentStatKind $consentStat,
        public ?string $landingUrl,
        public ?string $landingReferrer,
    ) {}
}
