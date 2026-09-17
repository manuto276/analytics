<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application;

use Analytics\Reporting\Domain\Filter;
use Analytics\Reporting\Domain\FilterOperator;

/**
 * Translates report filters into SQL conditions over the visits table (alias v) and the
 * events_raw table (alias e). Event dimensions on visit queries become EXISTS sub-queries.
 */
final class SqlFilters
{
    /** @var array<string, string> visit dimension => column of the visits table */
    private const array VISIT_COLUMNS = [
        'entry_page' => 'v.entry_path',
        'host' => 'v.entry_host',
        'channel' => 'v.channel',
        'source' => 'v.source',
        'referrer' => 'v.referrer_host',
        'utm_source' => 'v.utm_source',
        'utm_medium' => 'v.utm_medium',
        'utm_campaign' => 'v.utm_campaign',
        'utm_content' => 'v.utm_content',
        'utm_term' => 'v.utm_term',
        'device' => 'v.device',
        'browser' => 'v.browser',
        'os' => 'v.os',
        'country' => 'v.country',
        'level' => 'v.level',
        'content' => 'v.entry_content_key',
    ];

    /** @var array<string, string> event dimension => column of events_raw */
    private const array EVENT_COLUMNS = [
        'page' => 'e.path',
        'host' => 'e.host',
        'event' => 'e.name',
        'content' => 'e.content_key',
        'channel' => 'e.channel',
        'source' => 'e.source',
        'referrer' => 'e.referrer_host',
        'utm_source' => 'e.utm_source',
        'utm_medium' => 'e.utm_medium',
        'utm_campaign' => 'e.utm_campaign',
        'utm_content' => 'e.utm_content',
        'utm_term' => 'e.utm_term',
        'device' => 'e.device',
        'browser' => 'e.browser',
        'os' => 'e.os',
        'country' => 'e.country',
        'level' => 'e.level',
    ];

    /** An event row that stands for a whole visit nobody recorded: it is its own entry and exit. */
    private const string ORPHAN_ENTRY = 'e.visit_id IS NULL AND e.is_entry = 1';

    /**
     * Entry page of the visit an event row belongs to. An orphan entry answers with its own path
     * (it is the whole visit); any other row without a visit has no entry page, hence NULL.
     */
    private const string ENTRY_PATH = 'COALESCE(v.entry_path, IF(' . self::ORPHAN_ENTRY . ', e.path, NULL))';

    /** @var list<string> */
    private array $conditions = [];
    /** @var array<string, string> */
    private array $params = [];
    private int $index = 0;
    /**
     * Placeholder namespace of the translation being built. Each translation has its own so that a
     * caller can merge the parameters of several translations of the same filter list into one query
     * (a dimension may need a different number of placeholders on the visits side and on the
     * events side, so shared numbering would silently cross-bind).
     */
    private string $prefix = 'f';

    /**
     * Conditions for a query over visits (alias v). Event dimensions become EXISTS sub-queries
     * over events_raw of the same visit.
     *
     * @param list<Filter> $filters
     *
     * @return array{sql: string, params: array<string, string>}
     */
    public function forVisits(array $filters): array
    {
        $this->reset('fv');
        foreach ($filters as $filter) {
            $column = self::VISIT_COLUMNS[$filter->dimension] ?? null;
            if ($filter->dimension === 'exit_page') {
                $this->conditions[] = $this->existsOnVisit("e.type = 'pv' AND e.page_hash = v.exit_page_hash AND " . $this->condition('e.path', $filter));
            } elseif ($filter->dimension === 'page') {
                $this->conditions[] = $this->existsOnVisit("e.type = 'pv' AND " . $this->condition('e.path', $filter));
            } elseif ($filter->dimension === 'event') {
                $this->conditions[] = $this->existsOnVisit("e.type = 'ev' AND " . $this->condition('e.name', $filter));
            } elseif ($filter->dimension === 'content') {
                $this->conditions[] = '(' . $this->condition('v.entry_content_key', $filter) . ' OR ' . $this->existsOnVisit($this->condition('e.content_key', $filter)) . ')';
            } elseif ($column !== null) {
                $this->conditions[] = $this->condition($column, $filter);
            } else {
                throw new \InvalidArgumentException('Unsupported filter dimension ' . $filter->dimension);
            }
        }

        return $this->result();
    }

