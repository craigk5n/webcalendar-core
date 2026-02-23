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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for importing calendar data from external formats.
 */
final readonly class ImportService
{
    private Parser $parser;
    private LoggerInterface $logger;

    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private EventMapper $eventMapper,
        private ?CategoryRepositoryInterface $categoryRepository = null,
        private int $maxContentSize = 10485760, // 10MB default
        private int $maxEvents = 1000, // 1000 events default
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
     * @throws ImportLimitException If import limits are exceeded.
     */
    public function importIcal(string $icsContent, User $user): ImportResult
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

        try {
            $vcalendar = $this->parser->parse($icsContent);
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse iCal content', ['error' => $e->getMessage()]);
            throw $e;
        }

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
        $warnings = [];

        foreach ($components as $component) {
            if ($component instanceof VEvent) {
                try {
                    $event = $this->eventMapper->fromVEvent($component, $user->login());

                    // Update detection: check if an event with the same UID already exists
                    $existingEvent = $this->eventRepository->findByUid($event->uid());

                    if ($existingEvent !== null) {
                        $this->logger->debug('Skipping existing event', ['uid' => $event->uid()]);
                        $skipped++;
                        continue;
                    }

                    $this->eventRepository->create($event);
                    $imported++;

                    // Handle categories if present in the component and repo is available
                    if ($this->categoryRepository !== null) {
                        $this->importCategories($component, $event, $user);
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
            'warning_count' => count($warnings),
        ]);

        return new ImportResult($imported, $skipped, $warnings);
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

        $catNames = explode(',', $categories->getValue()->getRawValue());
        foreach ($catNames as $catName) {
            $catName = trim($catName);
            if ($catName === '') {
                continue;
            }

            $category = $this->categoryRepository->findByName($catName, $user->login());
            if ($category === null) {
                $category = new Category(0, $user->login(), $catName, null);
                $this->categoryRepository->create($category);
                $category = $this->categoryRepository->findByName($catName, $user->login());
            }

            if ($category !== null) {
                $this->categoryRepository->assignToEvent($event->id(), $user->login(), [$category->id()]);
            }
        }
    }
}
