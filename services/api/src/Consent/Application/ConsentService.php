<?php

declare(strict_types=1);

namespace Analytics\Consent\Application;

use Analytics\Consent\Domain\ConsentConfig;
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
    public const array POSITIONS = ['bottom', 'bottom-left', 'bottom-right'];

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
            'theme' => ['bg' => '#ffffff', 'fg' => '#111827', 'ac' => '#1d4ed8', 'acf' => '#ffffff', 'rad' => 8, 'pos' => 'bottom'],
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
        $this->cache->deleteItem('tracker_config_' . $siteId);

        return $draft;
    }

    /**
     * @return array{texts: array<string, array<string, string>>, policy_urls: array<string, string>, default_locale: string, theme: array{bg: string, fg: string, ac: string, acf: string, rad: int, pos: string}, accepted_ttl_days: int, rejected_ttl_days: int, show_floating_reopen: bool}
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
        $themeInput = $input->nested('theme', true) ?? new Input([], 'theme.');
        $theme = [
            'bg' => $themeInput->string('bg', 7, 7, '/^#[0-9a-fA-F]{6}$/'),
            'fg' => $themeInput->string('fg', 7, 7, '/^#[0-9a-fA-F]{6}$/'),
            'ac' => $themeInput->string('ac', 7, 7, '/^#[0-9a-fA-F]{6}$/'),
            'acf' => $themeInput->string('acf', 7, 7, '/^#[0-9a-fA-F]{6}$/'),
            'rad' => $themeInput->int('rad', 8, 0, 24),
            'pos' => $themeInput->choice('pos', self::POSITIONS, 'bottom'),
        ];
        $input->merge($themeInput);
        if ($themeInput->errors() === []) {
            if (ContrastChecker::ratio($theme['fg'], $theme['bg']) < ContrastChecker::MINIMUM) {
                $input->error('theme.fg', \sprintf('Text/background contrast must be at least %.1f:1.', ContrastChecker::MINIMUM));
            }
            if (ContrastChecker::ratio($theme['acf'], $theme['ac']) < ContrastChecker::MINIMUM) {
                $input->error('theme.acf', \sprintf('Button text/button contrast must be at least %.1f:1.', ContrastChecker::MINIMUM));
            }
        }
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
            'theme' => $config->theme,
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
        $item = $this->cache->getItem('tracker_config_' . $siteId);
        if ($item->isHit()) {
            $cached = $item->get();

            if (!\is_array($cached) || $cached === []) {
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

    /** @return array<string, mixed> */
    private function buildTrackerConfig(ConsentConfig $config): array
    {
        $texts = [];
        foreach ($config->texts as $locale => $values) {
            $texts[$locale] = $values + ['policyUrl' => $config->policyUrls[$locale] ?? ($config->policyUrls[$config->defaultLocale] ?? '')];
        }

        return [
            'v' => $config->consentVersion,
            'rev' => $config->revision,
            'dl' => $config->defaultLocale,
            'at' => $config->acceptedTtlDays,
            'rt' => $config->rejectedTtlDays,
            'fl' => $config->showFloatingReopen,
            'theme' => $config->theme,
            'texts' => $texts,
        ];
    }
}
