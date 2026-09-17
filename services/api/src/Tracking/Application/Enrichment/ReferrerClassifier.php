<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application\Enrichment;

/**
 * Maps referrer hosts to known sources using resources/referrers/*.json.
 * Each file: {"type": "search|social|email|ai|video", "sources": {"Google": ["google.*", "www.google.*"], ...}}
 */
final class ReferrerClassifier
{
    /** @var array<string, array{name: string, type: string}> exact host => source */
    private array $exact = [];
    /** @var list<array{regex: string, name: string, type: string}> */
    private array $patterns = [];

    public function __construct(string $directory)
    {
        $files = glob(rtrim($directory, '/') . '/*.json');
        foreach ($files === false ? [] : $files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!\is_array($data) || !\is_string($data['type'] ?? null) || !\is_array($data['sources'] ?? null)) {
                continue;
            }
            foreach ($data['sources'] as $name => $hosts) {
                if (!\is_array($hosts)) {
                    continue;
                }
                foreach ($hosts as $host) {
                    if (!\is_string($host)) {
                        continue;
                    }
                    if (str_contains($host, '*')) {
                        $regex = '/^' . str_replace('\*', '[a-z]{2,3}(?:\.[a-z]{2})?', preg_quote(strtolower($host), '/')) . '$/';
                        $this->patterns[] = ['regex' => $regex, 'name' => (string) $name, 'type' => $data['type']];
                    } else {
                        $this->exact[strtolower($host)] = ['name' => (string) $name, 'type' => $data['type']];
                    }
                }
            }
        }
    }

    /** @return array{name: string, type: string}|null */
    public function classify(string $host): ?array
    {
        $host = strtolower($host);
        $candidates = [$host];
        if (str_starts_with($host, 'www.')) {
            $candidates[] = substr($host, 4);
        } elseif (str_starts_with($host, 'm.')) {
            $candidates[] = substr($host, 2);
        }
        foreach ($candidates as $candidate) {
            if (isset($this->exact[$candidate])) {
                return $this->exact[$candidate];
            }
        }
        foreach ($candidates as $candidate) {
            foreach ($this->patterns as $pattern) {
                if (preg_match($pattern['regex'], $candidate) === 1) {
                    return ['name' => $pattern['name'], 'type' => $pattern['type']];
                }
            }
        }

        return null;
    }
}
