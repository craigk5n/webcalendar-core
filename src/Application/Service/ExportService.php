<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use Icalendar\Component\VCalendar;
use Icalendar\Writer\Writer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for exporting calendar data to external formats.
 */
final class ExportService
{
    private readonly Writer $writer;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EventMapper $eventMapper,
        ?LoggerInterface $logger = null
    ) {
        $this->writer = new Writer();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Exports a collection of events to an iCalendar (.ics) string.
     *
     * Tags are written into CATEGORIES alongside categories, so calendars
     * that know nothing about tags still show every label, and are named
     * again in X-WEBCAL-TAGS so a WebCalendar import can tell the two apart.
     * RFC 5545 offers nothing better: CATEGORIES is its only labelling
     * property, and RFC 7986 did not add one.
     *
     * @param EventCollection $events
     * @param array<int, string[]> $categoryMap Event ID → category names.
     * @param array<int, string[]> $tagMap      Event ID → tag names.
     */
    public function exportIcal(EventCollection $events, array $categoryMap = [], array $tagMap = []): string
    {
        $this->logger->info('Exporting events to iCal', ['count' => count($events->all())]);

        $vcalendar = new VCalendar();
        $vcalendar->setProductId('-//WebCalendar//NONSGML v4.0//EN');
        $vcalendar->setVersion('2.0');

        foreach ($events as $event) {
            $vevent = $this->eventMapper->toVEvent($event);

            $eventId = $event->id()->value();
            $categories = $categoryMap[$eventId] ?? [];
            $tags = $tagMap[$eventId] ?? [];

            // Categories lead, so a reader taking the first entry as the
            // event's category gets a category rather than a tag.
            $labels = array_merge($categories, $tags);
            if ($labels !== []) {
                $this->eventMapper->addCategoryNames($vevent, $labels);
                $this->eventMapper->addTagNames($vevent, $tags);
            }

            $vcalendar->addComponent($vevent);
        }

        return $this->writer->write($vcalendar);
    }
}
