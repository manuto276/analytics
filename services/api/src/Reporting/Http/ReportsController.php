<?php

declare(strict_types=1);

namespace Analytics\Reporting\Http;

use Analytics\Kernel\Http\RequestContext;
use Analytics\Reporting\Application\CsvExporter;
use Analytics\Reporting\Application\ReportQueryFactory;
use Analytics\Reporting\Application\ReportService;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\JsonResponder;
use Analytics\Sites\Domain\SiteSnapshot;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ReportsController
{
    public function __construct(
        private JsonResponder $responder,
        private ReportQueryFactory $queries,
        private ReportService $reports,
    ) {}

    public function overview(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, 'overview');
    }

    public function timeseries(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, 'timeseries');
    }

    public function pages(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, 'pages', ['kind' => $this->choice($request, 'kind', ['top', 'entry', 'exit'], 'top')]);
    }

    public function landingPages(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, 'landing-pages');
    }

    public function sources(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, 'sources', ['group' => $this->choice($request, 'group', ['channel', 'source', 'referrer'], 'channel')]);
    }

    public function campaigns(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, 'campaigns');
    }

    public function tech(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, 'tech', ['group' => $this->choice($request, 'group', ['device', 'browser', 'os'], 'device')]);
    }

    public function countries(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, 'countries');
    }

    public function events(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, 'events');
    }

    public function eventProps(ServerRequestInterface $request, string $eventName): ResponseInterface
    {
        if (preg_match('/^[a-z0-9_:.-]{1,64}$/', $eventName) !== 1) {
            throw ApiProblem::notFound('Unknown event.');
        }

        return $this->respond($request, 'event-props', ['event' => $eventName]);
    }

    public function content(ServerRequestInterface $request): ResponseInterface
    {
        $prefix = $request->getQueryParams()['prefix'] ?? '';
        if (!\is_string($prefix) || mb_strlen($prefix) > 128) {
            throw ApiProblem::validation(['prefix' => ['Must be at most 128 characters.']]);
        }

        return $this->respond($request, 'content', ['prefix' => $prefix]);
    }

    public function realtime(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, 'realtime');
    }

    public function goals(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, 'goals');
    }

    public function conversions(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, 'conversions');
    }

    public function funnel(ServerRequestInterface $request, string $funnelId): ResponseInterface
    {
        return $this->respond($request, 'funnels', [
            'funnel' => $funnelId,
            'breakdown' => $this->choice($request, 'breakdown', ['none', 'channel', 'device'], 'none'),
        ]);
    }

    public function attribution(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $name = static function (mixed $value): string {
            return \is_string($value) && preg_match('/^[a-z0-9_:.-]{1,64}$/', $value) === 1 ? $value : '';
        };

        return $this->respond($request, 'attribution', [
            'model' => $this->choice($request, 'model', \Analytics\Reporting\Application\Reports\AttributionReport::MODELS, 'first_touch'),
            'group' => $this->choice($request, 'group', \Analytics\Reporting\Application\Reports\AttributionReport::GROUPS, 'channel'),
            'window' => $this->choice($request, 'window', ['7', '30', '90'], '30'),
            'base' => $name($params['base'] ?? ''),
            'target' => $name($params['target'] ?? ''),
        ]);
    }

    public function consent(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, 'consent');
    }

    public function cohorts(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $periods = $params['periods'] ?? '6';

        return $this->respond($request, 'cohorts', [
            'cohort' => $this->choice($request, 'cohort', ['week', 'month'], 'month'),
            'periods' => \is_string($periods) && preg_match('/^\d{1,2}$/', $periods) === 1 ? $periods : '6',
        ]);
    }

    /** @param array<string, string> $options */
    private function respond(ServerRequestInterface $request, string $report, array $options = []): ResponseInterface
    {
        $site = SiteSnapshot::fromSite(RequestContext::site($request));
        $query = $this->queries->create($site, $request->getQueryParams(), $options);
        $result = $this->reports->run($report, $query);

        $wantsCsv = str_contains(strtolower($request->getHeaderLine('Accept')), 'text/csv');
        if ($wantsCsv) {
            $rows = $result['data']['rows'] ?? $result['data']['points'] ?? null;
            if (!\is_array($rows)) {
                throw new ApiProblem(406, 'csv_unavailable', 'Not acceptable', 'This report cannot be exported as CSV.');
            }
            /** @var list<array<string, mixed>> $rows */
            $filename = \sprintf('%s-%s-%s.csv', $report, $query->range->fromDay(), $query->range->toDay());

            return $this->responder->csv(CsvExporter::fromRows($rows), $filename)->withHeader('Cache-Control', 'private, max-age=30');
        }

        $body = ['data' => $result['data'], 'meta' => $result['meta']];
        $etag = '"' . substr(hash('xxh128', json_encode($body['data'], \JSON_THROW_ON_ERROR)), 0, 24) . '"';
        $response = $this->responder->json($body)
            ->withHeader('Cache-Control', 'private, max-age=30')
            ->withHeader('ETag', $etag);
        $ifNoneMatch = array_map('trim', explode(',', $request->getHeaderLine('If-None-Match')));
        if (\in_array($etag, $ifNoneMatch, true)) {
            return $response->withStatus(304)->withBody(new \Slim\Psr7\Stream(self::emptyStream()));
        }

        return $response;
    }

    /** @return resource */
    private static function emptyStream()
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open a temporary stream.');
        }

        return $stream;
    }

    /** @param list<string> $allowed */
    private function choice(ServerRequestInterface $request, string $name, array $allowed, string $default): string
    {
        $value = $request->getQueryParams()[$name] ?? $default;
        if (!\is_string($value) || !\in_array($value, $allowed, true)) {
            throw ApiProblem::validation([$name => ['Must be one of: ' . implode(', ', $allowed) . '.']]);
        }

        return $value;
    }
}
