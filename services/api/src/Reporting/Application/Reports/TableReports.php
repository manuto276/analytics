<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application\Reports;

use Analytics\Reporting\Application\EventRows;
use Analytics\Reporting\Application\Rollup\RawSelects;
use Analytics\Reporting\Application\SqlFilters;
use Analytics\Reporting\Domain\DateRange;
use Analytics\Reporting\Domain\ReportQuery;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Types;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Table reports (pages, sources, campaigns, tech, countries, events, content, conversions).
 * Each has a rollup query and a raw query built from the same aggregate fragments, so switching
 * source never changes the numbers.
 */
final readonly class TableReports
{
    /**
     * Repeats on the joined `visits` the day restriction the events already carry, so that MySQL
     * prunes its partitions. Only ever a day range — see RawSelects::visitsJoin().
     */
    private const string VISITS_DAY_RANGE = ' AND v.local_day BETWEEN :from AND :to';

    public function __construct(private Connection $connection, private SqlFilters $filters) {}

    /**
     * @return array{rows: list<array<string, mixed>>, total_rows: int}
     */
    public function run(string $report, ReportQuery $query, DateRange $range, bool $useRollup): array
    {
        return match ($report) {
            'pages' => $this->pages($query, $range, $useRollup),
            'landing-pages' => $this->landingPages($query, $range, $useRollup),
            'sources' => $this->sources($query, $range, $useRollup),
            'campaigns' => $this->campaigns($query, $range, $useRollup),
            'tech' => $this->tech($query, $range, $useRollup),
            'countries' => $this->countries($query, $range, $useRollup),
            'events' => $this->events($query, $range, $useRollup),
            'event-props' => $this->eventProps($query, $range, $useRollup),
            'content' => $this->content($query, $range, $useRollup),
            'conversions' => $this->conversions($query, $range, $useRollup),
            default => throw ApiProblem::notFound('Unknown report ' . $report),
        };
    }

    /** Metrics that each report can be sorted by (first one is the default). */
    public const array SORTABLE = [
        'pages' => ['pageviews', 'visits', 'visitors', 'entries', 'exits', 'bounce_rate', 'avg_engagement_ms'],
        'landing-pages' => ['entries', 'bounce_rate'],
        'sources' => ['visits', 'visitors', 'pageviews', 'bounce_rate', 'avg_duration_ms'],
        'campaigns' => ['visits', 'visitors', 'pageviews', 'bounce_rate'],
        'tech' => ['visits', 'visitors', 'pageviews'],
        'countries' => ['visits', 'visitors', 'pageviews'],
        'events' => ['occurrences', 'visits'],
        'event-props' => ['occurrences', 'visits'],
        'content' => ['pageviews', 'visits', 'visitors', 'contacts'],
        'conversions' => ['count', 'value_minor', 'attributed'],
    ];

    /** @return array{rows: list<array<string, mixed>>, total_rows: int} */
    private function pages(ReportQuery $q, DateRange $range, bool $useRollup): array
    {
        $kind = $q->option('kind', 'top');
        $sums = ['pageviews', 'visits', 'visitors', 'entries', 'exits', 'entry_bounces', 'engagement_ms'];
        if ($useRollup) {
            [$inner, $params] = $this->rollupInner('rollup_pages_daily', ['page_hash', 'host', 'path', ...$sums], $q, $range, ['page' => 'path', 'entry_page' => 'path', 'exit_page' => 'path', 'host' => 'host']);
        } else {
            // The event arms join visits so that the visit dimensions can be read on an event row.
            [$inner, $params] = $this->rawInner(static fn(string $e, string $v): string => RawSelects::pages($e, $v, true, self::VISITS_DAY_RANGE), $q, $range, EventRows::JoinedToVisits);
        }
        $having = match ($kind) {
            'entry' => 'SUM(entries) > 0',
            'exit' => 'SUM(exits) > 0',
            default => 'SUM(pageviews) > 0',
        };
        $default = match ($kind) {
            'entry' => 'entries',
            'exit' => 'exits',
            default => 'pageviews',
        };
        $result = $this->group($inner, $params, ['page_hash' => 'page_hash'], $sums, ['host' => 'MAX(host)', 'path' => 'MAX(path)'], $having, $this->order($q, 'pages', $default), $q);

        return $this->map($result, static fn(array $r): array => [
            'host' => Types::nullableString($r['host']),
            'path' => Types::nullableString($r['path']),
            'pageviews' => Types::int($r['pageviews']),
            'visits' => Types::int($r['visits']),
            'visitors' => Types::int($r['visitors']),
            'entries' => Types::int($r['entries']),
            'exits' => Types::int($r['exits']),
            'bounce_rate' => Types::int($r['entries']) > 0 ? round(Types::int($r['entry_bounces']) / Types::int($r['entries']), 4) : null,
            'avg_engagement_ms' => Types::int($r['pageviews']) > 0 ? (int) round(Types::int($r['engagement_ms']) / Types::int($r['pageviews'])) : null,
        ]);
    }

    /** @return array{rows: list<array<string, mixed>>, total_rows: int} */
    private function landingPages(ReportQuery $q, DateRange $range, bool $useRollup): array
    {
        $sums = ['entries', 'bounces'];
        if ($useRollup) {
            [$inner, $params] = $this->rollupInner('rollup_landing_daily', ['page_hash', 'channel', 'host', 'path', ...$sums], $q, $range, ['entry_page' => 'path', 'page' => 'path', 'host' => 'host', 'channel' => 'channel']);
        } else {
            // The only event arm holds the entry pageviews of visits that were never recorded.
            [$inner, $params] = $this->rawInner(RawSelects::landing(...), $q, $range, EventRows::OrphanEntries);
        }
        $result = $this->group($inner, $params, ['page_hash' => 'page_hash'], $sums, ['host' => 'MAX(host)', 'path' => 'MAX(path)'], 'SUM(entries) > 0', $this->order($q, 'landing-pages', 'entries'), $q);

        return $this->map($result, static fn(array $r): array => [
            'host' => Types::nullableString($r['host']),
            'path' => Types::nullableString($r['path']),
            'entries' => Types::int($r['entries']),
            'bounces' => Types::int($r['bounces']),
            'bounce_rate' => Types::int($r['entries']) > 0 ? round(Types::int($r['bounces']) / Types::int($r['entries']), 4) : null,
        ]);
    }

    /** @return array{rows: list<array<string, mixed>>, total_rows: int} */
    private function sources(ReportQuery $q, DateRange $range, bool $useRollup): array
    {
        $group = $q->option('group', 'channel');
        $sums = ['visits', 'visitors', 'bounces', 'pageviews', 'engagement_ms'];
        if ($useRollup) {
            [$inner, $params] = $this->rollupInner('rollup_sources_daily', ['channel', 'source_hash', 'source', 'referrer_host', ...$sums], $q, $range, ['channel' => 'channel', 'source' => 'source', 'referrer' => 'referrer_host']);
        } else {
            [$inner, $params] = $this->rawInner(RawSelects::sources(...), $q, $range, EventRows::OrphanEntries);
        }
        [$keys, $extras, $having] = match ($group) {
            'source' => [['source_key' => "IFNULL(source, '')"], ['source' => 'MAX(source)', 'channel' => 'MAX(channel)'], "IFNULL(MAX(source), '') <> ''"],
            'referrer' => [['referrer_key' => "IFNULL(referrer_host, '')"], ['referrer_host' => 'MAX(referrer_host)', 'channel' => 'MAX(channel)'], "IFNULL(MAX(referrer_host), '') <> ''"],
            default => [['channel' => 'channel'], [], null],
        };
        $result = $this->group($inner, $params, $keys, $sums, $extras, $having, $this->order($q, 'sources', 'visits'), $q);

        return $this->map($result, static fn(array $r): array => [
            'channel' => Types::nullableString($r['channel'] ?? null),
            'source' => Types::nullableString($r['source'] ?? null),
            'referrer_host' => Types::nullableString($r['referrer_host'] ?? null),
            'visits' => Types::int($r['visits']),
            'visitors' => Types::int($r['visitors']),
            'pageviews' => Types::int($r['pageviews']),
            'bounce_rate' => Types::int($r['visits']) > 0 ? round(Types::int($r['bounces']) / Types::int($r['visits']), 4) : null,
            'avg_duration_ms' => Types::int($r['visits']) > 0 ? (int) round(Types::int($r['engagement_ms']) / Types::int($r['visits'])) : null,
        ]);
    }

    /** @return array{rows: list<array<string, mixed>>, total_rows: int} */
    private function campaigns(ReportQuery $q, DateRange $range, bool $useRollup): array
    {
        $sums = ['visits', 'visitors', 'bounces', 'pageviews'];
        $dimensions = ['utm_source' => 'utm_source', 'utm_medium' => 'utm_medium', 'utm_campaign' => 'utm_campaign', 'utm_content' => 'utm_content', 'utm_term' => 'utm_term'];
        if ($useRollup) {
            [$inner, $params] = $this->rollupInner('rollup_campaigns_daily', ['utm_hash', ...array_keys($dimensions), ...$sums], $q, $range, $dimensions);
        } else {
            // Visits only: there is no events arm to translate the filters onto.
            [$inner, $params] = $this->rawInner(static fn(string $e, string $v): string => RawSelects::campaigns($v), $q, $range, null);
        }
        $result = $this->group($inner, $params, ['utm_hash' => 'utm_hash'], $sums, array_map(static fn(string $c): string => 'MAX(' . $c . ')', $dimensions), null, $this->order($q, 'campaigns', 'visits'), $q);

        return $this->map($result, static fn(array $r): array => [
            'utm_source' => Types::nullableString($r['utm_source']),
            'utm_medium' => Types::nullableString($r['utm_medium']),
            'utm_campaign' => Types::nullableString($r['utm_campaign']),
            'utm_content' => Types::nullableString($r['utm_content']),
            'utm_term' => Types::nullableString($r['utm_term']),
            'visits' => Types::int($r['visits']),
            'visitors' => Types::int($r['visitors']),
            'pageviews' => Types::int($r['pageviews']),
            'bounce_rate' => Types::int($r['visits']) > 0 ? round(Types::int($r['bounces']) / Types::int($r['visits']), 4) : null,
        ]);
    }

    /** @return array{rows: list<array<string, mixed>>, total_rows: int} */
    private function tech(ReportQuery $q, DateRange $range, bool $useRollup): array
    {
        $group = $q->option('group', 'device');
        if (!\in_array($group, ['device', 'browser', 'os'], true)) {
            throw ApiProblem::validation(['group' => ['Must be device, browser or os.']]);
        }
        $sums = ['visits', 'visitors', 'pageviews'];
        if ($useRollup) {
            [$inner, $params] = $this->rollupInner('rollup_tech_daily', ['value', ...$sums], $q, $range, [$group => 'value'], ' AND dimension = :dimension');
            $params['dimension'] = $group;
        } else {
            [$inner, $params] = $this->rawInner(static fn(string $e, string $v): string => RawSelects::tech($group, $e, $v, true, self::VISITS_DAY_RANGE), $q, $range, EventRows::JoinedToVisits);
        }
        $result = $this->group($inner, $params, ['value' => 'value'], $sums, [], null, $this->order($q, 'tech', 'visits'), $q);

        return $this->map($result, static fn(array $r): array => [
            'value' => Types::string($r['value']) === '' ? null : Types::string($r['value']),
            'visits' => Types::int($r['visits']),
            'visitors' => Types::int($r['visitors']),
            'pageviews' => Types::int($r['pageviews']),
        ]);
    }

    /** @return array{rows: list<array<string, mixed>>, total_rows: int} */
    private function countries(ReportQuery $q, DateRange $range, bool $useRollup): array
    {
        $sums = ['visits', 'visitors', 'pageviews'];
        if ($useRollup) {
            [$inner, $params] = $this->rollupInner('rollup_geo_daily', ['country', ...$sums], $q, $range, ['country' => 'country']);
        } else {
            [$inner, $params] = $this->rawInner(static fn(string $e, string $v): string => RawSelects::geo($e, $v, true, self::VISITS_DAY_RANGE), $q, $range, EventRows::JoinedToVisits);
        }
        $result = $this->group($inner, $params, ['country' => 'country'], $sums, [], null, $this->order($q, 'countries', 'visits'), $q);

        return $this->map($result, static fn(array $r): array => [
            'country' => Types::string($r['country']) === 'ZZ' ? null : Types::string($r['country']),
            'visits' => Types::int($r['visits']),
            'visitors' => Types::int($r['visitors']),
            'pageviews' => Types::int($r['pageviews']),
        ]);
    }

    /** @return array{rows: list<array<string, mixed>>, total_rows: int} */
    private function events(ReportQuery $q, DateRange $range, bool $useRollup): array
    {
        $sums = ['occurrences', 'visits'];
        if ($useRollup) {
            [$inner, $params] = $this->rollupInner('rollup_events_daily', ['name', ...$sums], $q, $range, ['event' => 'name'], " AND prop_key = ''");
        } else {
            [$inner, $params] = $this->rawInner(static fn(string $e, string $v): string => RawSelects::events($e, true, self::VISITS_DAY_RANGE), $q, $range, EventRows::JoinedToVisits, true, true);
        }
        $result = $this->group($inner, $params, ['name' => 'name'], $sums, [], null, $this->order($q, 'events', 'occurrences'), $q);

        return $this->map($result, static fn(array $r): array => [
            'name' => Types::string($r['name']),
            'occurrences' => Types::int($r['occurrences']),
            'visits' => Types::int($r['visits']),
        ]);
    }

    /** @return array{rows: list<array<string, mixed>>, total_rows: int} */
    private function eventProps(ReportQuery $q, DateRange $range, bool $useRollup): array
    {
        $name = $q->option('event');
        $sums = ['occurrences', 'visits'];
        if ($useRollup) {
            [$inner, $params] = $this->rollupInner('rollup_events_daily', ['name', 'prop_key', 'prop_value_hash', 'prop_value', ...$sums], $q, $range, ['event' => 'name'], " AND prop_key <> '' AND name = :event");
        } else {
            [$inner, $params] = $this->rawInner(static fn(string $e, string $v): string => RawSelects::eventProps($e . ' AND e.name = :event', true, self::VISITS_DAY_RANGE), $q, $range, EventRows::JoinedToVisits, true, true);
        }
        $params['event'] = $name;
        $result = $this->group($inner, $params, ['prop_key' => 'prop_key', 'prop_value_hash' => 'prop_value_hash'], $sums, ['prop_value' => 'MAX(prop_value)'], null, $this->order($q, 'event-props', 'occurrences'), $q);

        return $this->map($result, static fn(array $r): array => [
            'prop_key' => Types::string($r['prop_key']),
            'prop_value' => Types::string($r['prop_value']),
            'occurrences' => Types::int($r['occurrences']),
            'visits' => Types::int($r['visits']),
        ]);
    }

    /** @return array{rows: list<array<string, mixed>>, total_rows: int} */
    private function content(ReportQuery $q, DateRange $range, bool $useRollup): array
    {
        $sums = ['pageviews', 'visits', 'visitors', 'contacts'];
        $prefix = $q->option('prefix');
        $extraWhere = $prefix === '' ? '' : ' AND content_key LIKE :prefix';
        if ($useRollup) {
            [$inner, $params] = $this->rollupInner('rollup_content_daily', ['content_key', 'channel', ...$sums], $q, $range, ['content' => 'content_key', 'channel' => 'channel'], $extraWhere);
        } else {
            // RawSelects::content() always joins visits, so attribution filters must resolve through
            // COALESCE(v.…, e.…) here too — the same expression the rollup groups by.
            [$inner, $params] = $this->rawInner(static fn(string $e, string $v): string => RawSelects::content($e . ($prefix === '' ? '' : ' AND e.content_key LIKE :prefix'), self::VISITS_DAY_RANGE), $q, $range, EventRows::JoinedToVisits);
            $params['contacts'] = $q->site->contentContactEvents === [] ? ['__none__'] : $q->site->contentContactEvents;
        }
        if ($prefix !== '') {
            $params['prefix'] = SqlFilters::escapeLike($prefix) . '%';
        }
        $types = $useRollup ? [] : ['contacts' => ArrayParameterType::STRING];
        $result = $this->group($inner, $params, ['content_key' => 'content_key'], $sums, [], null, $this->order($q, 'content', 'pageviews'), $q, $types);

        $rows = $this->map($result, static fn(array $r): array => [
            'content_key' => Types::string($r['content_key']),
            'pageviews' => Types::int($r['pageviews']),
            'visits' => Types::int($r['visits']),
            'visitors' => Types::int($r['visitors']),
            'contacts' => Types::int($r['contacts']),
            'channels' => new \stdClass(),
        ]);

        // Channel split per content key (same source as the rows).
        $keys = array_map(static fn(array $r): string => Types::string($r['content_key']), $rows['rows']);
        if ($keys !== []) {
            $channelRows = $this->connection->fetchAllAssociative(
                'SELECT content_key, channel, SUM(visits) AS visits FROM (' . $inner . ') inner_rows WHERE content_key IN (:keys) GROUP BY content_key, channel',
                $params + ['keys' => $keys],
                $types + ['keys' => ArrayParameterType::STRING],
            );
            $byKey = [];
            foreach ($channelRows as $row) {
                $byKey[Types::string($row['content_key'])][Types::string($row['channel'])] = Types::int($row['visits']);
            }
            foreach ($rows['rows'] as $index => $row) {
                $key = Types::string($row['content_key']);
                $rows['rows'][$index]['channels'] = (object) ($byKey[$key] ?? []);
            }
        }

        return $rows;
    }

    /** @return array{rows: list<array<string, mixed>>, total_rows: int} */
    private function conversions(ReportQuery $q, DateRange $range, bool $useRollup): array
    {
        $sums = ['count', 'value_minor', 'attributed'];
        if ($useRollup) {
            [$inner, $params] = $this->rollupInner('rollup_conversions_daily', ['name', 'attr_channel', ...$sums], $q, $range, []);
        } else {
            [$inner, $params] = $this->rawInner(static fn(string $e, string $v): string => RawSelects::conversions(' AND c.local_day BETWEEN :from AND :to'), $q, $range, null, false);
            $params['currency'] = $q->site->currency;
        }
        $result = $this->group($inner, $params, ['name' => 'name'], $sums, [], null, $this->order($q, 'conversions', 'count'), $q);

        return $this->map($result, static fn(array $r): array => [
            'name' => Types::string($r['name']),
            'count' => Types::int($r['count']),
            'value_minor' => Types::int($r['value_minor']),
            'attributed' => Types::int($r['attributed']),
        ]);
    }

    /**
     * @param list<string>          $columns
     * @param array<string, string> $filterColumns dimension => rollup column
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function rollupInner(string $table, array $columns, ReportQuery $q, DateRange $range, array $filterColumns, string $extraWhere = ''): array
    {
        $filters = $this->filters->forRollup($q->filters, $filterColumns);
        $sql = 'SELECT ' . implode(', ', $columns) . ' FROM ' . $table . ' WHERE site_id = :site AND day BETWEEN :from AND :to' . $extraWhere . $filters['sql'];

        return [$sql, ['site' => $q->site->id, 'from' => $range->fromDay(), 'to' => $range->toDay()] + $filters['params']];
    }

    /**
     * @param callable(string, string): string $select
     * @param ?EventRows                       $eventRows what the rows of the events arms are, or null when the select has none
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function rawInner(callable $select, ReportQuery $q, DateRange $range, ?EventRows $eventRows, bool $useRange = true, bool $eventFilterOnRow = false): array
    {
        $visitFilters = $this->filters->forVisits($q->filters);
        $eventFilters = $eventRows === null ? ['sql' => '', 'params' => []] : $this->filters->forEvents($q->filters, $eventRows, $eventFilterOnRow);
        $eventsWhere = ($useRange ? ' AND e.local_day BETWEEN :from AND :to' : '') . $eventFilters['sql'];
        $visitsWhere = ($useRange ? ' AND v.local_day BETWEEN :from AND :to' : '') . $visitFilters['sql'];
        $sql = $select($eventsWhere, $visitsWhere);

        return [$sql, ['site' => $q->site->id, 'from' => $range->fromDay(), 'to' => $range->toDay()] + $eventFilters['params'] + $visitFilters['params']];
    }

    /**
     * @param array<string, string>   $keys    output name => expression
     * @param list<string>            $sums
     * @param array<string, string>   $extras  output name => aggregate expression
     * @param array<string, mixed>                                                                    $params
     * @param array<string, ArrayParameterType|ParameterType|\Doctrine\DBAL\Types\Type|string>       $types
     *
     * @return array{rows: list<array<string, mixed>>, total_rows: int}
     */
    private function group(string $inner, array $params, array $keys, array $sums, array $extras, ?string $having, string $order, ReportQuery $q, array $types = []): array
    {
        $select = [];
        foreach ($keys as $alias => $expression) {
            $select[] = $expression . ' AS ' . $alias;
        }
        foreach ($extras as $alias => $expression) {
            $select[] = $expression . ' AS ' . $alias;
        }
        foreach ($sums as $sum) {
            $select[] = 'SUM(' . $sum . ') AS ' . $sum;
        }
        $groupBy = implode(', ', array_values($keys));
        $havingSql = $having === null ? '' : ' HAVING ' . $having;
        $base = 'SELECT ' . implode(', ', $select) . ' FROM (' . $inner . ') rows_by_day GROUP BY ' . $groupBy . $havingSql;

        $total = Types::int($this->connection->fetchOne('SELECT COUNT(*) FROM (' . $base . ') grouped', $params, $types));
        $rows = $this->connection->fetchAllAssociative($base . ' ORDER BY ' . $order . ' LIMIT ' . $q->limit . ' OFFSET ' . $q->offset, $params, $types);

        return ['rows' => array_values($rows), 'total_rows' => $total];
    }

    /**
     * @param array{rows: list<array<string, mixed>>, total_rows: int} $result
     * @param callable(array<string, mixed>): array<string, mixed>     $mapper
     *
     * @return array{rows: list<array<string, mixed>>, total_rows: int}
     */
    private function map(array $result, callable $mapper): array
    {
        return ['rows' => array_map($mapper, $result['rows']), 'total_rows' => $result['total_rows']];
    }

    private function order(ReportQuery $q, string $report, string $default): string
    {
        $sortable = self::SORTABLE[$report] ?? [];
        $sort = $q->sort;
        if ($sort !== null && !\in_array($sort, $sortable, true)) {
            throw ApiProblem::validation(['sort' => ['Must be one of: ' . implode(', ', $sortable) . '.']]);
        }
        $column = match ($sort) {
            null => $default,
            'bounce_rate' => 'SUM(bounces) / NULLIF(SUM(visits), 0)',
            'avg_duration_ms' => 'SUM(engagement_ms) / NULLIF(SUM(visits), 0)',
            'avg_engagement_ms' => 'SUM(engagement_ms) / NULLIF(SUM(pageviews), 0)',
            default => $sort,
        };
        if ($sort === 'bounce_rate' && $report === 'pages') {
            $column = 'SUM(entry_bounces) / NULLIF(SUM(entries), 0)';
        }
        if ($sort === 'bounce_rate' && $report === 'landing-pages') {
            $column = 'SUM(bounces) / NULLIF(SUM(entries), 0)';
        }
        $direction = $q->sortDescending ? 'DESC' : 'ASC';

        return $column . ' ' . $direction . ', 1 ASC';
    }
}
