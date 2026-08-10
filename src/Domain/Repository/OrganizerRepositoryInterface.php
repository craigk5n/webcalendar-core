<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Organizer;
use WebCalendar\Core\Domain\ValueObject\OrganizerId;

/**
 * Interface for Organizer persistence operations.
 */
interface OrganizerRepositoryInterface
{
    public function findById(OrganizerId $id): ?Organizer;

    /**
     * Case-insensitive exact-name lookup — the match-or-create seam used
     * by imports.
     */
    public function findByName(string $name): ?Organizer;

    /**
     * @return Organizer[] Ordered by name.
     */
    public function findAll(): array;

    public function nextId(): int;

    /**
     * Inserts when the organizer's id is 0 (claiming the next id),
     * updates otherwise. Returns the persisted organizer carrying its
     * real id.
     */
    public function save(Organizer $organizer): Organizer;

    /**
     * Deletes an organizer. Events referencing it keep their rows — the
     * reference is nulled, never cascaded.
     */
    public function delete(OrganizerId $id): void;

    /**
     * Re-points events from one organizer to another (merge support).
     * Self-merge (`$from` equals `$to`) is a guarded no-op. Events
     * referencing other organizers are untouched.
     */
    public function reassignEvents(OrganizerId $from, OrganizerId $to): void;

    /**
     * Number of events referencing an organizer.
     */
    public function countEvents(OrganizerId $id): int;
}
