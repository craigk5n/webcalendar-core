<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\RecurrenceService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;

final class RecurrenceServiceTest extends TestCase
{
    private RecurrenceService $recurrenceService;

    protected function setUp(): void
    {
        $this->recurrenceService = new RecurrenceService();
    }

    public function testExpandDailyEvent(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = new Event(
            id: new EventId(1),
            uid: 'uid-1',
            name: 'Daily Meeting',
            description: '',
            location: '',
            start: $start,
            duration: 30,
            createdBy: 'admin',
            type: EventType::REPEATING_EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: new Recurrence(
                rule: new RecurrenceRule('FREQ=DAILY;COUNT=3')
            )
        );

        $range = new DateRange(
            new \DateTimeImmutable('2026-02-11 00:00:00'),
            new \DateTimeImmutable('2026-02-28 23:59:59')
        );

        $occurrences = $this->recurrenceService->expand($event, $range);

        $this->assertCount(3, $occurrences);
        $this->assertEquals($start, $occurrences[0]->getStart());
        $this->assertEquals($start->modify('+1 day'), $occurrences[1]->getStart());
        $this->assertEquals($start->modify('+2 days'), $occurrences[2]->getStart());
    }

    public function testExpandEventWithExDate(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $exDate = $start->modify('+1 day');
        
        $event = new Event(
            id: new EventId(1),
            uid: 'uid-1',
            name: 'Daily Meeting',
            description: '',
            location: '',
            start: $start,
            duration: 30,
            createdBy: 'admin',
            type: EventType::REPEATING_EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: new Recurrence(
                rule: new RecurrenceRule('FREQ=DAILY;COUNT=3'),
                exDate: new \WebCalendar\Core\Domain\ValueObject\ExDate([$exDate])
            )
        );

        $range = new DateRange(
            new \DateTimeImmutable('2026-02-11 00:00:00'),
            new \DateTimeImmutable('2026-02-28 23:59:59')
        );

        $occurrences = $this->recurrenceService->expand($event, $range);

        $this->assertCount(2, $occurrences);
        $this->assertEquals($start, $occurrences[0]->getStart());
        $this->assertEquals($start->modify('+2 days'), $occurrences[1]->getStart());
    }
}
