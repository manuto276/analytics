<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application\Payload;

use Analytics\Tracking\Domain\TrackingLevel;

final readonly class ParsedPayload
{
    /**
     * @param list<ParsedEvent> $events
     */
    public function __construct(
        public int $version,
        public string $publicKey,
        public TrackingLevel $level,
        /** 16 raw bytes, only for the consented level */
        public ?string $visitorId,
        /** 16 raw bytes, only for the consented level */
        public ?string $sessionId,
        public int $consentVersion,
        public ?int $screenWidth,
        public array $events,
    ) {}

    public function withBaseLevel(): self
    {
        return new self($this->version, $this->publicKey, TrackingLevel::Base, null, null, $this->consentVersion, $this->screenWidth, $this->events);
    }
}
