<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

/**
 * Chunks id lists destined for IN (...) clauses so prepared statements stay
 * under MySQL's 65,535-placeholder ceiling (SQLite's default compile-time
 * ceiling is lower still). Unchunked, a query binding one placeholder per id
 * fails outright on large datasets — e.g. exporting >= ~65k events.
 *
 * Callers run their query once per chunk and merge results; every batch
 * method in this namespace keys its result by id, and any given id lands in
 * exactly one chunk, so merging is collision-free.
 */
trait ChunkedInClauseTrait
{
    /**
     * Split an id list into IN()-safe chunks.
     *
     * Ids are deduplicated first: a duplicate inside one IN() is a no-op,
     * but the same id straddling two chunks would run its rows through the
     * caller's merge twice (e.g. doubling an event's participant list).
     * Dedup is what makes the "each id lands in exactly one chunk"
     * guarantee unconditional.
     *
     * 32,000 ids per chunk: half MySQL's ceiling, leaving generous headroom
     * for a query's other bound parameters. (No trait constants before
     * PHP 8.2, hence the literal.)
     *
     * @template T
     * @param array<T> $ids
     * @return list<non-empty-list<T>>
     */
    private function chunkForInClause(array $ids): array
    {
        return array_chunk(array_values(array_unique($ids)), 32000);
    }
}
