<?php

declare(strict_types=1);

namespace Analytics\Consent\Http;

use Analytics\Audit\Application\AuditLogger;
use Analytics\Consent\Application\ConsentService;
use Analytics\Consent\Domain\ConsentThemeV2;
use Analytics\Kernel\Http\RequestContext;
use Analytics\Shared\Crypto\Base64Url;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\JsonResponder;
use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ConsentController
{
    public function __construct(
        private JsonResponder $responder,
        private ConsentService $consent,
        private SiteRepository $sites,
        private AuditLogger $audit,
        private Connection $connection,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $published = $this->consent->published($site->id());
        $draft = $this->consent->draft($site->id());

        return $this->responder->data([
            'published' => $published === null ? null : ConsentService::toArray($published),
            'draft' => $draft === null ? null : ConsentService::toArray($draft),
            'defaults' => ConsentService::defaults() + ['theme_v2' => ConsentThemeV2::defaults()->toArray()],
        ]);
    }

    /**
     * The consent block of window.__an_cfg that an unsaved configuration would produce (compiled
     * stylesheet, reopen icon, texts…), for the dashboard preview. Same validation as saving the
     * draft; nothing is stored, so nothing is audited.
     */
    public function preview(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);

        return $this->responder->data($this->consent->preview($site->id(), RequestContext::body($request)));
    }

    public function saveDraft(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $draft = $this->consent->saveDraft($site->id(), RequestContext::body($request));
        $this->audit->log('consent.draft_saved', RequestContext::actor($request), $site->id(), 'consent_config', $draft->id());

        return $this->responder->data(ConsentService::toArray($draft));
    }

    public function discardDraft(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $this->consent->discardDraft($site->id());
        $this->audit->log('consent.draft_discarded', RequestContext::actor($request), $site->id(), 'site', $site->id());

        return $this->responder->noContent();
    }

    public function publish(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $input = RequestContext::body($request);
        $material = $input->bool('material_change');
        $input->assertValid();
        $config = $this->consent->publish($site->id(), $material, RequestContext::user($request)->id());
        $this->sites->forgetSnapshot($site);
        $this->audit->log('consent.published', RequestContext::actor($request), $site->id(), 'consent_config', $config->id(), ['revision' => $config->revision, 'consent_version' => $config->consentVersion, 'material_change' => $material]);

        return $this->responder->data(ConsentService::toArray($config));
    }

    /**
     * Proof of consent for one visitor id (the visitor provides it, e.g. from an_vid or
     * analytics.getVisitorId()). Only available when the site stores consent receipts.
     */
    public function receipts(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        if (!$site->consentReceiptsEnabled) {
            throw ApiProblem::conflict('receipts_disabled', 'Consent receipts are not enabled for this site.');
        }
        $visitorId = $request->getQueryParams()['visitor_id'] ?? '';
        $raw = \is_string($visitorId) && preg_match('/^[A-Za-z0-9_-]{22}$/', $visitorId) === 1 ? Base64Url::decode($visitorId) : null;
        if ($raw === null || \strlen($raw) !== 16) {
            throw ApiProblem::validation(['visitor_id' => ['Must be a visitor id (22 characters).']]);
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT consent_version, decision, decided_at FROM consent_receipts WHERE site_id = ? AND visitor_id = ? ORDER BY decided_at DESC LIMIT 100',
            [$site->id(), $raw],
            [ParameterType::INTEGER, ParameterType::BINARY],
        );
        $this->audit->log('consent.receipts_read', RequestContext::actor($request), $site->id(), 'site', $site->id());

        return $this->responder->data(array_map(static fn(array $row): array => [
            'consent_version' => Types::int($row['consent_version']),
            'decision' => Types::string($row['decision']),
            'decided_at' => new \DateTimeImmutable(Types::string($row['decided_at']), new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        ], $rows));
    }

    public function history(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);

        return $this->responder->data(array_map(ConsentService::toArray(...), $this->consent->history($site->id())));
    }
}
