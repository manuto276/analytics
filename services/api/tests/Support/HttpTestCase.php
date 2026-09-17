<?php

declare(strict_types=1);

namespace Analytics\Tests\Support;

use Analytics\Identity\Application\SessionManager;
use Analytics\Identity\Domain\SessionState;
use Analytics\Identity\Domain\User;
use Analytics\Kernel\AppFactory;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * In-process HTTP tests against the real Slim app, container and test database.
 * Requests and responses under /api/v1 are validated against docs/api/openapi.yaml.
 */
abstract class HttpTestCase extends IntegrationTestCase
{
    public const string CLIENT_IP = '203.0.113.77';
    public const string ORIGIN = 'https://analytics.test';

    /** @var App<ContainerInterface>|null */
    private static ?App $app = null;
    private static ?object $appContainer = null;
    private static ?ValidatorBuilder $openApi = null;

    /** @var array<string, string> */
    protected array $cookies = [];
    protected ?string $csrfToken = null;
    protected bool $validateOpenApi = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cookies = [];
        $this->csrfToken = null;
    }

    /** @return App<ContainerInterface> */
    protected function app(): App
    {
        if (self::$app === null || self::$appContainer !== $this->container) {
            self::$app = AppFactory::create($this->container);
            self::$appContainer = $this->container;
        }

        return self::$app;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    protected function request(string $method, string $uri, mixed $body = null, array $headers = [], array $server = []): ResponseInterface
    {
        $request = $this->buildRequest($method, $uri, $body, $headers, $server);
        $path = $request->getUri()->getPath();
        $validate = $this->validateOpenApi && str_starts_with($path, '/api/v1/');
        $response = $this->app()->handle($request);
        if ($validate && $response->getStatusCode() < 400) {
            // Negative tests send invalid requests on purpose; only successful ones must match the contract.
            $request->getBody()->rewind();
            $this->openApi()->getServerRequestValidator()->validate($request);
        }
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            $this->storeCookie($cookie);
        }
        if ($validate && $response->getStatusCode() !== 500) {
            $response->getBody()->rewind();
            $this->openApi()->getResponseValidator()->validate(new OperationAddress($this->openApiPath($request), strtolower($method)), $response);
            $response->getBody()->rewind();
        }

        return $response;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    protected function buildRequest(string $method, string $uri, mixed $body = null, array $headers = [], array $server = []): ServerRequestInterface
    {
        $server += ['REMOTE_ADDR' => self::CLIENT_IP, 'HTTPS' => 'on', 'SERVER_NAME' => 'analytics.test'];
        $request = new ServerRequestFactory()->createServerRequest($method, 'https://analytics.test' . $uri, $server);
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        $headers += ['Accept' => 'application/json', 'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15'];
        if (!\in_array($method, ['GET', 'HEAD'], true)) {
            $headers += ['Origin' => self::ORIGIN];
            if ($this->csrfToken !== null) {
                $headers += ['X-CSRF-Token' => $this->csrfToken];
            }
        }
        if ($body !== null) {
            if (\is_string($body)) {
                $headers += ['Content-Type' => 'text/plain'];
                $raw = $body;
            } else {
                $headers += ['Content-Type' => 'application/json'];
                $raw = json_encode($body, \JSON_THROW_ON_ERROR);
            }
            $request = $request->withBody(new StreamFactory()->createStream($raw));
            $headers += ['Content-Length' => (string) \strlen($raw)];
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($this->cookies !== [] && !isset($headers['Cookie'])) {
            return $request->withCookieParams($this->cookies)->withHeader('Cookie', implode('; ', array_map(static fn(string $k, string $v): string => $k . '=' . $v, array_keys($this->cookies), $this->cookies)));
        }

        return $request;
    }

    protected function get(string $uri, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $uri, null, $headers);
    }

    protected function post(string $uri, mixed $body = [], array $headers = []): ResponseInterface
    {
        return $this->request('POST', $uri, $body, $headers);
    }

    protected function patch(string $uri, mixed $body = [], array $headers = []): ResponseInterface
    {
        return $this->request('PATCH', $uri, $body, $headers);
    }

    protected function put(string $uri, mixed $body = [], array $headers = []): ResponseInterface
    {
        return $this->request('PUT', $uri, $body, $headers);
    }

    protected function delete(string $uri, mixed $body = null, array $headers = []): ResponseInterface
    {
        return $this->request('DELETE', $uri, $body, $headers);
    }

    /**
     * Sends a tracking batch like a browser on the tracked site would (sendBeacon, text/plain).
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    protected function collect(array $payload, array $headers = [], string $ip = self::CLIENT_IP): ResponseInterface
    {
        $headers += [
            'Origin' => 'https://www.site.test',
            'User-Agent' => Payloads::CHROME_UA,
            'Accept-Language' => 'it-IT,it;q=0.9,en;q=0.8',
            'Content-Type' => 'text/plain;charset=UTF-8',
        ];

        return $this->request('POST', '/t/e', json_encode($payload, \JSON_THROW_ON_ERROR), $headers, ['REMOTE_ADDR' => $ip]);
    }

    /** Signs in by creating a session directly (no password round trip). */
    protected function loginAs(User $user): void
    {
        $sessions = $this->service(SessionManager::class);
        [$token, $session] = $sessions->start($user, SessionState::Active, null, 'test');
        $this->cookies[$sessions->cookieName()] = $token;
        $this->csrfToken = $session->csrfSecret;
    }

    protected function logout(): void
    {
        $this->cookies = [];
        $this->csrfToken = null;
    }

    /** @return array<array-key, mixed> */
    protected function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data, 'Response is not JSON: ' . $response->getBody());

        return $data;
    }

    /** @return array<array-key, mixed> */
    protected function data(ResponseInterface $response, int $status = 200): array
    {
        $this->assertStatus($status, $response);
        $json = $this->json($response);
        self::assertArrayHasKey('data', $json);
        self::assertIsArray($json['data']);

        return $json['data'];
    }

    protected function assertStatus(int $expected, ResponseInterface $response): void
    {
        $response->getBody()->rewind();
        self::assertSame($expected, $response->getStatusCode(), 'Unexpected status. Body: ' . $response->getBody());
    }

    protected function assertProblem(ResponseInterface $response, int $status, ?string $code = null): void
    {
        $this->assertStatus($status, $response);
        self::assertStringStartsWith('application/problem+json', $response->getHeaderLine('Content-Type'));
        if ($code !== null) {
            self::assertSame($code, $this->json($response)['code'] ?? null);
        }
    }

    private function storeCookie(string $header): void
    {
        $parts = explode(';', $header);
        [$name, $value] = array_pad(explode('=', trim($parts[0]), 2), 2, '');
        $expired = false;
        foreach ($parts as $part) {
            if (preg_match('/^\s*max-age=(\d+)/i', $part, $m) === 1 && (int) $m[1] === 0) {
                $expired = true;
            }
        }
        if ($expired || $value === '') {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }
    }

    private function openApi(): ValidatorBuilder
    {
        return self::$openApi ??= new ValidatorBuilder()->fromYamlFile(\dirname(__DIR__, 4) . '/docs/api/openapi.yaml');
    }

    private function openApiPath(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        $schema = $this->openApi()->getServerRequestValidator()->getSchema();
        $method = strtolower($request->getMethod());
        foreach ($schema->paths as $pattern => $item) {
            $regex = '#^' . preg_replace('#\\\{[^}]+\\\}#', '[^/]+', preg_quote('/api/v1' . $pattern, '#')) . '$#';
            if (preg_match($regex, $path) === 1 && isset($item->{$method})) {
                return (string) $pattern;
            }
        }

        return $path;
    }
}
