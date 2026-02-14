<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use Icalendar\Component\VCalendar;
use Icalendar\Writer\Writer;

/**
 * Service for exporting calendar data to external formats.
 */
final readonly class ExportService
{
    private Writer $writer;

    public function __construct(
        private EventMapper $eventMapper
    ) {
        $this->writer = new Writer();
    }

    /**
     * Exports a collection of events to an iCalendar (.ics) string.
     *
     * @param EventCollection $events The events to export.
     * @param array<int, string[]> $categoryMap Optional map of event ID → category names.
     * @return string The generated ICS content.
     */
    public function exportIcal(EventCollection $events, array $categoryMap = []): string
    {
        $vcalendar = new VCalendar();
        $vcalendar->setProductId('-//WebCalendar//NONSGML v4.0//EN');
        $vcalendar->setVersion('2.0');

        foreach ($events as $event) {
            $vevent = $this->eventMapper->toVEvent($event);

            $eventId = $event->id()->value();
            if (isset($categoryMap[$eventId]) && !empty($categoryMap[$eventId])) {
                $this->eventMapper->addCategoryNames($vevent, $categoryMap[$eventId]);
            }

            $vcalendar->addComponent($vevent);
        }

        return $this->writer->write($vcalendar);
    }
}
