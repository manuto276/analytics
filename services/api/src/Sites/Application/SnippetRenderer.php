<?php

declare(strict_types=1);

namespace Analytics\Sites\Application;

final readonly class SnippetRenderer
{
    public function __construct(private string $appUrl) {}

    /** @return array{html: string, script_url: string, proxy_html: string} */
    public function render(string $publicKey, string $globalName = 'analytics'): array
    {
        $scriptUrl = $this->appUrl . '/t/' . $publicKey . '.js';
        $global = preg_match('/^[A-Za-z_$][A-Za-z0-9_$]{0,31}$/', $globalName) === 1 ? $globalName : 'analytics';
        $stub = \sprintf("<script>window.%1\$s=window.%1\$s||{q:[],track(){this.q.push(['track',...arguments])}}</script>", $global);

        return [
            'html' => \sprintf('<script defer src="%s"></script>', htmlspecialchars($scriptUrl, \ENT_QUOTES)) . "\n" . $stub,
            'script_url' => $scriptUrl,
            'proxy_html' => \sprintf('<script defer src="/stats/%s.js"></script>', htmlspecialchars($publicKey, \ENT_QUOTES)) . "\n" . $stub,
        ];
    }
}
