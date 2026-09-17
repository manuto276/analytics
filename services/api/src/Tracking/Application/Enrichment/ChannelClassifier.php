<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application\Enrichment;

use Analytics\Tracking\Domain\Channel;

/**
 * Derives UTM values, source and channel from the landing URL parameters and the referrer.
 */
final readonly class ChannelClassifier
{
    private const array PAID_MEDIUMS = ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'sem', 'cpm', 'cpv', 'cpa', 'display', 'banner', 'retargeting'];
    private const array PAID_SOCIAL_MEDIUMS = ['paid_social', 'paidsocial', 'paid-social', 'social_paid', 'social-paid', 'socialpaid'];
    private const array SOCIAL_MEDIUMS = ['social', 'social-network', 'social-media', 'sm', 'social network', 'social media', 'organic_social'];
    private const array EMAIL_MEDIUMS = ['email', 'e-mail', 'e_mail', 'mail', 'newsletter'];

    public function __construct(private ReferrerClassifier $referrers) {}

    /**
     * @param array<string, string> $params  raw query parameters of the landing URL (lowercased keys)
     * @param callable(string): bool $isOwnHost
     */
    public function classify(array $params, ?string $referrer, callable $isOwnHost): TrafficSource
    {
        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $key) {
            $value = isset($params[$key]) ? trim(PiiScrubber::scrub($params[$key])) : '';
            $utm[$key] = $value === '' ? null : mb_substr(\in_array($key, ['utm_source', 'utm_medium'], true) ? mb_strtolower($value) : $value, 0, 100);
        }
        if ($utm['utm_source'] === null && isset($params['ref']) && trim($params['ref']) !== '') {
            $utm['utm_source'] = mb_substr(mb_strtolower(trim(PiiScrubber::scrub($params['ref']))), 0, 100);
        }

        $referrerHost = null;
        if ($referrer !== null) {
            $host = parse_url($referrer, \PHP_URL_HOST);
            $referrerHost = \is_string($host) && $host !== '' ? strtolower(rtrim($host, '.')) : null;
        }
        $internal = $referrerHost !== null && $isOwnHost($referrerHost);
        if ($internal) {
            $referrerHost = null;
        }
        $known = $referrerHost === null ? null : $this->referrers->classify($referrerHost);
        $sourceKnown = $utm['utm_source'] === null ? null : $this->referrers->classify($utm['utm_source']) ?? $this->referrers->classify($utm['utm_source'] . '.com');

        $medium = $utm['utm_medium'];
        $hasUtm = $utm['utm_source'] !== null || $medium !== null || $utm['utm_campaign'] !== null;
        $sourceType = $sourceKnown['type'] ?? $known['type'] ?? null;

        $channel = match (true) {
            isset($params['gclid']) || isset($params['gbraid']) || isset($params['wbraid']) || isset($params['msclkid']) || isset($params['dclid']) => Channel::PaidSearch,
            $medium !== null && \in_array($medium, self::PAID_SOCIAL_MEDIUMS, true) => Channel::PaidSocial,
            $medium !== null && \in_array($medium, self::PAID_MEDIUMS, true) => $sourceType === 'social' ? Channel::PaidSocial : Channel::PaidSearch,
            $medium !== null && \in_array($medium, self::EMAIL_MEDIUMS, true) => Channel::Email,
            $medium !== null && \in_array($medium, self::SOCIAL_MEDIUMS, true) => Channel::OrganicSocial,
            $medium === 'organic' && $sourceType === 'search' => Channel::OrganicSearch,
            $hasUtm && $sourceType === 'social' && $medium === null => Channel::OrganicSocial,
            $hasUtm && $sourceType === 'search' && $medium === null => Channel::OrganicSearch,
            $hasUtm => Channel::Campaign,
            $known !== null && $known['type'] === 'search' => Channel::OrganicSearch,
            $known !== null && \in_array($known['type'], ['social', 'video'], true) => Channel::OrganicSocial,
            $known !== null && $known['type'] === 'email' => Channel::Email,
            isset($params['fbclid']) || isset($params['ttclid']) || isset($params['twclid']) => Channel::OrganicSocial,
            $referrerHost !== null => Channel::Referral,
            $internal => Channel::Internal,
            default => Channel::Direct,
        };

        $source = $utm['utm_source'] !== null
            ? ($sourceKnown['name'] ?? $utm['utm_source'])
            : ($known['name'] ?? $referrerHost);

        return new TrafficSource(
            channel: $channel,
            source: $source === null ? null : mb_substr($source, 0, 100),
            referrerHost: $referrerHost === null ? null : mb_substr($referrerHost, 0, 190),
            utmSource: $utm['utm_source'],
            utmMedium: $utm['utm_medium'],
            utmCampaign: $utm['utm_campaign'],
            utmContent: $utm['utm_content'],
            utmTerm: $utm['utm_term'],
        );
    }
}
