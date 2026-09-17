<?php

declare(strict_types=1);

namespace Analytics\Consent\Http;

use Analytics\Audit\Application\AuditLogger;
use Analytics\Consent\Application\ConsentService;
use Analytics\Kernel\Http\RequestContext;
use Analytics\Shared\Http\JsonResponder;
use Analytics\Sites\Application\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ConsentController
{
    public function __construct(
        private JsonResponder $responder,
        private ConsentService $consent,
        private SiteRepository $sites,
        private AuditLogger $audit,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $published = $this->consent->published($site->id());
        $draft = $this->consent->draft($site->id());

        return $this->responder->data([
            'published' => $published === null ? null : ConsentService::toArray($published),
            'draft' => $draft === null ? null : ConsentService::toArray($draft),
            'defaults' => ConsentService::defaults(),
        ]);
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

    public function history(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);

        return $this->responder->data(array_map(ConsentService::toArray(...), $this->consent->history($site->id())));
    }
}
