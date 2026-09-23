<?php

declare(strict_types=1);

namespace Analytics\Consent\Domain;

/**
 * Where the banner sits on one device class (desktop above the breakpoint, mobile at or below it).
 */
final readonly class BannerPlacement
{
    public function __construct(
        public string $position,
        public int $offset,
        public string $buttons,
        /** Desktop only: the banner's maximum width in px (mobile layouts span the viewport). */
        public ?int $maxWidth = null,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        $out = ['position' => $this->position];
        if ($this->maxWidth !== null) {
            $out['maxWidth'] = $this->maxWidth;
        }

        return $out + ['offset' => $this->offset, 'buttons' => $this->buttons];
    }
}
