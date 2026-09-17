<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application;

/**
 * What the rows of an events-side query are. It decides how a dimension that lives on the *visit*
 * (`entry_page`, `exit_page`) can be read on an event row, so every raw report can answer those
 * filters instead of refusing them.
 */
enum EventRows
{
    /**
     * Rows are events joined to their visit (alias `v`, NULL when the event has no visit): the
     * visit answers for them, and an event with no visit falls back to the orphan rule below.
     */
    case JoinedToVisits;

    /**
     * Rows are the entry pageviews of visits that were never recorded
     * (`e.visit_id IS NULL AND e.is_entry = 1`) — how a `pageviews_only` site gets a visits figure.
     * Such a row is a whole one-pageview visit, so it is its own entry page and its own exit page.
     */
    case OrphanEntries;
}
