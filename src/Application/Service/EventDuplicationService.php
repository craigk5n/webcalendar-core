<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\Reminder;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\CategoryRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\ReminderRepositoryInterface;
use WebCalendar\Core\Domain\Repository\SiteExtraRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\EventId;

/**
 * Duplicates an event — single or whole recurring series — under a new
 * identity (Epic 24, a frequent The-Events-Calendar habit).
 *
 * The copy carries the full recurrence (rule, RDATEs, EXDATEs), the
 * venue/organizer references, participants with their statuses, category
 * assignments, the reminder (with its sent-state reset) and site extras.
 * Deliberately NOT copied: the activity log (the copy starts its own
 * history) and attachments (Media Library references are app-side).
 *
 * The caller supplies the new uid and, optionally, the new title —
 * "Copy of X" phrasing is presentation-layer i18n, not domain logic.
 */
final class EventDuplicationService
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ?ReminderRepositoryInterface $reminderRepository = null,
        private readonly ?SiteExtraRepositoryInterface $siteExtraRepository = null,
        private readonly ?ActivityLogService $activityLog = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param EventId $source  Event (or series) to duplicate.
     * @param string  $newUid  Uid for the copy; must not exist yet.
     * @param User    $actor   Becomes the copy's owner.
     * @param ?string $newName Title for the copy; null keeps the original's.
     * @return Event The persisted copy, carrying its new id.
     * @throws \DomainException When the source does not exist.
     * @throws \InvalidArgumentException When the uid is already taken.
     */
    public function duplicate(EventId $source, string $newUid, User $actor, ?string $newName = null): Event
    {
        $original = $this->eventRepository->findById($source);
        if ($original === null) {
            throw new \DomainException(sprintf('Event %d not found.', $source->value()));
        }
        if ($this->eventRepository->findByUid($newUid) !== null) {
            throw new \InvalidArgumentException(sprintf('An event with uid "%s" already exists.', $newUid));
        }

        $copy = new Event(
            id: new EventId(0),
            uid: $newUid,
            name: $newName ?? $original->name(),
            description: $original->description(),
            location: $original->location(),
            start: $original->start(),
            duration: $original->duration(),
            createdBy: $actor->login(),
            type: $original->type(),
            access: $original->access(),
            recurrence: $original->recurrence(),
            sequence: 0,
            status: $original->status(),
            allDay: $original->isAllDay(),
            image: $original->image(),
            venueId: $original->venueId(),
            organizerId: $original->organizerId(),
        );

        $this->eventRepository->save($copy);
        $saved = $this->eventRepository->findByUid($newUid);
        if ($saved === null) {
            throw new \RuntimeException(sprintf('Event "%s" was not found after saving.', $newUid));
        }
        $newId = $saved->id();

        $this->copySidecars($original, $newId, $actor);

        $this->activityLog?->log(
            $newId->value(),
            $actor->login(),
            null,
            ActivityLogType::CREATE,
            sprintf('Duplicated from event %d (uid %s).', $source->value(), $original->uid())
        );
        $this->logger->info('Event duplicated', [
            'source' => $source->value(),
            'copy' => $newId->value(),
            'actor' => $actor->login(),
        ]);

        return $saved;
    }

    private function copySidecars(Event $original, EventId $newId, User $actor): void
    {
        $participants = $this->eventRepository->getParticipantsWithStatus($original->id());
        if ($participants !== []) {
            $this->eventRepository->saveParticipantsWithStatus($newId, $participants);
        }

        $categoryIds = array_map(
            static fn ($category): int => $category->id(),
            $this->categoryRepository->getForEvent($original->id(), $original->createdBy())
        );
        if ($categoryIds !== []) {
            $this->categoryRepository->assignToEvent($newId, $actor->login(), $categoryIds);
        }

        $reminder = $this->reminderRepository?->findByEventId($original->id()->value());
        if ($reminder !== null) {
            // The copy has never sent anything.
            $this->reminderRepository?->save(new Reminder(
                eventId: $newId->value(),
                date: $reminder->date(),
                offset: $reminder->offset(),
                related: $reminder->related(),
                before: $reminder->before(),
                lastSent: 0,
                repeats: $reminder->repeats(),
                duration: $reminder->duration(),
                timesSent: 0,
                action: $reminder->action(),
            ));
        }

        $extras = $this->siteExtraRepository?->getForEvent($original->id()->value()) ?? [];
        if ($extras !== []) {
            $this->siteExtraRepository?->saveForEvent($newId->value(), $extras);
        }
    }
}
