<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
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
        ?User $user = null,
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
    public function createEvent(Event $event, User $actor): void
    {
        $this->logger->info('Creating new event', [
            'uid' => $event->uid(),
            'name' => $event->name(),
            'actor' => $actor->login()
        ]);
        $this->eventRepository->save($event);
    }

    /**
     * Updates an existing event.
     *
     * @throws EventNotFoundException if the event does not exist.
     * @throws AuthorizationException if the actor is not authorized.
     */
    public function updateEvent(Event $event, User $actor): void
    {
        $existing = $this->eventRepository->findById($event->id());
        if ($existing === null) {
            throw EventNotFoundException::forId($event->id());
        }

        $this->assertCanModify($existing, $actor, 'update event');

        $this->logger->info('Updating event', [
            'id' => $event->id()->value(),
            'name' => $event->name(),
            'actor' => $actor->login()
        ]);
        $this->eventRepository->save($event);
    }

    /**
     * Deletes an event.
     *
     * @throws EventNotFoundException if the event does not exist.
     * @throws AuthorizationException if the actor is not authorized.
     */
    public function deleteEvent(EventId $id, User $actor): void
    {
        $existing = $this->eventRepository->findById($id);
        if ($existing === null) {
            throw EventNotFoundException::forId($id);
        }

        $this->assertCanModify($existing, $actor, 'delete event');

        $this->logger->info('Deleting event', [
            'id' => $id->value(),
            'actor' => $actor->login()
        ]);
        $this->eventRepository->delete($id);
    }

    /**
     * Approves an event for a participant.
     *
     * @throws AuthorizationException if the actor is not the participant or admin.
     */
    public function approveEvent(EventId $id, string $participantLogin, User $actor): void
    {
        $this->assertCanApproveForParticipant($participantLogin, $actor);

        $this->logger->info('Approving event', [
            'id' => $id->value(),
            'participant' => $participantLogin,
            'actor' => $actor->login()
        ]);
        $this->eventRepository->updateParticipantStatus($id, $participantLogin, 'A');
    }

    /**
     * Rejects an event for a participant.
     *
     * @throws AuthorizationException if the actor is not the participant or admin.
     */
    public function rejectEvent(EventId $id, string $participantLogin, User $actor): void
    {
        $this->assertCanApproveForParticipant($participantLogin, $actor);

        $this->logger->info('Rejecting event', [
            'id' => $id->value(),
            'participant' => $participantLogin,
            'actor' => $actor->login()
        ]);
        $this->eventRepository->updateParticipantStatus($id, $participantLogin, 'R');
    }

    /**
     * Asserts that the actor can modify the event.
     *
     * Authorization rules:
     * - Admins can modify any event
     * - Users can modify events they created
     */
    private function assertCanModify(Event $event, User $actor, string $action): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($event->createdBy() === $actor->login()) {
            return;
        }

        $this->logger->warning('Authorization denied', [
            'action' => $action,
            'event_id' => $event->id()->value(),
            'actor' => $actor->login(),
            'owner' => $event->createdBy()
        ]);

        throw AuthorizationException::notOwner($action, $event->id()->value(), $actor->login());
    }

    /**
     * Asserts that the actor can approve/reject for the participant.
     *
     * Authorization rules:
     * - Admins can approve/reject for anyone
     * - Users can only approve/reject for themselves
     */
    private function assertCanApproveForParticipant(string $participantLogin, User $actor): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($participantLogin === $actor->login()) {
            return;
        }

        $this->logger->warning('Authorization denied for participant action', [
            'actor' => $actor->login(),
            'participant' => $participantLogin
        ]);

        throw AuthorizationException::notOwner('modify participant status', 0, $actor->login());
    }
}
