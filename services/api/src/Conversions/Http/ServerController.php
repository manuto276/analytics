<?php

declare(strict_types=1);

namespace Analytics\Conversions\Http;

use Analytics\Conversions\Application\ConversionIngestHandler;
use Analytics\Kernel\Http\RequestContext;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\JsonResponder;
use Analytics\Shared\Types;
use Analytics\Sites\Domain\SiteSnapshot;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ServerController
{
    public function __construct(
        private JsonResponder $responder,
        private ConversionIngestHandler $conversions,
        private Connection $connection,
        private ClockInterface $clock,
    ) {}

    public function conversions(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            throw ApiProblem::badRequest('invalid_json', 'Expected a JSON object or array.');
        }
        $site = SiteSnapshot::fromSite(RequestContext::site($request));
        $result = $this->conversions->handle($site, $body);

        return $this->responder->json($result, 202);
    }

    /** Aggregated stats of one content key; groups smaller than min_group_size are suppressed. */
    public function contentStats(ServerRequestInterface $request, string $contentKey): ResponseInterface
    {
        $site = SiteSnapshot::fromSite(RequestContext::site($request));
        $days = $request->getQueryParams()['days'] ?? '30';
        if (!\is_string($days) || preg_match('/^\d{1,3}$/', $days) !== 1 || (int) $days < 1 || (int) $days > 395) {
            throw ApiProblem::validation(['days' => ['Must be between 1 and 395.']]);
        }
        if (mb_strlen($contentKey) > 128) {
            throw ApiProblem::validation(['contentKey' => ['Must be at most 128 characters.']]);
        }
        $to = $this->clock->now()->setTimezone($site->timezone());
        $from = $to->modify('-' . ((int) $days - 1) . ' days');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT channel, SUM(pageviews) AS pageviews, SUM(visits) AS visits, SUM(visitors) AS visitors, SUM(contacts) AS contacts
               FROM rollup_content_daily WHERE site_id = ? AND day BETWEEN ? AND ? AND content_key = ? GROUP BY channel',
            [$site->id, $from->format('Y-m-d'), $to->format('Y-m-d'), $contentKey],
        );
        $pageviews = 0;
        $visitors = 0;
        $contacts = 0;
        $channels = [];
        foreach ($rows as $row) {
            $pageviews += Types::int($row['pageviews']);
            $visitors += Types::int($row['visitors']);
            $contacts += Types::int($row['contacts']);
            $channels[Types::string($row['channel'])] = Types::int($row['visits']);
        }
        // `visitors` is 0 on sites that store no visitor hash at all (pageviews_only, base level), so the
        // suppression falls back to the strongest group measure the site does keep.
        $visits = array_sum($channels);
        $groupSize = match (true) {
            $visitors > 0 => $visitors,
            $visits > 0 => $visits,
            default => $pageviews,
        };
        $suppressed = $groupSize > 0 && $groupSize < $site->minGroupSize;

        return $this->responder->data([
            'content_key' => $contentKey,
            'days' => (int) $days,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'pageviews' => $suppressed ? null : $pageviews,
            'visitors' => $suppressed ? null : $visitors,
            'contacts' => $suppressed ? null : $contacts,
            'suppressed' => $suppressed,
            'channels' => $suppressed ? (object) [] : (object) $channels,
        ]);
    }
}
