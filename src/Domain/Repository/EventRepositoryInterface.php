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
     * Finds all events within a specific date range.
     * Optionally filtered by user.
     * 
     * @param DateRange $range The date range to search within.
     * @param \WebCalendar\Core\Domain\Entity\User|null $user Optional user to filter by.
     * @return Event[]
     */
    public function findByDateRange(DateRange $range, ?\WebCalendar\Core\Domain\Entity\User $user = null): array;

    /**
     * Persists an event.
     */
    public function save(Event $event): void;

    /**
     * Deletes an event by its identifier.
     */
    public function delete(EventId $id): void;
}
