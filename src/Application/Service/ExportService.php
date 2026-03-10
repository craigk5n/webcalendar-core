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
final readonly class ExportService
{
    private Writer $writer;
    private LoggerInterface $logger;

    public function __construct(
        private EventMapper $eventMapper,
        ?LoggerInterface $logger = null
    ) {
        $this->writer = new Writer();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Exports a collection of events to an iCalendar (.ics) string.
     *
     * @param EventCollection $events
     * @param array<int, string[]> $categoryMap Event ID → category names.
     */
    public function exportIcal(EventCollection $events, array $categoryMap = []): string
    {
        $this->logger->info('Exporting events to iCal', ['count' => count($events->all())]);

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
