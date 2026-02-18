<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Exception\EventNotFoundException;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for orchestrating Event-related business logic.
 */
final readonly class EventService
{
    private LoggerInterface $logger;

    public function __construct(
        private EventRepositoryInterface $eventRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Finds events within a date range.
     *
     * @param string[]|null $users Optional list of user logins to restrict results to.
     */
    public function getEventsInDateRange(
        DateRange $range,
        ?\WebCalendar\Core\Domain\Entity\User $user = null,
        ?string $accessLevel = null,
        ?array $users = null,
    ): EventCollection {
        $this->logger->debug('Fetching events in date range', [
            'start' => $range->startDate()->format('c'),
            'end' => $range->endDate()->format('c'),
            'user' => $user?->login(),
            'access' => $accessLevel
        ]);
        $events = $this->eventRepository->findByDateRange($range, $user, $accessLevel, $users);
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
        $this->logger->info('Creating new event', ['uid' => $event->uid(), 'name' => $event->name()]);
        $this->eventRepository->save($event);
    }

    /**
     * Updates an existing event.
     *
     * @throws EventNotFoundException if the event does not exist.
     */
    public function updateEvent(Event $event): void
    {
        $existing = $this->eventRepository->findById($event->id());
        if ($existing === null) {
            throw EventNotFoundException::forId($event->id());
        }

        $this->logger->info('Updating event', ['id' => $event->id()->value(), 'name' => $event->name()]);
        $this->eventRepository->save($event);
    }

    /**
     * Deletes an event.
     *
     * @throws EventNotFoundException if the event does not exist.
     */
    public function deleteEvent(EventId $id): void
    {
        $existing = $this->eventRepository->findById($id);
        if ($existing === null) {
            throw EventNotFoundException::forId($id);
        }

        $this->logger->info('Deleting event', ['id' => $id->value()]);
        $this->eventRepository->delete($id);
    }

    /**
     * Approves an event for a participant.
     */
    public function approveEvent(EventId $id, string $login): void
    {
        $this->logger->info('Approving event', ['id' => $id->value(), 'user' => $login]);
        $this->eventRepository->updateParticipantStatus($id, $login, 'A');
    }

    /**
     * Rejects an event for a participant.
     */
    public function rejectEvent(EventId $id, string $login): void
    {
        $this->logger->info('Rejecting event', ['id' => $id->value(), 'user' => $login]);
        $this->eventRepository->updateParticipantStatus($id, $login, 'R');
    }
}
