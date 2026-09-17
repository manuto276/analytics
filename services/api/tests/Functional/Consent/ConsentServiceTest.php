<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Consent;

use Analytics\Consent\Application\ConsentService;
use Analytics\Consent\Domain\ConsentConfig;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Validation\Input;
use Analytics\Tests\Support\HttpTestCase;

final class ConsentServiceTest extends HttpTestCase
{
    public function testDraftCanBeDiscardedAndRepublished(): void
    {
        $site = $this->factory->site();
        $this->loginAs($this->factory->admin());
        $base = '/api/v1/sites/' . $site->id() . '/consent';

        $this->assertProblem($this->delete($base . '/draft'), 404, 'not_found');

        $this->put($base . '/draft', ConsentService::defaults());
        $this->assertStatus(204, $this->delete($base . '/draft'));
        self::assertNull($this->data($this->get($base))['draft']);

        $this->put($base . '/draft', ConsentService::defaults());
        $published = $this->data($this->post($base . '/publish', ['material_change' => false]));
        self::assertSame(1, $published['revision'], 'a discarded draft frees its revision number');

        // A published revision is immutable: editing creates a new draft.
        $texts = ConsentService::defaults();
        $texts['texts']['en']['title'] = 'New title';
        $draft = $this->data($this->put($base . '/draft', $texts));
        self::assertSame('draft', $draft['status']);
        self::assertSame(2, $draft['revision']);
        self::assertSame('Your privacy', $this->data($this->get($base))['published']['texts']['en']['title']);
    }

    public function testTrackerConfigIsCachedAndRefreshedOnPublish(): void
    {
        $site = $this->factory->site(['cookieLevelEnabled' => true]);
        $consent = $this->service(ConsentService::class);
        self::assertNull($consent->trackerConfig($site->id()), 'nothing published yet');
        self::assertNull($consent->trackerConfig($site->id()), 'the empty result is cached');

        $consent->saveDraft($site->id(), new Input(ConsentService::defaults()));
        $consent->publish($site->id(), true, 1);

        $config = self::asArray($consent->trackerConfig($site->id()));
        self::assertSame(1, $config['v']);
        self::assertSame(180, $config['at']);
        self::assertTrue($config['fl']);
        self::assertSame($config, self::asArray($consent->trackerConfig($site->id())), 'served from cache');
    }

    /**
     * @return array<string, mixed>
     */
    private static function asArray(mixed $value): array
    {
        self::assertIsArray($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    public function testPublishRequiresADraftAndArchivesThePreviousRevision(): void
    {
        $site = $this->factory->site();
        $consent = $this->service(ConsentService::class);
        try {
            $consent->publish($site->id(), false, 1);
            self::fail('expected a conflict');
        } catch (ApiProblem $problem) {
            self::assertSame('no_draft', $problem->type);
        }

        $consent->saveDraft($site->id(), new Input(ConsentService::defaults()));
        $first = $consent->publish($site->id(), false, 1);
        $consent->saveDraft($site->id(), new Input(ConsentService::defaults()));
        $second = $consent->publish($site->id(), true, 1);

        $this->em->refresh($first);
        self::assertSame(ConsentConfig::ARCHIVED, $first->status);
        self::assertSame(ConsentConfig::PUBLISHED, $second->status);
        self::assertSame(2, $second->consentVersion);
        self::assertSame([$second->revision, $first->revision], array_map(static fn(ConsentConfig $c): int => $c->revision, $consent->history($site->id())));
    }

    public function testValidationRejectsUnknownLocalesAndMissingTexts(): void
    {
        $site = $this->factory->site();
        $this->loginAs($this->factory->admin());
        $base = '/api/v1/sites/' . $site->id() . '/consent/draft';

        $bad = ConsentService::defaults();
        $bad['texts'] = ['english' => $bad['texts']['en']];
        $bad['default_locale'] = 'english';
        $response = $this->put($base, $bad);
        $this->assertProblem($response, 422);
        self::assertArrayHasKey('texts.english', $this->json($response)['errors']);

        $missing = ConsentService::defaults();
        unset($missing['texts']['en']['reject']);
        $missing['default_locale'] = 'en';
        $response = $this->put($base, $missing);
        $this->assertProblem($response, 422);
        self::assertArrayHasKey('texts.en.reject', $this->json($response)['errors']);

        $empty = ConsentService::defaults();
        $empty['texts'] = [];
        $this->assertProblem($this->put($base, $empty), 422);
    }
}
