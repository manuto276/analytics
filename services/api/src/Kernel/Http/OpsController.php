<?php

declare(strict_types=1);

namespace Analytics\Kernel\Http;

use Analytics\Kernel\Settings;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\JsonResponder;
use Analytics\Shared\Http\RequestAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Local operations endpoint used after a release switches the `current` symlink, so OPcache does
 * not keep serving the previous release. Requires OPS_TOKEN and a loopback client.
 */
final readonly class OpsController
{
    public function __construct(private JsonResponder $responder, private Settings $settings) {}

    public function resetOpcache(ServerRequestInterface $request): ResponseInterface
    {
        $token = $this->settings->opsToken;
        if ($token === null || $token === '') {
            throw ApiProblem::notFound('Operations endpoints are disabled (set OPS_TOKEN to enable them).');
        }
        $prefix = RequestContext::ipPrefix($request);
        if ($prefix === null || !($prefix->matches('127.0.0.0/8') || $prefix->matches('::1'))) {
            throw ApiProblem::forbidden('Operations endpoints only answer on the loopback interface.');
        }
        // Behind a reverse proxy REMOTE_ADDR is the proxy itself, so "loopback" would otherwise be true
        // for every remote client. A forwarded chain from a peer we do not trust cannot be believed, and
        // a request carrying one did not originate on this host.
        if ($request->getAttribute(RequestAttributes::IP_UNVERIFIED_PROXY) === true) {
            throw ApiProblem::forbidden('Operations endpoints only answer on the loopback interface.');
        }
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ') || !hash_equals($token, trim(substr($header, 7)))) {
            throw ApiProblem::unauthorized('Invalid operations token.');
        }

        $reset = \function_exists('opcache_reset') && opcache_reset();

        return $this->responder->json(['reset' => $reset, 'enabled' => \function_exists('opcache_get_status')])->withHeader('Cache-Control', 'no-store');
    }

}
