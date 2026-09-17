<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application\Enrichment;

use Analytics\Shared\Net\IpPrefix;
use Analytics\Sites\Application\SiteSnapshot;

final class BotFilter
{
    /** Reason the request is dropped, or null when it looks like a real visitor. */
    public static function requestRejection(UserAgentInfo $ua, string $acceptLanguage, ?IpPrefix $ip, SiteSnapshot $site): ?string
    {
        if ($ua->isBot) {
            return 'bot_user_agent';
        }
        if (trim($acceptLanguage) === '') {
            return 'no_accept_language';
        }
        if ($ip !== null) {
            foreach ($site->excludedIpPrefixes as $cidr) {
                if ($ip->matches($cidr)) {
                    return 'excluded_ip';
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $globs
     */
    public static function isExcludedPath(string $path, array $globs): bool
    {
        foreach ($globs as $glob) {
            if (fnmatch($glob, $path, \FNM_NOESCAPE) || fnmatch(rtrim($glob, '/') . '/*', $path, \FNM_NOESCAPE)) {
                return true;
            }
        }

        return false;
    }
}
