<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Tracking;

use Analytics\Tests\Support\Payloads;
use Analytics\Tracking\Application\Payload\PayloadException;
use Analytics\Tracking\Application\Payload\PayloadParser;
use Analytics\Tracking\Domain\ConsentStatKind;
use Analytics\Tracking\Domain\EventType;
use Analytics\Tracking\Domain\TrackingLevel;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayloadParserTest extends TestCase
{
    private const string KEY = 'pk_abcdefghijklmnopqrstu';

    public function testParsesAllEventTypes(): void
    {
        $vid = Payloads::id22();
        $payload = Payloads::batch(self::KEY, [
            Payloads::pageview('https://www.site.test/p?utm_source=x', 'https://referrer.example.org/'),
            Payloads::event('signup_click', ['plan' => 'pro', 'n' => 2, 'ok' => true, 'f' => 1.5]),
            Payloads::engagement(15432, 80),
            Payloads::consentUpgrade('https://www.site.test/landing', 'https://google.com/'),
            Payloads::consentStat('accept'),
        ], 'c', ['vid' => $vid, 'sid' => Payloads::id22(), 'cv' => 3]);
        self::assertValidAgainstSchema($payload);

        $parsed = new PayloadParser()->parse($payload);

        self::assertSame(TrackingLevel::Consented, $parsed->level);
        self::assertSame(16, \strlen((string) $parsed->visitorId));
        self::assertSame(3, $parsed->consentVersion);
        self::assertCount(5, $parsed->events);
        self::assertSame(EventType::Custom, $parsed->events[1]->type);
        self::assertSame(['plan' => 'pro', 'n' => 2, 'ok' => true, 'f' => 1.5], $parsed->events[1]->props);
        self::assertSame(15432, $parsed->events[2]->engagedMs);
        self::assertSame(80, $parsed->events[2]->scrollPct);
        self::assertSame('https://www.site.test/landing', $parsed->events[3]->landingUrl);
        self::assertSame(ConsentStatKind::Accept, $parsed->events[4]->consentStat);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidBatches(): iterable
    {
        $pv = Payloads::pageview();
        yield 'missing version' => [['k' => self::KEY, 'l' => 'b', 'e' => [$pv]], 'invalid_payload'];
        yield 'unknown version' => [['v' => 2, 'k' => self::KEY, 'l' => 'b', 'e' => [$pv]], 'unsupported_version'];
        yield 'string version' => [['v' => '1', 'k' => self::KEY, 'l' => 'b', 'e' => [$pv]], 'invalid_payload'];
        yield 'bad key' => [['v' => 1, 'k' => 'pk_short', 'l' => 'b', 'e' => [$pv]], 'invalid_payload'];
        yield 'bad level' => [['v' => 1, 'k' => self::KEY, 'l' => 'x', 'e' => [$pv]], 'invalid_payload'];
        yield 'no events' => [['v' => 1, 'k' => self::KEY, 'l' => 'b', 'e' => []], 'invalid_payload'];
        yield 'events object' => [['v' => 1, 'k' => self::KEY, 'l' => 'b', 'e' => ['a' => $pv]], 'invalid_payload'];
        yield 'too many events' => [['v' => 1, 'k' => self::KEY, 'l' => 'b', 'e' => array_fill(0, 51, $pv)], 'too_many_events'];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidBatches')]
    public function testRejectsInvalidBatches(array $payload, string $code): void
    {
        try {
            new PayloadParser()->parse($payload);
            self::fail('Expected rejection');
        } catch (PayloadException $e) {
            self::assertSame($code, $e->problemCode);
        }
    }

    public function testDropsInvalidEventsIndividually(): void
    {
        $parser = new PayloadParser();
        $dup = Payloads::pageview();
        $parsed = $parser->parse(Payloads::batch(self::KEY, [
            $dup,
            $dup,
            ['id' => 'short', 't' => 'pv', 'u' => 'https://www.site.test/'],
            ['id' => Payloads::uid(), 't' => 'zz', 'u' => 'https://www.site.test/'],
            ['id' => Payloads::uid(), 't' => 'pv', 'u' => 'javascript:alert(1)'],
            ['id' => Payloads::uid(), 't' => 'pv', 'u' => 'https://www.site.test/' . str_repeat('a', 2100)],
            Payloads::event('Bad Name'),
            Payloads::event('ok', array_fill_keys(range('a', 'k'), 'x')),
            Payloads::event('ok', ['k' => str_repeat('x', 101)]),
            Payloads::event('ok', ['nested' => ['no']]),
            Payloads::event('ok', [str_repeat('k', 33) => 'x']),
            ['id' => Payloads::uid(), 't' => 'cs', 'u' => 'https://www.site.test/', 'cs' => 'maybe'],
            'not an object',
            Payloads::event('fine_event'),
        ]));

        self::assertCount(2, $parsed->events);
        self::assertSame(12, $parser->dropped);
    }

    public function testConsentedLevelWithoutIdsIsDowngraded(): void
    {
        $parsed = new PayloadParser()->parse(Payloads::batch(self::KEY, [Payloads::pageview()], 'c', ['vid' => 'nope']));
        self::assertSame(TrackingLevel::Base, $parsed->level);
        self::assertNull($parsed->visitorId);

        $base = new PayloadParser()->parse(Payloads::batch(self::KEY, [Payloads::pageview()], 'b', ['vid' => Payloads::id22(), 'sid' => Payloads::id22()]));
        self::assertNull($base->visitorId, 'ids are stripped on the base level');
        self::assertNull($base->sessionId);
    }

    public function testAgeIsClamped(): void
    {
        $parsed = new PayloadParser()->parse(Payloads::batch(self::KEY, [
            ['a' => -5] + Payloads::pageview(),
            ['a' => 999_999_999] + Payloads::pageview(),
            ['a' => 'x'] + Payloads::pageview(),
        ]));
        self::assertSame([0, PayloadParser::MAX_AGE_MS, 0], array_map(static fn ($e): int => $e->ageMs, $parsed->events));
    }

    public function testSeededFuzzNeverThrowsUnexpectedErrors(): void
    {
        mt_srand(20260917);
        $parser = new PayloadParser();
        $values = [null, true, 0, -1, 1.5, '', 'x', 'pv', 'https://www.site.test/', [], ['a' => 1], [1, 2], str_repeat('é', 70), 'pk_abcdefghijklmnopqrstu', 1, 'b', 'c'];
        for ($i = 0; $i < 3000; ++$i) {
            $event = [];
            foreach (['id', 't', 'u', 'r', 'a', 'n', 'p', 'ck', 'ms', 'sp', 'cs', 'lu', 'lr'] as $key) {
                if (mt_rand(0, 2) > 0) {
                    $event[$key] = $values[mt_rand(0, \count($values) - 1)];
                }
            }
            $payload = [];
            foreach (['v', 'k', 'l', 'vid', 'sid', 'cv', 'sw'] as $key) {
                if (mt_rand(0, 3) > 0) {
                    $payload[$key] = $values[mt_rand(0, \count($values) - 1)];
                }
            }
            $payload['e'] = mt_rand(0, 4) === 0 ? $values[mt_rand(0, \count($values) - 1)] : [$event, Payloads::pageview()];
            try {
                $parsed = $parser->parse($payload);
                foreach ($parsed->events as $e) {
                    self::assertSame(12, \strlen($e->uid));
                }
            } catch (PayloadException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertValidAgainstSchema(array $payload): void
    {
        $validator = new Validator();
        $schemaPath = \dirname(__DIR__, 5) . '/docs/api/tracking-payload.v1.schema.json';
        $schema = json_decode((string) file_get_contents($schemaPath), false, 512, \JSON_THROW_ON_ERROR);
        $data = json_decode(json_encode($payload, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
        $result = $validator->validate($data, $schema);
        self::assertTrue($result->isValid(), 'Payload does not match the JSON schema');
    }
}
