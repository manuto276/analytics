<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application\Enrichment;

final readonly class UserAgentInfo
{
    public function __construct(
        public bool $isBot,
        public ?string $browser,
        public ?int $browserMajor,
        public ?string $os,
        public ?int $osMajor,
        /** desktop | mobile | tablet | other */
        public string $device,
    ) {}
}
