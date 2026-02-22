<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Reminder;

/**
 * Interface for Reminder persistence operations.
 */
interface ReminderRepositoryInterface
{
    /**
     * Find all pending (unsent) reminders, joined with their event data.
     *
     * @return array<int, array{reminder: Reminder, event_name: string, event_description: string, event_location: string, event_date: int, event_time: int}>
     */
    public function findPending(): array;

    /**
     * Mark a reminder as sent by setting the last_sent timestamp.
     */
    public function markSent(int $eventId): void;

    /**
     * Save (insert or update) a reminder.
     */
    public function save(Reminder $reminder): void;

    /**
     * Delete a reminder by event ID.
     */
    public function delete(int $eventId): void;
}
