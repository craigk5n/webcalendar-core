<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventCollection;

/**
 * Service for orchestrating Event-related business logic.
 */
final readonly class EventService
{
    public function __construct(
        private EventRepositoryInterface $eventRepository
    ) {
    }

    /**
     * Finds events within a date range.
     */
    public function getEventsInDateRange(DateRange $range, ?\WebCalendar\Core\Domain\Entity\User $user = null): EventCollection
    {
        $events = $this->eventRepository->findByDateRange($range, $user);
        return new EventCollection($events);
    }

    /**
     * Finds a single event by ID.
     */
    public function getEventById(EventId $id): ?Event
    {
        return $this->eventRepository->findById($id);
    }

    /**
     * Persists a new event.
     */
    public function createEvent(Event $event): void
    {
        // Business logic like conflict detection could be added here
        $this->eventRepository->save($event);
    }

    /**
     * Updates an existing event.
     * 
     * @throws \WebCalendar\Core\Domain\Exception\EventNotFoundException
     */
    public function updateEvent(Event $event): void
    {
        if ($this->eventRepository->findById($event->id()) === null) {
            throw \WebCalendar\Core\Domain\Exception\EventNotFoundException::forId($event->id());
        }
        $this->eventRepository->save($event);
    }

    /**
     * Deletes an event.
     * 
     * @throws \WebCalendar\Core\Domain\Exception\EventNotFoundException
     */
    public function deleteEvent(EventId $id): void
    {
        if ($this->eventRepository->findById($id) === null) {
            throw \WebCalendar\Core\Domain\Exception\EventNotFoundException::forId($id);
        }
        $this->eventRepository->delete($id);
    }

    /**
     * Approves an event for a user.
     */
    public function approveEvent(EventId $id, string $userLogin): void
    {
        $this->eventRepository->updateParticipantStatus($id, $userLogin, 'A');
    }

    /**
     * Rejects an event for a user.
     */
    public function rejectEvent(EventId $id, string $userLogin): void
    {
        $this->eventRepository->updateParticipantStatus($id, $userLogin, 'R');
    }
}
