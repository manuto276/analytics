<?php

declare(strict_types=1);

namespace Analytics\Conversions\Http;

use Analytics\Audit\Application\AuditLogger;
use Analytics\Conversions\Application\ApiKeyService;
use Analytics\Conversions\Application\CostImporter;
use Analytics\Conversions\Domain\CampaignCost;
use Analytics\Conversions\Domain\Funnel;
use Analytics\Conversions\Domain\FunnelStep;
use Analytics\Conversions\Domain\Goal;
use Analytics\Conversions\Domain\GoalType;
use Analytics\Kernel\Http\RequestContext;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\JsonResponder;
use Analytics\Shared\Http\RequestAttributes;
use Analytics\Shared\Types;
use Analytics\Shared\Validation\Input;
use Analytics\Sites\Domain\SiteSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Site settings for conversions: API keys, goals, funnels and campaign costs.
 */
final readonly class ConversionsAdminController
{
    public function __construct(
        private JsonResponder $responder,
        private EntityManagerInterface $em,
        private ApiKeyService $apiKeys,
        private CostImporter $costImporter,
        private AuditLogger $audit,
        private ClockInterface $clock,
    ) {}

    // --- API keys -----------------------------------------------------------

    public function listApiKeys(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);

        return $this->responder->data(array_map(ApiKeyService::toArray(...), $this->apiKeys->forSite($site->id())));
    }

    public function createApiKey(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $input = RequestContext::body($request);
        $name = $input->string('name', 120);
        $scopes = $input->stringList('scopes', 5, 32, null, true);
        $expiresRaw = $input->optionalString('expires_at', 40);
        $input->assertValid();
        $expiresAt = null;
        if ($expiresRaw !== null) {
            try {
                $expiresAt = new \DateTimeImmutable($expiresRaw)->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                throw ApiProblem::validation(['expires_at' => ['Must be an ISO 8601 date and time.']]);
            }
            if ($expiresAt <= $this->clock->now()) {
                throw ApiProblem::validation(['expires_at' => ['Must be in the future.']]);
            }
        }

        [$key, $secret] = $this->apiKeys->create($site->id(), $name, $scopes, RequestContext::user($request)->id(), $expiresAt);
        $this->audit->log('api_key.created', RequestContext::actor($request), $site->id(), 'api_key', $key->id(), ['scopes' => $scopes, 'key_prefix' => $key->prefix]);

        return $this->responder->json(['data' => ApiKeyService::toArray($key) + ['secret' => $secret]], 201);
    }

    public function revokeApiKey(ServerRequestInterface $request, string $keyId): ResponseInterface
    {
        $site = RequestContext::site($request);
        $key = $this->apiKeys->revoke($site->id(), (int) $keyId);
        $this->audit->log('api_key.revoked', RequestContext::actor($request), $site->id(), 'api_key', $key->id());

        return $this->responder->noContent();
    }

    // --- Goals --------------------------------------------------------------

    public function listGoals(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);

        return $this->responder->data(array_map(self::goalToArray(...), $this->goals($site->id())));
    }

    public function createGoal(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $goal = new Goal($site->id(), '', GoalType::Pageview, []);
        $this->applyGoal($goal, RequestContext::body($request), true);
        $this->assertGoalNameFree($site->id(), $goal->name, null);
        $this->em->persist($goal);
        $this->em->flush();
        $this->audit->log('goal.created', RequestContext::actor($request), $site->id(), 'goal', $goal->id(), ['name' => $goal->name]);

        return $this->responder->json(['data' => self::goalToArray($goal)], 201);
    }

    public function updateGoal(ServerRequestInterface $request, string $goalId): ResponseInterface
    {
        $site = RequestContext::site($request);
        $goal = $this->goal($site->id(), (int) $goalId);
        $this->applyGoal($goal, RequestContext::body($request), false);
        $this->assertGoalNameFree($site->id(), $goal->name, $goal->id());
        $this->em->flush();
        $this->audit->log('goal.updated', RequestContext::actor($request), $site->id(), 'goal', $goal->id());

        return $this->responder->data(self::goalToArray($goal));
    }

    public function deleteGoal(ServerRequestInterface $request, string $goalId): ResponseInterface
    {
        $site = RequestContext::site($request);
        $goal = $this->goal($site->id(), (int) $goalId);
        $used = $this->em->getRepository(FunnelStep::class)->findOneBy(['goalId' => $goal->id()]);
        if ($used instanceof FunnelStep) {
            throw ApiProblem::conflict('goal_in_use', 'This goal is used by a funnel; remove it from the funnel first.');
        }
        $this->em->remove($goal);
        $this->em->flush();
        $this->audit->log('goal.deleted', RequestContext::actor($request), $site->id(), 'goal', $goalId);

        return $this->responder->noContent();
    }

    // --- Funnels ------------------------------------------------------------

    public function listFunnels(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        /** @var list<Funnel> $funnels */
        $funnels = $this->em->getRepository(Funnel::class)->findBy(['siteId' => $site->id()], ['name' => 'ASC']);

        return $this->responder->data(array_map(fn(Funnel $f): array => $this->funnelToArray($f), $funnels));
    }

    public function createFunnel(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $funnel = new Funnel($site->id(), '', 'visit', 30);
        $this->applyFunnel($funnel, RequestContext::body($request), true);
        $this->em->persist($funnel);
        $this->em->flush();
        $this->audit->log('funnel.created', RequestContext::actor($request), $site->id(), 'funnel', $funnel->id(), ['name' => $funnel->name]);

        return $this->responder->json(['data' => $this->funnelToArray($funnel)], 201);
    }

    public function updateFunnel(ServerRequestInterface $request, string $funnelId): ResponseInterface
    {
        $site = RequestContext::site($request);
        $funnel = $this->funnel($site->id(), (int) $funnelId);
        $this->applyFunnel($funnel, RequestContext::body($request), false);
        $this->em->flush();
        $this->audit->log('funnel.updated', RequestContext::actor($request), $site->id(), 'funnel', $funnel->id());

        return $this->responder->data($this->funnelToArray($funnel));
    }

    public function deleteFunnel(ServerRequestInterface $request, string $funnelId): ResponseInterface
    {
        $site = RequestContext::site($request);
        $funnel = $this->funnel($site->id(), (int) $funnelId);
        $this->em->remove($funnel);
        $this->em->flush();
        $this->audit->log('funnel.deleted', RequestContext::actor($request), $site->id(), 'funnel', $funnelId);

        return $this->responder->noContent();
    }

    // --- Campaign costs -----------------------------------------------------

    public function listCosts(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $params = $request->getQueryParams();
        $qb = $this->em->createQueryBuilder()->select('c')->from(CampaignCost::class, 'c')
            ->where('c.siteId = :site')->setParameter('site', $site->id())
            ->orderBy('c.dayFrom', 'DESC')->addOrderBy('c.id', 'DESC')->setMaxResults(1000);
        foreach (['from' => 'dayTo', 'to' => 'dayFrom'] as $param => $field) {
            if (isset($params[$param])) {
                if (!\is_string($params[$param]) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $params[$param]) !== 1) {
                    throw ApiProblem::validation([$param => ['Must be a date (YYYY-MM-DD).']]);
                }
                $qb->andWhere('c.' . $field . ($param === 'from' ? ' >= ' : ' <= ') . ':' . $param)->setParameter($param, new \DateTimeImmutable($params[$param]));
            }
        }
        /** @var list<CampaignCost> $costs */
        $costs = $qb->getQuery()->getResult();

        return $this->responder->data(array_map(self::costToArray(...), $costs));
    }

    public function createCost(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $values = $this->costValues(RequestContext::body($request), SiteSnapshot::fromSite($site), true);
        $cost = new CampaignCost(
            siteId: $site->id(),
            dayFrom: $values['day_from'],
            dayTo: $values['day_to'],
            channel: $values['channel'],
            utmSource: $values['utm_source'],
            utmMedium: $values['utm_medium'],
            utmCampaign: $values['utm_campaign'],
            amountMinor: (string) $values['amount_minor'],
            currency: $values['currency'],
            note: $values['note'],
            importBatchId: null,
            createdBy: RequestContext::user($request)->id(),
            createdAt: $this->clock->now(),
        );
        $this->em->persist($cost);
        $this->em->flush();
        $this->audit->log('cost.created', RequestContext::actor($request), $site->id(), 'campaign_cost', $cost->id());

        return $this->responder->json(['data' => self::costToArray($cost)], 201);
    }

    public function updateCost(ServerRequestInterface $request, string $costId): ResponseInterface
    {
        $site = RequestContext::site($request);
        $cost = $this->em->find(CampaignCost::class, (int) $costId);
        if (!$cost instanceof CampaignCost || $cost->siteId !== $site->id()) {
            throw ApiProblem::notFound('Cost entry not found.');
        }
        $values = $this->costValues(RequestContext::body($request), SiteSnapshot::fromSite($site), false, $cost);
        $cost->dayFrom = $values['day_from'];
        $cost->dayTo = $values['day_to'];
        $cost->channel = $values['channel'];
        $cost->utmSource = $values['utm_source'];
        $cost->utmMedium = $values['utm_medium'];
        $cost->utmCampaign = $values['utm_campaign'];
        $cost->amountMinor = (string) $values['amount_minor'];
        $cost->currency = $values['currency'];
        $cost->note = $values['note'];
        $this->em->flush();
        $this->audit->log('cost.updated', RequestContext::actor($request), $site->id(), 'campaign_cost', $cost->id());

        return $this->responder->data(self::costToArray($cost));
    }

    public function deleteCost(ServerRequestInterface $request, string $costId): ResponseInterface
    {
        $site = RequestContext::site($request);
        $cost = $this->em->find(CampaignCost::class, (int) $costId);
        if (!$cost instanceof CampaignCost || $cost->siteId !== $site->id()) {
            throw ApiProblem::notFound('Cost entry not found.');
        }
        $this->em->remove($cost);
        $this->em->flush();
        $this->audit->log('cost.deleted', RequestContext::actor($request), $site->id(), 'campaign_cost', $costId);

        return $this->responder->noContent();
    }

    public function importCosts(ServerRequestInterface $request): ResponseInterface
    {
        $site = RequestContext::site($request);
        $csv = $request->getAttribute(RequestAttributes::RAW_BODY);
        if (!\is_string($csv) || trim($csv) === '') {
            throw ApiProblem::validation(['csv' => ['Send the CSV file as the request body with Content-Type: text/csv.']]);
        }
        $dryRun = ($request->getQueryParams()['dry_run'] ?? '0') === '1';
        $result = $this->costImporter->import(SiteSnapshot::fromSite($site), $csv, $dryRun, RequestContext::user($request)->id());
        if ($result['imported'] === true) {
            $this->audit->log('cost.imported', RequestContext::actor($request), $site->id(), 'campaign_cost', null, ['rows' => $result['valid_rows'], 'batch_id' => $result['batch_id']]);
        }

        return $this->responder->data($result);
    }

    // --- helpers ------------------------------------------------------------

    /** @return list<Goal> */
    private function goals(int $siteId): array
    {
        /** @var list<Goal> $goals */
        $goals = $this->em->getRepository(Goal::class)->findBy(['siteId' => $siteId], ['name' => 'ASC']);

        return $goals;
    }

    private function goal(int $siteId, int $goalId): Goal
    {
        $goal = $this->em->find(Goal::class, $goalId);
        if (!$goal instanceof Goal || $goal->siteId !== $siteId) {
            throw ApiProblem::notFound('Goal not found.');
        }

        return $goal;
    }

    private function funnel(int $siteId, int $funnelId): Funnel
    {
        $funnel = $this->em->find(Funnel::class, $funnelId);
        if (!$funnel instanceof Funnel || $funnel->siteId !== $siteId) {
            throw ApiProblem::notFound('Funnel not found.');
        }

        return $funnel;
    }

    private function assertGoalNameFree(int $siteId, string $name, ?int $exceptId): void
    {
        $existing = $this->em->getRepository(Goal::class)->findOneBy(['siteId' => $siteId, 'name' => $name]);
        if ($existing instanceof Goal && $existing->id() !== $exceptId) {
            throw ApiProblem::conflict('goal_name_taken', 'A goal with this name already exists.');
        }
    }

    private function applyGoal(Goal $goal, Input $input, bool $creating): void
    {
        if ($creating || $input->has('name')) {
            $goal->name = $input->string('name', 120);
        }
        if ($creating || $input->has('type')) {
            $type = $input->enum('type', GoalType::class);
            if ($type instanceof GoalType) {
                $goal->type = $type;
            }
        }
        if ($creating || $input->has('match')) {
            $match = $input->nested('match', true);
            if ($match !== null) {
                $goal->match = match ($goal->type) {
                    GoalType::Pageview => ['path' => $match->string('path', 255, 1, '#^/\S*$#')],
                    GoalType::Event => array_filter([
                        'name' => $match->string('name', 64, 1, '/^[a-z0-9_:.-]{1,64}$/'),
                        'props' => self::props($match),
                    ], static fn(mixed $value): bool => $value !== null && $value !== []),
                    GoalType::Conversion => ['name' => $match->string('name', 64, 1, '/^[a-z0-9_:.-]{1,64}$/')],
                };
                $input->merge($match);
            }
        }
        $input->assertValid();
    }

    /** @return array<string, string>|null */
    private static function props(Input $match): ?array
    {
        $props = $match->array('props');
        if ($props === null) {
            return null;
        }
        $clean = [];
        foreach ($props as $key => $value) {
            if (!\is_scalar($value) || \strlen((string) $key) > 32) {
                $match->error('props', 'Properties must be short scalar values.');
                continue;
            }
            $clean[(string) $key] = (string) $value;
        }

        return \count($clean) > 1 ? \array_slice($clean, 0, 1) : $clean;
    }

    private function applyFunnel(Funnel $funnel, Input $input, bool $creating): void
    {
        if ($creating || $input->has('name')) {
            $funnel->name = $input->string('name', 120);
        }
        if ($creating || $input->has('scope')) {
            $funnel->scope = $input->choice('scope', ['visit', 'visitor'], 'visit');
        }
        if ($creating || $input->has('window_days')) {
            $funnel->windowDays = $input->int('window_days', 30, 1, 90);
        }
        if ($creating || $input->has('goal_ids')) {
            $ids = $input->array('goal_ids', $creating) ?? [];
            $goalIds = [];
            foreach ($ids as $id) {
                if (!\is_int($id) && !(\is_string($id) && ctype_digit($id))) {
                    $input->error('goal_ids', 'Must be a list of goal ids.');
                    continue;
                }
                $goalIds[] = (int) $id;
            }
            if (\count($goalIds) < 2 || \count($goalIds) > 10) {
                $input->error('goal_ids', 'A funnel needs between 2 and 10 steps.');
            }
            foreach ($goalIds as $goalId) {
                $goal = $this->em->find(Goal::class, $goalId);
                if (!$goal instanceof Goal || $goal->siteId !== $funnel->siteId) {
                    $input->error('goal_ids', 'Goal ' . $goalId . ' does not exist for this site.');
                }
            }
            $input->assertValid();
            $funnel->replaceSteps($goalIds);
        }
        $input->assertValid();
    }

    /**
     * @return array{day_from: \DateTimeImmutable, day_to: \DateTimeImmutable, channel: ?string, utm_source: ?string, utm_medium: ?string, utm_campaign: ?string, amount_minor: int, currency: string, note: ?string}
     */
    private function costValues(Input $input, SiteSnapshot $site, bool $creating, ?CampaignCost $existing = null): array
    {
        $dayFrom = $input->has('day_from') || $creating ? $input->string('day_from', 10, 10, '/^\d{4}-\d{2}-\d{2}$/') : ($existing === null ? '' : $existing->dayFrom->format('Y-m-d'));
        $dayTo = $input->has('day_to') ? $input->string('day_to', 10, 10, '/^\d{4}-\d{2}-\d{2}$/') : ($existing?->dayTo->format('Y-m-d') ?? $dayFrom);
        $amount = $input->has('amount_minor') || $creating ? $input->int('amount_minor', null, 0, 1_000_000_000_000) : Types::int($existing?->amountMinor);
        $currency = $input->has('currency') ? $input->string('currency', 3, 3, '/^[A-Z]{3}$/') : ($existing === null ? $site->currency : $existing->currency);
        $values = [
            'channel' => $input->has('channel') ? $input->optionalString('channel', 32) : $existing?->channel,
            'utm_source' => $input->has('utm_source') ? $input->optionalString('utm_source', 100) : $existing?->utmSource,
            'utm_medium' => $input->has('utm_medium') ? $input->optionalString('utm_medium', 100) : $existing?->utmMedium,
            'utm_campaign' => $input->has('utm_campaign') ? $input->optionalString('utm_campaign', 100) : $existing?->utmCampaign,
            'note' => $input->has('note') ? $input->optionalString('note', 255) : $existing?->note,
        ];
        if ($dayTo !== '' && $dayFrom !== '' && $dayTo < $dayFrom) {
            $input->error('day_to', 'Must not be before day_from.');
        }
        $input->assertValid();

        return [
            'day_from' => new \DateTimeImmutable($dayFrom),
            'day_to' => new \DateTimeImmutable($dayTo),
            'channel' => $values['channel'],
            'utm_source' => $values['utm_source'],
            'utm_medium' => $values['utm_medium'],
            'utm_campaign' => $values['utm_campaign'],
            'amount_minor' => $amount,
            'currency' => $currency,
            'note' => $values['note'],
        ];
    }

    /** @return array<string, mixed> */
    public static function goalToArray(Goal $goal): array
    {
        return ['id' => $goal->id(), 'name' => $goal->name, 'type' => $goal->type->value, 'match' => (object) $goal->match];
    }

    /** @return array<string, mixed> */
    public function funnelToArray(Funnel $funnel): array
    {
        $steps = [];
        foreach ($funnel->steps as $step) {
            $goal = $this->em->find(Goal::class, $step->goalId);
            $steps[] = [
                'position' => $step->position,
                'goal_id' => $step->goalId,
                'goal_name' => $goal instanceof Goal ? $goal->name : 'deleted goal',
            ];
        }

        return ['id' => $funnel->id(), 'name' => $funnel->name, 'scope' => $funnel->scope, 'window_days' => $funnel->windowDays, 'steps' => $steps];
    }

    /** @return array<string, mixed> */
    public static function costToArray(CampaignCost $cost): array
    {
        return [
            'id' => $cost->id(),
            'day_from' => $cost->dayFrom->format('Y-m-d'),
            'day_to' => $cost->dayTo->format('Y-m-d'),
            'channel' => $cost->channel,
            'utm_source' => $cost->utmSource,
            'utm_medium' => $cost->utmMedium,
            'utm_campaign' => $cost->utmCampaign,
            'amount_minor' => Types::int($cost->amountMinor),
            'currency' => $cost->currency,
            'note' => $cost->note,
            'import_batch_id' => $cost->importBatchId,
            'created_at' => $cost->createdAt->format(\DATE_ATOM),
        ];
    }
}