    /**
     * Conditions for a query over events_raw (alias e).
     *
     * $rows says what the rows are, which is what makes the visit dimensions (`entry_page`,
     * `exit_page`) answerable: either the query joins the visit of each row, or every row already
     * *is* a whole visit (an orphan entry pageview) and answers for itself. There is no third case,
     * so no filter the public API accepts can reach an events-side query it cannot translate.
     *
     * @param list<Filter> $filters
     *
     * @return array{sql: string, params: array<string, string>}
     */
    public function forEvents(array $filters, EventRows $rows = EventRows::JoinedToVisits, bool $eventFilterOnRow = false): array
    {
        $this->reset('fe');
        $orphans = $rows === EventRows::OrphanEntries;
        foreach ($filters as $filter) {
            if ($filter->dimension === 'entry_page') {
                $this->conditions[] = $this->condition($orphans ? 'e.path' : self::ENTRY_PATH, $filter);
                continue;
            }
            if ($filter->dimension === 'exit_page') {
                $this->conditions[] = $orphans
                    ? $this->condition('e.path', $filter)
                    : '(EXISTS (SELECT 1 FROM events_raw ex WHERE ex.site_id = v.site_id AND ex.local_day = v.local_day AND ex.visit_id = v.id AND ex.page_hash = v.exit_page_hash AND '
                        . $this->condition('ex.path', $filter) . ') OR (' . self::ORPHAN_ENTRY . ' AND ' . $this->condition('e.path', $filter) . '))';
                continue;
            }
            if ($filter->dimension === 'event' && !$eventFilterOnRow) {
                // "Visits that fired this event": the filter belongs to the visit, not to the row.
                $this->conditions[] = 'EXISTS (SELECT 1 FROM events_raw ev WHERE ev.site_id = e.site_id AND ev.local_day = e.local_day AND ev.visit_id = e.visit_id AND ev.visit_id IS NOT NULL AND ev.type = \'ev\' AND ' . $this->condition('ev.name', $filter) . ')';
                continue;
            }
            if (\in_array($filter->dimension, ['channel', 'source', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'], true) && !$orphans) {
                // Visit-level attribution wins when the event belongs to a visit.
                $visitColumn = self::VISIT_COLUMNS[$filter->dimension];
                $eventColumn = self::EVENT_COLUMNS[$filter->dimension];
                $this->conditions[] = $this->condition('COALESCE(' . $visitColumn . ', ' . $eventColumn . ')', $filter);
                continue;
            }
            $column = self::EVENT_COLUMNS[$filter->dimension] ?? throw new \InvalidArgumentException('Unsupported filter dimension ' . $filter->dimension);
            $this->conditions[] = $this->condition($column, $filter);
        }

        return $this->result();
    }

    /**
     * Conditions over a rollup table: the caller maps dimensions to columns.
     *
     * @param list<Filter>          $filters
     * @param array<string, string> $columns dimension => column
     *
     * @return array{sql: string, params: array<string, string>}
     */
    public function forRollup(array $filters, array $columns): array
    {
        $this->reset('fr');
        foreach ($filters as $filter) {
            $column = $columns[$filter->dimension] ?? throw new \InvalidArgumentException('Unsupported filter dimension ' . $filter->dimension);
            $this->conditions[] = $this->condition($column, $filter);
        }

        return $this->result();
    }

    private function existsOnVisit(string $condition): string
    {
        return 'EXISTS (SELECT 1 FROM events_raw e WHERE e.site_id = v.site_id AND e.local_day = v.local_day AND e.visit_id = v.id AND ' . $condition . ')';
    }

    private function condition(string $column, Filter $filter): string
    {
        $name = $this->prefix . $this->index++;
        $value = $filter->value;

        if ($value === '' && \in_array($filter->operator, [FilterOperator::Is, FilterOperator::IsNot], true)) {
            return $filter->operator === FilterOperator::Is ? "({$column} IS NULL OR {$column} = '')" : "({$column} IS NOT NULL AND {$column} <> '')";
        }

        return match ($filter->operator) {
            FilterOperator::Is => $this->bind($name, $value) . "{$column} = :{$name}",
            FilterOperator::IsNot => $this->bind($name, $value) . "({$column} IS NULL OR {$column} <> :{$name})",
            FilterOperator::Contains => $this->bind($name, '%' . self::escapeLike($value) . '%') . "{$column} LIKE :{$name}",
            FilterOperator::Prefix => $this->bind($name, self::escapeLike($value) . '%') . "{$column} LIKE :{$name}",
            FilterOperator::Glob => $this->bind($name, self::globToLike($value)) . "{$column} LIKE :{$name}",
        };
    }

    private function bind(string $name, string $value): string
    {
        $this->params[$name] = $value;

        return '';
    }

    private function reset(string $prefix): void
    {
        $this->conditions = [];
        $this->params = [];
        $this->index = 0;
        $this->prefix = $prefix;
    }

    /** @return array{sql: string, params: array<string, string>} */
    private function result(): array
    {
        return ['sql' => $this->conditions === [] ? '' : ' AND ' . implode(' AND ', $this->conditions), 'params' => $this->params];
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    public static function globToLike(string $glob): string
    {
        return str_replace(['*', '?'], ['%', '_'], self::escapeLike($glob));
    }
}
