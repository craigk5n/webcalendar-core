<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * Interface for Event persistence operations.
 */
interface EventRepositoryInterface
{
    /**
     * Finds an event by its unique identifier.
     */
    public function findById(EventId $id): ?Event;

    /**
     * Finds an event by its globally unique identifier (RFC 5545 UID).
     */
    public function findByUid(string $uid): ?Event;

    /**
     * Finds all events within a specific date range.
     * Optionally filtered by user, access level, and/or specific user logins.
     *
     * @param DateRange $range The date range to search within.
     * @param \WebCalendar\Core\Domain\Entity\User|null $user Optional user — when set, returns public events + user's own events.
     * @param string|null $accessLevel Optional access level filter (e.g. 'P' for public only).
     * @param string[]|null $users Optional list of user logins to restrict results to.
     * @return Event[]
     */
    public function findByDateRange(
        DateRange $range,
        ?\WebCalendar\Core\Domain\Entity\User $user = null,
        ?string $accessLevel = null,
        ?array $users = null,
    ): array;

    /**
     * Searches for events by keyword and optional filters.
     * 
     * @return \WebCalendar\Core\Domain\ValueObject\EventCollection
     */
    public function search(string $keyword, ?DateRange $range = null, ?\WebCalendar\Core\Domain\Entity\User $user = null): \WebCalendar\Core\Domain\ValueObject\EventCollection;

    /**
     * Persists an event.
     */
    public function save(Event $event): void;

    /**
     * Creates a new event. Alias for save() used by ImportService.
     */
    public function create(Event $event): void;

    /**
     * Deletes an event by its identifier.
     */
    public function delete(EventId $id): void;

    /**
     * Updates the status of a participant for an event.
     */
    public function updateParticipantStatus(EventId $eventId, string $userLogin, string $status): void;

    /**
     * Get participant logins for an event.
     *
     * @return string[]
     */
    public function getParticipants(EventId $id): array;

    /**
     * Get participant logins for multiple events in a single query.
     *
     * @param EventId[] $eventIds
     * @return array<int, string[]> Map of event_id => participant logins.
     */
    public function getParticipantsBatch(array $eventIds): array;

    /**
     * Replace all participants for an event.
     *
     * @param string[] $logins
     */
    public function saveParticipants(EventId $id, array $logins): void;

    /**
     * Get UIDs of events created by a specific login.
     *
     * @return string[]
     */
    public function findUidsByCreator(string $login): array;

    /**
     * Delete all events created by a specific login.
     */
    public function deleteByCreator(string $login): void;
}
