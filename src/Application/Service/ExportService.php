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
     * @return string The generated ICS content.
     */
    public function exportIcal(EventCollection $events): string
    {
        $vcalendar = new VCalendar();
        $vcalendar->setProductId('-//WebCalendar//NONSGML v4.0//EN');
        $vcalendar->setVersion('2.0');

        foreach ($events as $event) {
            $vevent = $this->eventMapper->toVEvent($event);
            $vcalendar->addComponent($vevent);
        }

        return $this->writer->write($vcalendar);
    }
}
