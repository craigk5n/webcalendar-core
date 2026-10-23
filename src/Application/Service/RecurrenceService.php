<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use Icalendar\Component\VEvent;
use Icalendar\Recurrence\RecurrenceExpander;
use Icalendar\Recurrence\Occurrence;

/**
 * Service for expanding repeating events into concrete occurrences.
 */
final class RecurrenceService
{
    private readonly RecurrenceExpander $expander;

    public function __construct()
    {
        $this->expander = new RecurrenceExpander();
    }

    /**
     * Expands a repeating event into occurrences within the given date range.
     *
     * @return Occurrence[]
     */
    public function expand(Event $event, DateRange $range): array
    {
        if (!$event->recurrence()->isRepeating()) {
            return [
                new Occurrence($event->start(), $event->end())
            ];
        }

        $vevent = new VEvent();
        $vevent->setDtStart($event->start()->format('Ymd\THis'));
        $vevent->setDuration('PT' . $event->duration() . 'M');
        $vevent->setUid($event->uid());

        $recurrence = $event->recurrence();
        if ($recurrence->rule() !== null) {
            $vevent->setRrule($recurrence->rule()->toString());
        }

        // EXDATEs and RDATEs are stored by day (cal_date is YYYYMMDD), and
        // the repository rebuilds them with whatever clock time the request
        // ran at. Pin each one to the series' start time, or an EXDATE never
        // matches the occurrence it was written to remove.
        $startTime = $event->start()->format('His');

        foreach ($recurrence->exDate()->dates() as $date) {
            $vevent->addExdate($date->format('Ymd') . 'T' . $startTime);
        }

        foreach ($recurrence->rDate()->dates() as $date) {
            $vevent->addRdate($date->format('Ymd') . 'T' . $startTime);
        }

        $occurrences = [];
        // The expander needs an end date for unbounded rules
        $expansionEnd = $range->endDate();
        
        foreach ($this->expander->expand($vevent, $expansionEnd) as $occurrence) {
            if ($occurrence->getStart() > $range->endDate()) {
                break;
            }
            
            if ($occurrence->getStart() >= $range->startDate()) {
                $occurrences[] = $occurrence;
            }
        }

        return $occurrences;
    }
}
