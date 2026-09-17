<?php

declare(strict_types=1);

namespace Analytics\Audit\Http;

use Analytics\Kernel\Http\RequestContext;
use Analytics\Reporting\Application\Cursor;
use Analytics\Reporting\Application\JobsStatus;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Http\JsonResponder;
use Analytics\Shared\Types;
use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AuditController
{
    public function __construct(
        private JsonResponder $responder,
        private Connection $connection,
        private JobsStatus $jobs,
    ) {}

    public function log(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit = isset($params['limit']) && is_numeric($params['limit']) ? max(1, min(1000, (int) $params['limit'])) : 50;
        $offset = Cursor::decode(\is_string($params['cursor'] ?? null) ? $params['cursor'] : null);
        $conditions = [];
        $values = [];
        if (isset($params['site_id'])) {
            if (!is_numeric($params['site_id'])) {
                throw ApiProblem::validation(['site_id' => ['Must be an integer.']]);
            }
            $conditions[] = 'a.site_id = :site';
            $values['site'] = (int) $params['site_id'];
        }
        if (isset($params['action'])) {
            if (!\is_string($params['action']) || preg_match('/^[a-z_.]{1,64}$/', $params['action']) !== 1) {
                throw ApiProblem::validation(['action' => ['Invalid action.']]);
            }
            $conditions[] = 'a.action = :action';
            $values['action'] = $params['action'];
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.*, u.email AS actor_email FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id AND a.actor_type = 'user'"
            . $where . ' ORDER BY a.occurred_at DESC, a.id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
            $values,
        );
        $hasMore = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);

        $data = array_map(static function (array $row): array {
            $metadata = json_decode(Types::string($row['metadata'], '{}'), true);

            return [
                'id' => Types::int($row['id']),
                'occurred_at' => new \DateTimeImmutable(Types::string($row['occurred_at']), new \DateTimeZone('UTC'))->format(\DATE_ATOM),
                'actor_type' => Types::string($row['actor_type']),
                'actor_id' => Types::nullableInt($row['actor_id']),
                'actor_email' => Types::nullableString($row['actor_email']),
                'action' => Types::string($row['action']),
                'site_id' => Types::nullableInt($row['site_id']),
                'target_type' => Types::nullableString($row['target_type']),
                'target_id' => Types::nullableString($row['target_id']),
                'metadata' => \is_array($metadata) && $metadata !== [] ? $metadata : new \stdClass(),
                'ip_prefix' => Types::nullableString($row['ip_prefix']),
            ];
        }, $rows);

        return $this->responder->json([
            'data' => $data,
            'meta' => ['next_cursor' => $hasMore ? Cursor::encode($offset + $limit) : null],
        ]);
    }

    public function jobs(ServerRequestInterface $request): ResponseInterface
    {
        RequestContext::user($request);

        return $this->responder->data($this->jobs->snapshot());
    }
}
