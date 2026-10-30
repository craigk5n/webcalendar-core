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
        $vevent->setDtStart($this->generationStart($event, $range)->format('Ymd\THis'));
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

    /**
     * Where generation starts: the series' DTSTART, moved forward by whole
     * periods to just before the range when that cannot change which
     * occurrences fall inside it.
     *
     * The expander walks from DTSTART to the range end, so without this the
     * cost of every view grows with the series' age: a public daily series
     * dated centuries back would cost seconds and over 100 MB on every
     * month anyone looked at. Only DAILY and WEEKLY rules are moved, as the
     * expensive ones whose occurrences repeat exactly every INTERVAL days
     * or weeks. A COUNT rule is left alone (it counts from DTSTART), and so
     * is any rule using parts whose pattern is not a whole number of
     * periods (BYSETPOS, BYWEEKNO, BYYEARDAY). Monthly and yearly series
     * stay cheap over any span.
     */
    private function generationStart(Event $event, DateRange $range): \DateTimeImmutable
    {
        $start = $event->start();
        $rule = $event->recurrence()->rule()?->getRRule();
        if ($rule === null || $rule->hasCount()) {
            return $start;
        }
        if ($rule->getBySetPos() !== [] || $rule->getByWeekNo() !== [] || $rule->getByYearDay() !== []) {
            return $start;
        }

        $periodDays = match ($rule->getFreq()) {
            'DAILY' => $rule->getInterval(),
            'WEEKLY' => 7 * $rule->getInterval(),
            default => 0,
        };
        if ($periodDays < 1) {
            return $start;
        }

        // Stay a full period short of the range, so a WEEKLY;BYDAY week that
        // straddles the new start lies wholly before the range.
        $gapDays = $range->startDate()->getTimestamp() > $start->getTimestamp()
            ? (int) $start->diff($range->startDate())->days
            : 0;
        $periods = intdiv($gapDays, $periodDays) - 1;
        if ($periods < 1) {
            return $start;
        }

        return $start->modify('+' . ($periods * $periodDays) . ' days');
    }
}
