<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Exception\EventNotFoundException;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Domain\ValueObject\ParticipantStatus;
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
        private UserRepositoryInterface $userRepository,
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
     * @throws EventNotFoundException if the event does not exist.
     * @throws \InvalidArgumentException if the participant is not on the event.
     * @throws AuthorizationException if the actor is not the participant or admin.
     */
    public function approveEvent(EventId $id, string $participantLogin, User $actor): void
    {
        $this->assertCanApproveForParticipant($participantLogin, $actor);
        $this->setParticipantStatus($id, $participantLogin, ParticipantStatus::ACCEPTED, $actor);
    }

    /**
     * Rejects an event for a participant.
     *
     * @throws EventNotFoundException if the event does not exist.
     * @throws \InvalidArgumentException if the participant is not on the event.
     * @throws AuthorizationException if the actor is not the participant or admin.
     */
    public function rejectEvent(EventId $id, string $participantLogin, User $actor): void
    {
        $this->assertCanApproveForParticipant($participantLogin, $actor);
        $this->setParticipantStatus($id, $participantLogin, ParticipantStatus::REJECTED, $actor);
    }

    /**
     * Replaces participants on an event.
     *
     * - Validates all logins exist
     * - Deduplicates
     * - Ensures the event creator stays as a participant
     * - New participants get status 'W' (Waiting)
     * - Existing participants keep their current status
     *
     * @param string[] $logins
     * @throws EventNotFoundException if the event does not exist.
     * @throws \InvalidArgumentException if any login does not exist.
     * @throws AuthorizationException if the actor is not authorized.
     */
    public function setParticipants(EventId $id, array $logins, User $actor): void
    {
        $event = $this->findEventOrFail($id);
        $this->assertCanModify($event, $actor, 'set participants');

        $logins = array_values(array_unique($logins));
        $this->assertLoginsExist($logins);

        // Ensure creator is always included
        $creator = $event->createdBy();
        if (!in_array($creator, $logins, true)) {
            $logins[] = $creator;
        }

        $existing = $this->eventRepository->getParticipantsWithStatus($id);

        $merged = [];
        foreach ($logins as $login) {
            $merged[$login] = $existing[$login] ?? ParticipantStatus::WAITING->value;
        }

        $this->logger->info('Setting participants', [
            'event_id' => $id->value(),
            'count' => count($merged),
            'actor' => $actor->login()
        ]);

        $this->eventRepository->saveParticipantsWithStatus($id, $merged);
    }

    /**
     * Adds a single participant to an event.
     *
     * No-op if the participant is already on the event.
     * New participants get status 'W' (Waiting).
     *
     * @throws EventNotFoundException if the event does not exist.
     * @throws \InvalidArgumentException if the login does not exist.
     * @throws AuthorizationException if the actor is not authorized.
     */
    public function addParticipant(EventId $id, string $login, User $actor): void
    {
        $event = $this->findEventOrFail($id);
        $this->assertCanModify($event, $actor, 'add participant');
        $this->assertLoginsExist([$login]);

        $existing = $this->eventRepository->getParticipantsWithStatus($id);
        if (isset($existing[$login])) {
            return;
        }

        $existing[$login] = ParticipantStatus::WAITING->value;

        $this->logger->info('Adding participant', [
            'event_id' => $id->value(),
            'login' => $login,
            'actor' => $actor->login()
        ]);

        $this->eventRepository->saveParticipantsWithStatus($id, $existing);
    }

    /**
     * Removes a participant from an event.
     *
     * No-op if the participant is not on the event.
     * Cannot remove the event creator.
     *
     * @throws EventNotFoundException if the event does not exist.
     * @throws \InvalidArgumentException if attempting to remove the event creator.
     * @throws AuthorizationException if the actor is not authorized.
     */
    public function removeParticipant(EventId $id, string $login, User $actor): void
    {
        $event = $this->findEventOrFail($id);
        $this->assertCanModify($event, $actor, 'remove participant');

        if ($event->createdBy() === $login) {
            throw new \InvalidArgumentException(
                sprintf('Cannot remove event creator "%s" from participants.', $login)
            );
        }

        $existing = $this->eventRepository->getParticipantsWithStatus($id);
        if (!isset($existing[$login])) {
            return;
        }

        unset($existing[$login]);

        $this->logger->info('Removing participant', [
            'event_id' => $id->value(),
            'login' => $login,
            'actor' => $actor->login()
        ]);

        $this->eventRepository->saveParticipantsWithStatus($id, $existing);
    }

    /**
     * Sets the status of a specific participant on an event.
     *
     * @throws EventNotFoundException if the event does not exist.
     * @throws \InvalidArgumentException if the participant is not on the event.
     */
    public function setParticipantStatus(
        EventId $id,
        string $login,
        ParticipantStatus $status,
        User $actor
    ): void {
        $this->findEventOrFail($id);

        $existing = $this->eventRepository->getParticipantsWithStatus($id);
        if (!isset($existing[$login])) {
            throw new \InvalidArgumentException(
                sprintf('User "%s" is not a participant on event %d.', $login, $id->value())
            );
        }

        $this->logger->info('Setting participant status', [
            'event_id' => $id->value(),
            'login' => $login,
            'status' => $status->value,
            'actor' => $actor->login()
        ]);

        $this->eventRepository->updateParticipantStatus($id, $login, $status->value);
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

        throw AuthorizationException::notSelf('modify participant status', $participantLogin, $actor->login());
    }

    /**
     * Finds an event by ID or throws.
     *
     * @throws EventNotFoundException
     */
    private function findEventOrFail(EventId $id): Event
    {
        $event = $this->eventRepository->findById($id);
        if ($event === null) {
            throw EventNotFoundException::forId($id);
        }
        return $event;
    }

    /**
     * Validates that all given logins correspond to existing users.
     *
     * @param string[] $logins
     * @throws \InvalidArgumentException if any login does not exist.
     */
    private function assertLoginsExist(array $logins): void
    {
        $invalid = [];
        foreach ($logins as $login) {
            if ($this->userRepository->findByLogin($login) === null) {
                $invalid[] = $login;
            }
        }

        if ($invalid !== []) {
            throw new \InvalidArgumentException(
                sprintf('Unknown user login(s): %s', implode(', ', $invalid))
            );
        }
    }
}
