<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\ICal;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;
use Icalendar\Component\VEvent;
use Icalendar\Parser\ValueParser\DateTimeParser;
use Icalendar\Parser\ValueParser\DurationParser;

/**
 * Mapper for translating between Domain Event entities and iCalendar VEvent components.
 */
final readonly class EventMapper
{
    private DateTimeParser $dateTimeParser;
    private DurationParser $durationParser;

    public function __construct()
    {
        $this->dateTimeParser = new DateTimeParser();
        $this->durationParser = new DurationParser();
    }

    /**
     * Maps a VEvent component to a Domain Event entity.
     * 
     * @param VEvent $vevent The iCalendar VEvent component.
     * @param string $createdBy The login of the user importing the event.
     * @return Event
     */
    public function fromVEvent(VEvent $vevent, string $createdBy): Event
    {
        $uid = $vevent->getUid() ?? '';
        $name = $vevent->getSummary() ?? 'Untitled Event';
        $description = $vevent->getDescription() ?? '';
        $location = $vevent->getLocation() ?? '';
        
        $startStr = $vevent->getDtStart();
        $start = $startStr !== null 
            ? $this->dateTimeParser->parse($startStr) 
            : new \DateTimeImmutable();

        $durationMinutes = 0;
        $durationStr = $vevent->getDuration();
        if ($durationStr !== null) {
            $interval = $this->durationParser->parse($durationStr);
            $durationMinutes = $this->intervalToMinutes($interval);
        } else {
            $endStr = $vevent->getDtEnd();
            if ($endStr !== null) {
                $end = $this->dateTimeParser->parse($endStr);
                $durationMinutes = (int) (($end->getTimestamp() - $start->getTimestamp()) / 60);
            }
        }

        $recurrence = new Recurrence();
        $rruleStr = $vevent->getRrule();
        if ($rruleStr !== null) {
            $recurrence = new Recurrence(rule: new RecurrenceRule($rruleStr));
        }

        // Default to Event type and Public access for imports if not specified
        return new Event(
            id: new EventId(0), // 0 indicates a new, unpersisted entity
            uid: $uid,
            name: $name,
            description: $description,
            location: $location,
            start: $start,
            duration: $durationMinutes,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: $recurrence,
            sequence: (int) ($vevent->getProperty('SEQUENCE')?->getValue()->getRawValue() ?? 0),
            status: $vevent->getProperty('STATUS')?->getValue()->getRawValue()
        );
    }

    /**
     * Maps a Domain Event entity to a VEvent component.
     * 
     * @param Event $event The Domain Event entity.
     * @return VEvent
     */
    public function toVEvent(Event $event): VEvent
    {
        $vevent = new VEvent();
        $vevent->setUid($event->uid());
        $vevent->setSummary($event->name());
        $vevent->setDescription($event->description());
        $vevent->setLocation($event->location());
        $vevent->setDtStart($event->start()->format('Ymd\THis'));
        $vevent->setDuration(sprintf('PT%dM', $event->duration()));
        
        if ($event->recurrence()->rule() !== null) {
            $vevent->setRrule($event->recurrence()->rule()->toString());
        }

        return $vevent;
    }

    private function intervalToMinutes(\DateInterval $interval): int
    {
        return (int) ($interval->days * 24 * 60) +
               (int) ($interval->h * 60) +
               (int) ($interval->i);
    }
}
