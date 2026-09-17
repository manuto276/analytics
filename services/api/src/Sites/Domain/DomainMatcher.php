<?php

declare(strict_types=1);

namespace Analytics\Sites\Domain;

final class DomainMatcher
{
    /**
     * Normalises a host entered by a user ("https://WWW.Example.com/" → "www.example.com").
     * Returns null when invalid.
     */
    public static function normalizeHost(string $input): ?string
    {
        $input = strtolower(trim($input));
        if ($input === '') {
            return null;
        }
        if (str_contains($input, '://')) {
            $input = (string) parse_url($input, \PHP_URL_HOST);
        }
        $input = rtrim(explode('/', $input)[0], '.');
        $input = (string) preg_replace('/:\d+$/', '', $input);
        if (\function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $input) === 1) {
            $ascii = idn_to_ascii($input, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
            $input = $ascii === false ? '' : $ascii;
        }
        if ($input === 'localhost') {
            return $input;
        }
        if (\strlen($input) > 190 || preg_match('/^(?=.{1,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $input) !== 1) {
            return null;
        }

        return $input;
    }

    /**
     * @param list<array{host: string, include_subdomains: bool}> $domains
     */
    public static function matches(string $host, array $domains): bool
    {
        $host = strtolower(rtrim($host, '.'));
        foreach ($domains as $domain) {
            if ($host === $domain['host']) {
                return true;
            }
            if ($domain['include_subdomains'] && str_ends_with($host, '.' . $domain['host'])) {
                return true;
            }
        }

        return false;
    }

    /** Host of an Origin or Referer header value, or null. */
    public static function hostOf(string $url): ?string
    {
        if ($url === '' || $url === 'null') {
            return null;
        }
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
