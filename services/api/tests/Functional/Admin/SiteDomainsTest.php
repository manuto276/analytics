<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Admin;

use Analytics\Tests\Support\HttpTestCase;

/**
 * Domains accept the notation the CLI and the docs use: "*.example.com" is example.com and its subdomains.
 */
final class SiteDomainsTest extends HttpTestCase
{
    public function testCreateAcceptsWildcardsBareHostsAndUrls(): void
    {
        $this->loginAs($this->factory->admin());
        $site = $this->data($this->post('/api/v1/sites', ['name' => 'Mixed', 'domains' => [
            ['host' => '*.frascella.dev', 'include_subdomains' => false],
            ['host' => 'skeda.fit', 'include_subdomains' => false],
            ['host' => 'https://www.example.com/some/page?x=1', 'include_subdomains' => false],
        ]]), 201);

        self::assertSame([
            ['host' => 'frascella.dev', 'include_subdomains' => true],
            ['host' => 'skeda.fit', 'include_subdomains' => false],
            ['host' => 'www.example.com', 'include_subdomains' => false],
        ], self::sorted($site['domains']));
    }

    public function testUpdateAcceptsWildcardsAndKeepsAnExplicitFlag(): void
    {
        $this->loginAs($this->factory->admin());
        $site = $this->factory->site([], ['skeda.fit'], false);

        $updated = $this->data($this->patch('/api/v1/sites/' . $site->id(), ['domains' => [
            ['host' => 'skeda.fit', 'include_subdomains' => true],
            ['host' => '*.frascella.dev', 'include_subdomains' => false],
            ['host' => 'http://Blog.Example.com:8080/', 'include_subdomains' => false],
        ]]));

        self::assertSame([
            ['host' => 'blog.example.com', 'include_subdomains' => false],
            ['host' => 'frascella.dev', 'include_subdomains' => true],
            ['host' => 'skeda.fit', 'include_subdomains' => true],
        ], self::sorted($updated['domains']));
    }

    public function testGarbageHostIsRejectedWithItsIndex(): void
    {
        $this->loginAs($this->factory->admin());
        $response = $this->post('/api/v1/sites', ['name' => 'Bad', 'domains' => [
            ['host' => '*.frascella.dev', 'include_subdomains' => false],
            ['host' => 'not a host!', 'include_subdomains' => false],
        ]]);
        $this->assertProblem($response, 422);
        $errors = $this->json($response)['errors'] ?? [];
        self::assertIsArray($errors);
        self::assertArrayHasKey('domains.1.host', $errors);
        self::assertArrayNotHasKey('domains.0.host', $errors);

        $site = $this->factory->site([], ['skeda.fit'], false);
        $response = $this->patch('/api/v1/sites/' . $site->id(), ['domains' => [
            ['host' => 'skeda.fit', 'include_subdomains' => false],
            ['host' => 'www.example.com', 'include_subdomains' => false],
            ['host' => '*.', 'include_subdomains' => true],
        ]]);
        $this->assertProblem($response, 422);
        self::assertSame(['domains.2.host'], array_keys((array) ($this->json($response)['errors'] ?? [])));
    }

    /**
     * @return list<array{host: string, include_subdomains: bool}>
     */
    private static function sorted(mixed $domains): array
    {
        self::assertIsArray($domains);
        /** @var list<array{host: string, include_subdomains: bool}> $domains */
        usort($domains, static fn(array $a, array $b): int => strcmp($a['host'], $b['host']));

        return $domains;
    }
}
