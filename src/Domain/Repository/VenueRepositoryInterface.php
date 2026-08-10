<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Venue;
use WebCalendar\Core\Domain\ValueObject\VenueId;

/**
 * Interface for Venue persistence operations.
 */
interface VenueRepositoryInterface
{
    public function findById(VenueId $id): ?Venue;

    /**
     * Case-insensitive exact-name lookup — the match-or-create seam used
     * by imports.
     */
    public function findByName(string $name): ?Venue;

    /**
     * @return Venue[] Ordered by name.
     */
    public function findAll(): array;

    public function nextId(): int;

    /**
     * Inserts when the venue's id is 0 (claiming the next id), updates
     * otherwise. Returns the persisted venue carrying its real id.
     */
    public function save(Venue $venue): Venue;

    /**
     * Deletes a venue. Events referencing it keep their rows and their
     * legacy location string — the reference is nulled, never cascaded.
     */
    public function delete(VenueId $id): void;

    /**
     * Re-points events from one venue to another (merge support).
     * Self-merge (`$from` equals `$to`) is a guarded no-op. Events
     * referencing other venues are untouched.
     */
    public function reassignEvents(VenueId $from, VenueId $to): void;

    /**
     * Number of events referencing a venue.
     */
    public function countEvents(VenueId $id): int;
}
