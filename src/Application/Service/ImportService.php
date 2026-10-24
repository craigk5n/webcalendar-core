<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Application\DTO\ImportResult;
use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\CategoryRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\ExDate;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use Icalendar\Parser\Parser;
use Icalendar\Component\VEvent;
use Icalendar\Value\TextListValue;
use Icalendar\Value\ValueInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for importing calendar data from external formats.
 */
final class ImportService
{
    private readonly Parser $parser;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventMapper $eventMapper,
        private readonly ?CategoryRepositoryInterface $categoryRepository = null,
        private readonly int $maxContentSize = 10485760, // 10MB default
        private readonly int $maxEvents = 1000, // 1000 events default
        ?LoggerInterface $logger = null,
        // Appended so positional callers keep working (Epic 22 follow-up).
        private readonly ?\WebCalendar\Core\Domain\Repository\OrganizerRepositoryInterface $organizerRepository = null,
    ) {
        $this->parser = new Parser(Parser::LENIENT);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Imports events from an iCalendar (.ics) string.
     *
     * @param string $icsContent The ICS file content.
     * @param User $user The user importing the events.
     * @param bool $updateExisting When a VEVENT's UID already exists, overwrite
     *   that event with the incoming values instead of skipping it. Defaults to
     *   false, preserving the historical skip-only behaviour.
     *
     *   Callers mirroring a remote feed should pass true: without it, a change
     *   made upstream (a time moved, a title edited) can never propagate, since
     *   the UID is seen and the event skipped on every subsequent sync.
     * @throws ImportLimitException If import limits are exceeded.
     */
    public function importIcal(string $icsContent, User $user, bool $updateExisting = false): ImportResult
    {
        $contentSize = strlen($icsContent);
        $this->logger->info('Starting iCal import', [
            'user' => $user->login(),
            'content_length' => $contentSize,
            'max_size' => $this->maxContentSize,
        ]);

        // Check content size limit
        if ($contentSize > $this->maxContentSize) {
            $this->logger->error('Import content too large', [
                'size' => $contentSize,
                'max_size' => $this->maxContentSize,
            ]);
            throw ImportLimitException::contentTooLarge($contentSize, $this->maxContentSize);
        }

        $vcalendar = $this->parser->parse($icsContent);

        // Count events first to check limit
        $components = $vcalendar->getComponents();
        $eventCount = 0;
        foreach ($components as $component) {
            if ($component instanceof VEvent) {
                $eventCount++;
            }
        }

        if ($eventCount > $this->maxEvents) {
            $this->logger->error('Import contains too many events', [
                'count' => $eventCount,
                'max_events' => $this->maxEvents,
            ]);
            throw ImportLimitException::tooManyEvents($eventCount, $this->maxEvents);
        }

        $imported = 0;
        $skipped = 0;
        $updated = 0;
        $warnings = [];

        // Series first, then the overrides of their occurrences: an override
        // has to find its series, which may come later in the file.
        $series = [];
        $overrides = [];
        foreach ($components as $component) {
            if ($component instanceof VEvent) {
                if ($component->getProperty('RECURRENCE-ID') === null) {
                    $series[] = $component;
                } else {
                    $overrides[] = $component;
                }
            }
        }

        foreach (array_merge($series, $overrides) as $component) {
            try {
                if ($component->getProperty('RECURRENCE-ID') !== null) {
                    $this->excludeOverriddenOccurrence($component, $user);
                    if ($this->isCancelled($component)) {
                        // An earlier sync may have stored this occurrence
                        // as moved; cancelled now, that copy goes too.
                        $stale = $this->findOwnedByUid(self::localUid($component), $user);
                        if ($stale !== null) {
                            $this->eventRepository->delete($stale->id());
                        }
                        continue;
                    }
                }

                $event = $this->attachOrganizer(
                    $component,
                    $this->eventMapper->fromVEvent($component, $user->login())
                );
                $event = $event->with(uid: self::localUid($component));

                // Update detection: check if an event with the same UID already exists
                $existingEvent = $this->eventRepository->findByUid($event->uid());

                // cal_uid is unique site-wide, so a match can belong to anyone.
                // It is the importer's to update only if the importer owns it;
                // otherwise this is a collision, reported and left alone.
                if ($existingEvent !== null && $existingEvent->createdBy() !== $user->login()) {
                    $this->logger->warning('Skipping event whose UID belongs to another calendar', [
                        'uid' => $event->uid(),
                    ]);
                    $warnings[] = [
                        'line' => 0,
                        'message' => sprintf('Skipped event %s: its UID belongs to another calendar.', $event->uid()),
                    ];
                    $skipped++;
                    continue;
                }

                if ($existingEvent !== null) {
                    if (!$updateExisting) {
                        $this->logger->debug('Skipping existing event', ['uid' => $event->uid()]);
                        $skipped++;
                        continue;
                    }

                    // Re-point the mapped event at the existing row so save()
                    // overwrites it rather than inserting a duplicate.
                    $saved = $event->withId($existingEvent->id());
                    $this->eventRepository->save($saved);
                    $this->logger->debug('Updated existing event', [
                        'uid' => $event->uid(),
                        'id' => $existingEvent->id()->value(),
                    ]);

                    // Re-sync categories on update too. Without this a
                    // CATEGORIES change made upstream never propagates once
                    // the event exists, since every later sync takes this
                    // branch. $saved carries the real row id.
                    if ($this->categoryRepository !== null) {
                        $this->importCategories($component, $saved, $user);
                    }

                    $updated++;
                    continue;
                }

                $this->eventRepository->create($event);
                $imported++;

                // Handle categories if present in the component and repo is available.
                if ($this->categoryRepository !== null) {
                    // create() has a void contract and Event is immutable, so
                    // the mapped $event still carries EventId(0). Re-read the
                    // row by UID to obtain its generated id before assigning
                    // categories, otherwise they attach to cal_id 0.
                    $persisted = $event->uid() !== ''
                        ? $this->eventRepository->findByUid($event->uid())
                        : null;
                    if ($persisted !== null) {
                        $this->importCategories($component, $persisted, $user);
                    }
                }
            } catch (\Exception $e) {
                $uid = $component->getProperty('UID')?->getValue()->getRawValue() ?? 'unknown';
                $this->logger->warning('Failed to import VEVENT', [
                    'error' => $e->getMessage(),
                    'uid' => $uid,
                ]);
                $warnings[] = [
                    'line' => 0,
                    'message' => sprintf('Failed to import event %s: %s', $uid, $e->getMessage()),
                ];
            }
        }

        $this->logger->info('iCal import completed', [
            'imported_count' => $imported,
            'skipped_count' => $skipped,
            'updated_count' => $updated,
            'warning_count' => count($warnings),
        ]);

        return new ImportResult($imported, $skipped, $warnings, $updated);
    }

    /**
     * The UID an imported VEVENT is stored under.
     *
     * A series keeps its own UID. An override of one occurrence shares the
     * series' UID in the file (RFC 5545 §3.8.4.4), and is stored as an event
     * of its own, so it gets the UID and RECURRENCE-ID together — stable
     * across syncs, and distinct from the series it would otherwise
     * overwrite. Anything that compares a feed's UIDs with stored ones (a
     * remote calendar sync deciding what was deleted upstream) must use this.
     */
    public static function localUid(VEvent $vevent): string
    {
        $uid = trim($vevent->getUid() ?? '');
        $recurrenceId = $vevent->getProperty('RECURRENCE-ID');
        if ($recurrenceId === null || $uid === '') {
            return $uid;
        }

        return $uid . '/' . trim($recurrenceId->getValue()->getRawValue());
    }

    /**
     * Take the occurrence an override replaces out of its series, so the
     * calendar shows the override instead of both. A no-op when the series
     * is not stored (a date-filtered feed can carry the override alone) or
     * already excludes that day.
     */
    private function excludeOverriddenOccurrence(VEvent $override, User $user): void
    {
        $occurrence = $this->eventMapper->extractRecurrenceId($override);
        $uid = trim($override->getUid() ?? '');
        if ($occurrence === null || $uid === '') {
            return;
        }

        $series = $this->findOwnedByUid($uid, $user);
        if ($series === null || !$series->recurrence()->isRepeating()) {
            return;
        }

        $recurrence = $series->recurrence();
        $day = $occurrence->format('Y-m-d');
        foreach ($recurrence->exDate()->dates() as $excluded) {
            if ($excluded->format('Y-m-d') === $day) {
                return;
            }
        }

        $this->eventRepository->save($series->with(recurrence: new Recurrence(
            $recurrence->rule(),
            new ExDate([...$recurrence->exDate()->dates(), $occurrence]),
            $recurrence->rDate(),
        )));
    }

    /**
     * The event stored under a UID, if the importer owns it.
     *
     * The UID lookup is site-wide. An override in a feed or file must not be
     * able to EXDATE or delete another user's event just by naming its UID.
     */
    private function findOwnedByUid(string $uid, User $user): ?Event
    {
        $event = $this->eventRepository->findByUid($uid);

        return $event !== null && $event->createdBy() === $user->login() ? $event : null;
    }

    /** A cancelled occurrence only removes; it has no event of its own. */
    private function isCancelled(VEvent $vevent): bool
    {
        $status = $vevent->getProperty('STATUS');
        return $status !== null && strtoupper(trim($status->getValue()->getRawValue())) === 'CANCELLED';
    }

    /**
     * Gets the maximum content size in bytes.
     */
    public function getMaxContentSize(): int
    {
        return $this->maxContentSize;
    }

    /**
     * Gets the maximum number of events allowed.
     */
    public function getMaxEvents(): int
    {
        return $this->maxEvents;
    }

    /**
     * Match-or-create the VEVENT's ORGANIZER as an Organizer entity and
     * point the event at it (Epic 22 follow-up). Without a configured
     * organizer repository the event passes through untouched.
     */
    private function attachOrganizer(VEvent $component, Event $event): Event
    {
        if ($this->organizerRepository === null) {
            return $event;
        }
        $data = $this->eventMapper->extractOrganizer($component);
        if ($data === null) {
            return $event;
        }

        $existing = $this->organizerRepository->findByName($data['name']);
        if ($existing === null) {
            try {
                $existing = $this->organizerRepository->save(
                    new \WebCalendar\Core\Domain\Entity\Organizer(
                        id: new \WebCalendar\Core\Domain\ValueObject\OrganizerId(0),
                        name: $data['name'],
                        email: $data['email'],
                    )
                );
            } catch (\InvalidArgumentException) {
                // A malformed mailto address must not fail the event; keep
                // the organizer as a name-only entity.
                $existing = $this->organizerRepository->save(
                    new \WebCalendar\Core\Domain\Entity\Organizer(
                        id: new \WebCalendar\Core\Domain\ValueObject\OrganizerId(0),
                        name: $data['name'],
                    )
                );
            }
        }

        return $event->withOrganizerId($existing->id());
    }

    private function importCategories(VEvent $component, Event $event, User $user): void
    {
        if ($this->categoryRepository === null) {
            return;
        }

        $categories = $component->getProperty('CATEGORIES');
        if ($categories === null) {
            return;
        }

        $names = $this->categoryNames($categories->getValue());

        // X-WEBCAL-TAGS says which of the event's labels are tags; it does
        // not add any. Iterating CATEGORIES rather than the tag list is what
        // enforces that, and it matters: the two properties are one source
        // of truth split in two, so anything that rewrites CATEGORIES — a
        // hand edit, another calendar app — can leave the tag list naming
        // labels the event no longer carries. Those are ignored here rather
        // than resurrected as labels the file no longer claims.
        $tagNames = $this->eventMapper->extractTagNames($component);

        $categoryIds = [];
        $tagIds = [];
        foreach ($names as $catName) {
            if (in_array($catName, $tagNames, true)) {
                $tag = $this->categoryRepository->findTagByName($catName);
                if ($tag === null) {
                    // Tags are global, so no owner — the entity rejects one.
                    $this->categoryRepository->create(
                        new Category($this->categoryRepository->nextId(), null, $catName, null, true, true)
                    );
                    $tag = $this->categoryRepository->findTagByName($catName);
                }
                if ($tag !== null) {
                    $tagIds[] = $tag->id();
                }
                continue;
            }

            $category = $this->categoryRepository->findByName($catName, $user->login());
            if ($category === null) {
                // A real id, not 0: save() writes the id it is given, and the
                // composite key is (cat_id, cat_owner). Creating every new
                // category as id 0 made the second one an UPDATE of the
                // first, so a file introducing two categories ended up with
                // only the last — having overwritten the other.
                $category = new Category($this->categoryRepository->nextId(), $user->login(), $catName, null);
                $this->categoryRepository->create($category);
                $category = $this->categoryRepository->findByName($catName, $user->login());
            }

            if ($category !== null) {
                $categoryIds[] = $category->id();
            }
        }

        // Assign the full set in one call. assignToEvent() replaces the event's
        // assignments wholesale (delete-then-insert), so a single call both
        // preserves every label on the event (calling it per-name would keep
        // only the last, and calling it once per kind would keep only the
        // second kind) and lets an update overwrite the previous set.
        // Categories lead so the colour-bearing label stays primary.
        $labelIds = array_merge($categoryIds, $tagIds);
        if ($labelIds !== []) {
            $this->categoryRepository->assignToEvent($event->id(), $user->login(), $labelIds);
        }
    }

    /**
     * Extracts individual category names from a CATEGORIES value. Since
     * php-icalendar-core 1.2.0 the parser produces a TextListValue whose items
     * are split on unescaped commas and unescaped, so "Food\,Drink,Travel"
     * yields ["Food,Drink", "Travel"]. Leading/trailing whitespace is trimmed
     * and empty names are dropped.
     *
     * @return list<string>
     */
    private function categoryNames(ValueInterface $value): array
    {
        $names = $value instanceof TextListValue
            ? $value->getItems()
            // Programmatically-built components may carry a scalar TEXT value;
            // its raw value is already unescaped, so split on every comma.
            : explode(',', $value->getRawValue());

        $result = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name !== '') {
                $result[] = $name;
            }
        }

        return $result;
    }
}
