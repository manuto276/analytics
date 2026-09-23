<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Tracking;

use Analytics\Consent\Application\ConsentService;
use Analytics\Shared\Validation\Input;
use Analytics\Tests\Support\HttpTestCase;

final class ScriptTest extends HttpTestCase
{
    /** @return array<string, mixed> */
    private static function config(string $body): array
    {
        self::assertSame(1, preg_match('/window\.__an_cfg=(\{.*?\});\n/s', $body, $m));

        return (array) json_decode($m[1], true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testServesTrackerWithSiteConfigAndEtag(): void
    {
        $site = $this->factory->site(['cookieDomain' => 'site.test', 'excludedPaths' => ['/admin']]);
        $response = $this->get('/t/' . $site->publicKey . '.js');

        $this->assertStatus(200, $response);
        self::assertStringStartsWith('application/javascript', $response->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=300, stale-while-revalidate=600', $response->getHeaderLine('Cache-Control'));
        self::assertSame('cross-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
        $body = (string) $response->getBody();
        self::assertStringStartsWith('/*! analytics | AGPL-3.0-or-later | source: https://github.com/manuto276/analytics */', $body);
        $config = self::config($body);
        self::assertSame($site->publicKey, $config['k']);
        self::assertSame('.site.test', $config['cd']);
        self::assertSame(['/admin'], $config['xp']);
        self::assertFalse($config['c']);
        self::assertNull($config['consent']);

        $etag = $response->getHeaderLine('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);
        $cached = $this->get('/t/' . $site->publicKey . '.js', ['If-None-Match' => $etag]);
        $this->assertStatus(304, $cached);
        self::assertSame('', (string) $cached->getBody());
    }

    public function testPublishedConsentConfigIsEmbeddedAndChangesEtag(): void
    {
        $site = $this->factory->site(['cookieLevelEnabled' => true]);
        $first = $this->get('/t/' . $site->publicKey . '.js');
        self::assertNull(self::config((string) $first->getBody())['consent']);

        $consent = $this->service(ConsentService::class);
        $consent->saveDraft($site->id(), new Input(ConsentService::defaults()));
        $consent->publish($site->id(), false, 1);

        $second = $this->get('/t/' . $site->publicKey . '.js');
        $config = self::config((string) $second->getBody());
        self::assertIsArray($config['consent']);
        self::assertSame(1, $config['consent']['v']);
        self::assertSame('Accetta', $config['consent']['texts']['it']['accept']);
        self::assertSame('https://www.example.com/it/privacy', $config['consent']['texts']['it']['policyUrl']);
        self::assertStringStartsWith('.b,.f{position:fixed', $config['consent']['css'], 'the compiled banner stylesheet');
        self::assertIsString($config['consent']['ri']);
        self::assertArrayNotHasKey('theme', $config['consent']);
        self::assertNotSame($first->getHeaderLine('ETag'), $second->getHeaderLine('ETag'));
    }

    public function testUnknownOrArchivedSite(): void
    {
        $this->assertStatus(404, $this->get('/t/pk_' . str_repeat('A', 21) . '.js'));
        $site = $this->factory->site(['archivedAt' => new \DateTimeImmutable()]);
        $this->assertStatus(404, $this->get('/t/' . $site->publicKey . '.js'));
    }
}
