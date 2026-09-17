<?php

declare(strict_types=1);

namespace Analytics\Conversions\Application;

use Analytics\Shared\Crypto\Base64Url;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Types;
use Analytics\Shared\Validation\Input;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Tracking\Application\DirtyDayMarker;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * Server-side conversions: validated, attributed and stored idempotently per (site, external id).
 */
final readonly class ConversionIngestHandler
{
    public const int MAX_BATCH = 100;
    public const string NAME_PATTERN = '/^[a-z0-9_:.-]{1,64}$/';

    public function __construct(
        private Connection $connection,
        private AttributionResolver $attribution,
        private CustomerRefHasher $customerRefs,
        private ClockInterface $clock,
    ) {}

    /**
     * @param array<array-key, mixed> $body one conversion or a list of them
     *
     * @return array{accepted: int, duplicates: int, rejected: list<array{index: int, error: string}>}
     */
    public function handle(SiteSnapshot $site, array $body): array
    {
        $items = array_is_list($body) ? $body : [$body];
        if ($items === []) {
            throw ApiProblem::badRequest('empty_batch', 'Send at least one conversion.');
        }
        if (\count($items) > self::MAX_BATCH) {
            throw ApiProblem::badRequest('too_many_conversions', \sprintf('At most %d conversions per request.', self::MAX_BATCH));
        }

        $accepted = 0;
        $duplicates = 0;
        $rejected = [];
        $days = [];
        $now = $this->clock->now();

        foreach ($items as $index => $item) {
            if (!\is_array($item)) {
                $rejected[] = ['index' => Types::int($index), 'error' => 'Each conversion must be an object.'];
                continue;
            }
            try {
                $row = $this->row($site, new Input($item), $now);
            } catch (ApiProblem $problem) {
                $rejected[] = ['index' => Types::int($index), 'error' => self::describe($problem)];
                continue;
            }
            $inserted = $this->connection->executeStatement(
                'INSERT IGNORE INTO conversions (' . implode(', ', array_keys($row['values'])) . ') VALUES (:' . implode(', :', array_keys($row['values'])) . ')',
                $row['values'],
                $row['types'],
            );
            if ($inserted > 0) {
                ++$accepted;
                $days[Types::string($row['values']['local_day'])] = true;
            } else {
                ++$duplicates;
            }
        }

        foreach (array_keys($days) as $day) {
            DirtyDayMarker::mark($this->connection, $site->id, (string) $day, $now);
        }

        return ['accepted' => $accepted, 'duplicates' => $duplicates, 'rejected' => $rejected];
    }

    /**
     * @return array{values: array<string, mixed>, types: array<string, ParameterType>}
     */
    private function row(SiteSnapshot $site, Input $input, \DateTimeImmutable $now): array
    {
        $externalId = $input->string('id', 128);
        $name = $input->string('name', 64, 1, self::NAME_PATTERN);
        $occurredAtRaw = $input->optionalString('occurred_at', 40);
        $visitorIdRaw = $input->optionalString('visitor_id', 22);
        $customerRef = $input->optionalString('customer_ref', 256);
        $props = $input->array('props');
        $value = $input->nested('value');
        $declared = $input->nested('declared_source');

        $occurredAt = $now;
        if ($occurredAtRaw !== null) {
            try {
                $occurredAt = new \DateTimeImmutable($occurredAtRaw)->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                $input->error('occurred_at', 'Must be an ISO 8601 date and time.');
            }
            if ($occurredAt > $now->modify('+1 hour')) {
                $input->error('occurred_at', 'Must not be in the future.');
            }
            if ($occurredAt < $now->modify('-30 days')) {
                $input->error('occurred_at', 'Must not be older than 30 days.');
            }
        }

        $visitorId = null;
        if ($visitorIdRaw !== null) {
            $decoded = preg_match('/^[A-Za-z0-9_-]{22}$/', $visitorIdRaw) === 1 ? Base64Url::decode($visitorIdRaw) : null;
            if ($decoded === null || \strlen($decoded) !== 16) {
                $input->error('visitor_id', 'Must be the an_vid cookie value.');
            } else {
                $visitorId = $decoded;
            }
        }

        $amount = null;
        $currency = null;
        if ($value !== null) {
            $amount = $value->int('amount_minor', null, -1_000_000_000_000, 1_000_000_000_000);
            $currency = $value->string('currency', 3, 3, '/^[A-Z]{3}$/');
            $input->merge($value);
        }

        $propsJson = null;
        if ($props !== null) {
            if (\count($props) > 10) {
                $input->error('props', 'At most 10 properties.');
            }
            $clean = [];
            foreach ($props as $key => $item) {
                $key = (string) $key;
                if (\strlen($key) > 32 || !\is_scalar($item) || (\is_string($item) && mb_strlen($item) > 100)) {
                    $input->error('props.' . $key, 'Invalid property.');
                    continue;
                }
                $clean[$key] = $item;
            }
            $propsJson = $clean === [] ? null : json_encode($clean, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        }

        $declaredJson = null;
        if ($declared !== null) {
            $declaredValues = [];
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'channel'] as $key) {
                $declaredValue = $declared->optionalString($key, 100);
                if ($declaredValue !== null) {
                    $declaredValues[$key] = $declaredValue;
                }
            }
            $input->merge($declared);
            $declaredJson = $declaredValues === [] ? null : json_encode($declaredValues, \JSON_THROW_ON_ERROR);
        }

        $input->assertValid();

        $customerRefHash = $customerRef === null || $customerRef === '' ? null : $this->customerRefs->hash($site->id, $customerRef);
        $attribution = $this->attribution->resolve($site->id, $visitorId, $customerRefHash, $occurredAt);

        $values = [
            'site_id' => $site->id,
            'external_id' => $externalId,
            'name' => $name,
            'origin' => 'server',
            'occurred_at' => $occurredAt->format('Y-m-d H:i:s.v'),
            'local_day' => $occurredAt->setTimezone($site->timezone())->format('Y-m-d'),
            'received_at' => $now->format('Y-m-d H:i:s.v'),
            'visitor_id' => $visitorId,
            'customer_ref' => $customerRefHash,
            'value_minor' => $amount,
            'currency' => $currency,
            'props' => $propsJson,
            'declared_source' => $declaredJson,
        ] + $attribution;

        return [
            'values' => $values,
            'types' => ['visitor_id' => ParameterType::BINARY, 'customer_ref' => ParameterType::BINARY],
        ];
    }

    private static function describe(ApiProblem $problem): string
    {
        if ($problem->errors === []) {
            return $problem->detail ?? $problem->title();
        }
        $parts = [];
        foreach ($problem->errors as $field => $messages) {
            $parts[] = $field . ': ' . implode(' ', $messages);
        }

        return implode('; ', $parts);
    }
}
