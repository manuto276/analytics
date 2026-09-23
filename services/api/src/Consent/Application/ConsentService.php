<?php

declare(strict_types=1);

namespace Analytics\Consent\Application;

use Analytics\Consent\Domain\ConsentConfig;
use Analytics\Consent\Domain\ConsentThemeV2;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Types;
use Analytics\Shared\Validation\Input;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Draft → publish workflow of the consent banner configuration. Published revisions are immutable;
 * consent_version increments only on a material change.
 */
final readonly class ConsentService
{
    public const array TEXT_KEYS = ['title' => 120, 'body' => 1200, 'accept' => 40, 'reject' => 40, 'close' => 40, 'policy' => 60, 'reopen' => 60];
    /** Cache key of the consent block of window.__an_cfg; publishing deletes it. */
    public const string TRACKER_CONFIG_CACHE = 'tracker_config_';

    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private CacheItemPoolInterface $cache,
    ) {}

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'texts' => [
                'en' => [
                    'title' => 'Your privacy',
                    'body' => 'We use first-party cookies to measure returning visits and campaign performance. Basic anonymous statistics are collected without cookies. You can change your choice at any time.',
                    'accept' => 'Accept',
                    'reject' => 'Reject',
                    'close' => 'Close',
                    'policy' => 'Privacy policy',
                    'reopen' => 'Cookie settings',
                ],
                'it' => [
                    'title' => 'La tua privacy',
                    'body' => 'Usiamo cookie di prima parte per misurare le visite di ritorno e il rendimento delle campagne. Le statistiche di base anonime vengono raccolte senza cookie. Puoi cambiare la tua scelta in qualsiasi momento.',
                    'accept' => 'Accetta',
                    'reject' => 'Rifiuta',
                    'close' => 'Chiudi',
                    'policy' => 'Informativa privacy',
                    'reopen' => 'Impostazioni cookie',
                ],
            ],
            'policy_urls' => ['en' => 'https://www.example.com/privacy', 'it' => 'https://www.example.com/it/privacy'],
            'default_locale' => 'en',
            // The complete v2 theme (docs/api/consent-theme.v2.schema.json). v1 themes are still
            // accepted on write for clients that predate v2.
            'theme' => ConsentThemeV2::defaults()->toArray(),
            'accepted_ttl_days' => 180,
            'rejected_ttl_days' => 180,
            'show_floating_reopen' => true,
        ];
    }

    public function published(int $siteId): ?ConsentConfig
    {
        $config = $this->em->getRepository(ConsentConfig::class)->findOneBy(['siteId' => $siteId, 'status' => ConsentConfig::PUBLISHED]);

        return $config instanceof ConsentConfig ? $config : null;
    }

    public function draft(int $siteId): ?ConsentConfig
    {
        $config = $this->em->getRepository(ConsentConfig::class)->findOneBy(['siteId' => $siteId, 'status' => ConsentConfig::DRAFT]);

        return $config instanceof ConsentConfig ? $config : null;
    }

    /** @return list<ConsentConfig> */
    public function history(int $siteId): array
    {
        /** @var list<ConsentConfig> $items */
        $items = $this->em->getRepository(ConsentConfig::class)->findBy(['siteId' => $siteId, 'status' => [ConsentConfig::PUBLISHED, ConsentConfig::ARCHIVED]], ['revision' => 'DESC']);

        return $items;
    }

    public function saveDraft(int $siteId, Input $input): ConsentConfig
    {
        $values = self::validate($input);
        $draft = $this->draft($siteId);
        if ($draft === null) {
            $revision = Types::int($this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(revision), 0) + 1 FROM consent_configs WHERE site_id = ?', [$siteId]));
            $draft = new ConsentConfig($siteId, $revision, $this->published($siteId)->consentVersion ?? 0, ConsentConfig::DRAFT, $values['texts'], $values['policy_urls'], $values['default_locale'], $values['theme'], $values['accepted_ttl_days'], $values['rejected_ttl_days'], $values['show_floating_reopen'], $this->clock->now());
            $this->em->persist($draft);
        } else {
            $draft->texts = $values['texts'];
            $draft->policyUrls = $values['policy_urls'];
            $draft->defaultLocale = $values['default_locale'];
            $draft->theme = $values['theme'];
            $draft->acceptedTtlDays = $values['accepted_ttl_days'];
            $draft->rejectedTtlDays = $values['rejected_ttl_days'];
            $draft->showFloatingReopen = $values['show_floating_reopen'];
        }
        $this->em->flush();

        return $draft;
    }

    public function discardDraft(int $siteId): void
    {
        $draft = $this->draft($siteId) ?? throw ApiProblem::notFound('No draft.');
        $this->em->remove($draft);
        $this->em->flush();
    }

    public function publish(int $siteId, bool $materialChange, int $userId): ConsentConfig
    {
        $draft = $this->draft($siteId) ?? throw ApiProblem::conflict('no_draft', 'There is no draft to publish.');
        $current = $this->published($siteId);
        if ($current === null) {
            $draft->consentVersion = 1;
        } else {
            $draft->consentVersion = $materialChange ? $current->consentVersion + 1 : $current->consentVersion;
            $current->status = ConsentConfig::ARCHIVED;
        }
        $draft->status = ConsentConfig::PUBLISHED;
        $draft->publishedAt = $this->clock->now();
        $draft->publishedBy = $userId;
        $this->em->flush();
        $this->cache->deleteItem(self::TRACKER_CONFIG_CACHE . $siteId);

        return $draft;
    }

    /**
     * The theme may be v1 (stored as sent) or v2 (stored complete); see {@see ThemeValidator}.
     *
     * @return array{texts: array<string, array<string, string>>, policy_urls: array<string, string>, default_locale: string, theme: array<string, mixed>, accepted_ttl_days: int, rejected_ttl_days: int, show_floating_reopen: bool}
     */
    public static function validate(Input $input): array
    {
        $texts = [];
        $rawTexts = $input->array('texts', true) ?? [];
        if ($rawTexts === [] || array_is_list($rawTexts)) {
            $input->error('texts', 'Provide texts for at least one locale.');
        }
        foreach ($rawTexts as $locale => $localeTexts) {
            $locale = (string) $locale;
            if (preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale) !== 1) {
                $input->error('texts.' . $locale, 'Locale must look like "en" or "pt-BR".');
                continue;
            }
            $localeInput = new Input(\is_array($localeTexts) ? $localeTexts : [], 'texts.' . $locale . '.');
            foreach (self::TEXT_KEYS as $key => $max) {
                $texts[$locale][$key] = $localeInput->string($key, $max);
            }
            $input->merge($localeInput);
        }
        $policyUrls = [];
        foreach ($input->array('policy_urls', true) ?? [] as $locale => $url) {
            if (!\is_string($url) || preg_match('#^https?://#', $url) !== 1 || filter_var($url, \FILTER_VALIDATE_URL) === false || \strlen($url) > 500) {
                $input->error('policy_urls.' . $locale, 'Must be an http(s) URL.');
                continue;
            }
            $policyUrls[(string) $locale] = $url;
        }
        $defaultLocale = $input->string('default_locale', 8);
        if ($texts !== [] && !isset($texts[$defaultLocale])) {
            $input->error('default_locale', 'Must be one of the locales with texts.');
        }
        $themeInput = $input->nested('theme') ?? new Input([], 'theme.');
        $theme = ThemeValidator::validate($themeInput);
        $input->merge($themeInput);
        $values = [
            'texts' => $texts,
            'policy_urls' => $policyUrls,
            'default_locale' => $defaultLocale,
            'theme' => $theme,
            'accepted_ttl_days' => $input->int('accepted_ttl_days', 180, 1, 395),
            'rejected_ttl_days' => $input->int('rejected_ttl_days', 180, 1, 395),
            'show_floating_reopen' => $input->bool('show_floating_reopen', true),
        ];
        $input->assertValid();

        return $values;
    }

    /** @return array<string, mixed> */
    public static function toArray(ConsentConfig $config): array
    {
        return [
            'id' => $config->id(),
            'revision' => $config->revision,
            'consent_version' => $config->consentVersion,
            'status' => $config->status,
            'texts' => (object) $config->texts,
            'policy_urls' => (object) $config->policyUrls,
            'default_locale' => $config->defaultLocale,
            'theme' => (object) $config->theme,
            'theme_v2' => ConsentThemeV2::fromStored($config->theme)->toArray(),
            'accepted_ttl_days' => $config->acceptedTtlDays,
            'rejected_ttl_days' => $config->rejectedTtlDays,
            'show_floating_reopen' => $config->showFloatingReopen,
            'created_at' => $config->createdAt->format(\DATE_ATOM),
            'published_at' => $config->publishedAt?->format(\DATE_ATOM),
            'published_by' => $config->publishedBy,
        ];
    }

    /**
     * Consent block of window.__an_cfg (docs/architecture/tracker.md).
     *
     * @return array<string, mixed>|null
     */
    public function trackerConfig(int $siteId): ?array
    {
        $item = $this->cache->getItem(self::TRACKER_CONFIG_CACHE . $siteId);
        $cached = $item->isHit() ? $item->get() : null;
        // A block cached before the stylesheet existed (the deploy that introduced it) is rebuilt.
        if (\is_array($cached) && ($cached === [] || isset($cached['css']))) {
            if ($cached === []) {
                return null;
            }
            $out = [];
            foreach ($cached as $key => $value) {
                $out[(string) $key] = $value;
            }

            return $out;
        }
        $config = $this->published($siteId);
        $value = $config === null ? null : $this->buildTrackerConfig($config);
        $item->set($value ?? [])->expiresAfter(60);
        $this->cache->save($item);

        return $value;
    }

    /**
     * The consent block an unsaved configuration would produce — what the dashboard preview renders
     * with the real banner module. Validated exactly like {@see self::saveDraft()} (same 422s);
     * nothing is stored. `v` and `rev` are those the configuration would get if it were saved as
     * the draft and published without a material change.
     *
     * @return array<string, mixed>
     */
    public function preview(int $siteId, Input $input): array
    {
        $values = self::validate($input);
        $draft = $this->draft($siteId);
        $published = $this->published($siteId);
        $revision = $draft->revision ?? (($published->revision ?? 0) + 1);

        return self::trackerBlock($values['texts'], $values['policy_urls'], $values['default_locale'], $values['theme'], $values['accepted_ttl_days'], $values['rejected_ttl_days'], $values['show_floating_reopen'], $published->consentVersion ?? 1, $revision);
    }

    /** @return array<string, mixed> */
    private function buildTrackerConfig(ConsentConfig $config): array
    {
        return self::trackerBlock($config->texts, $config->policyUrls, $config->defaultLocale, $config->theme, $config->acceptedTtlDays, $config->rejectedTtlDays, $config->showFloatingReopen, $config->consentVersion, $config->revision);
    }

    /**
     * @param array<string, array<string, string>> $texts
     * @param array<string, string> $policyUrls
     * @param array<array-key, mixed> $theme stored theme (v1 or v2)
     *
     * @return array<string, mixed>
     */
    private static function trackerBlock(array $texts, array $policyUrls, string $defaultLocale, array $theme, int $acceptedTtlDays, int $rejectedTtlDays, bool $floatingReopen, int $consentVersion, int $revision): array
    {
        $localised = [];
        foreach ($texts as $locale => $values) {
            $localised[$locale] = $values + ['policyUrl' => $policyUrls[$locale] ?? ($policyUrls[$defaultLocale] ?? '')];
        }

        $v2 = ConsentThemeV2::fromStored($theme);

        return [
            'v' => $consentVersion,
            'rev' => $revision,
            'dl' => $defaultLocale,
            'at' => $acceptedTtlDays,
            'rt' => $rejectedTtlDays,
            'fl' => $floatingReopen,
            'css' => BannerStylesheet::compile($v2),
            'ri' => BannerStylesheet::iconPath($v2),
            'texts' => $localised,
        ];
    }
}
