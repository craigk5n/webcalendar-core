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
final readonly class RecurrenceService
{
    private RecurrenceExpander $expander;

    public function __construct()
    {
        $this->expander = new RecurrenceExpander();
    }

    /**
     * Expands a repeating event into a list of occurrences within the given date range.
     * 
     * @param Event $event The event to expand.
     * @param DateRange $range The date range for expansion.
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

        foreach ($recurrence->exDate()->dates() as $date) {
            $vevent->addExdate($date->format('Ymd\THis'));
        }

        foreach ($recurrence->rDate()->dates() as $date) {
            $vevent->addRdate($date->format('Ymd\THis'));
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
