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

        foreach ($components as $component) {
            if ($component instanceof VEvent) {
                try {
                    $event = $this->eventMapper->fromVEvent($component, $user->login());

                    // Update detection: check if an event with the same UID already exists
                    $existingEvent = $this->eventRepository->findByUid($event->uid());

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

    private function importCategories(VEvent $component, Event $event, User $user): void
    {
        if ($this->categoryRepository === null) {
            return;
        }

        $categories = $component->getProperty('CATEGORIES');
        if ($categories === null) {
            return;
        }

        $categoryIds = [];
        foreach ($this->categoryNames($categories->getValue()) as $catName) {
            $category = $this->categoryRepository->findByName($catName, $user->login());
            if ($category === null) {
                $category = new Category(0, $user->login(), $catName, null);
                $this->categoryRepository->create($category);
                $category = $this->categoryRepository->findByName($catName, $user->login());
            }

            if ($category !== null) {
                $categoryIds[] = $category->id();
            }
        }

        // Assign the full set in one call. assignToEvent() replaces the event's
        // assignments wholesale (delete-then-insert), so a single call both
        // preserves every category on the event (calling it per-name would keep
        // only the last) and lets an update overwrite the previous set.
        if ($categoryIds !== []) {
            $this->categoryRepository->assignToEvent($event->id(), $user->login(), $categoryIds);
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
