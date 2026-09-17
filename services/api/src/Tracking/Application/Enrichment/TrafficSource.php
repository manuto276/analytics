<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application\Enrichment;

use Analytics\Tracking\Domain\Channel;

final readonly class TrafficSource
{
    public function __construct(
        public Channel $channel,
        public ?string $source,
        public ?string $referrerHost,
        public ?string $utmSource,
        public ?string $utmMedium,
        public ?string $utmCampaign,
        public ?string $utmContent,
        public ?string $utmTerm,
    ) {}

    public static function direct(): self
    {
        return new self(Channel::Direct, null, null, null, null, null, null, null);
    }

    /** 8-byte key identifying the external source of a visit (null when direct or internal). */
    public function key(): ?string
    {
        if (!$this->channel->isExternal()) {
            return null;
        }

        return substr(hash('sha256', implode("\0", [$this->channel->value, $this->source, $this->utmSource, $this->utmMedium, $this->utmCampaign]), true), 0, 8);
    }
}
