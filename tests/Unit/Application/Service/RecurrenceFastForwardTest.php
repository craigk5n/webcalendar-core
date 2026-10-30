<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\RecurrenceService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\ExDate;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;

/**
 * expand() starts DAILY and WEEKLY generation just before the range rather
 * than at DTSTART, so a series' age no longer sets the cost of every view.
 * The shortcut must never change which occurrences come back: each case is
 * checked against an expansion over a range that starts at DTSTART (which
 * nothing can shorten), filtered to the window.
 */
final class RecurrenceFastForwardTest extends TestCase
{
    private RecurrenceService $service;

    protected function setUp(): void
    {
        $this->service = new RecurrenceService();
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function rules(): array
    {
        return [
            'daily' => ['FREQ=DAILY', '2019-01-01 09:00', '2026-03-01', '2026-03-31'],
            'every 3 days' => ['FREQ=DAILY;INTERVAL=3', '2018-05-17 09:00', '2026-03-01', '2026-03-31'],
            'weekdays' => ['FREQ=WEEKLY;BYDAY=MO,WE,FR', '2017-01-04 18:30', '2026-03-01', '2026-03-31'],
            'fortnightly, start off its days' => ['FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,TH', '2016-02-03 08:00', '2026-03-01', '2026-03-31'],
            'fortnightly plain' => ['FREQ=WEEKLY;INTERVAL=2', '2015-07-06 12:00', '2026-03-01', '2026-04-30'],
            'daily in March only' => ['FREQ=DAILY;BYMONTH=3', '2014-03-10 07:00', '2026-03-01', '2026-03-31'],
            'until inside the window' => ['FREQ=DAILY;UNTIL=20260315T235959Z', '2016-01-01 09:00', '2026-03-01', '2026-03-31'],
            'until before the window' => ['FREQ=WEEKLY;UNTIL=20250101T000000Z', '2016-01-01 09:00', '2026-03-01', '2026-03-31'],
            'window one day long' => ['FREQ=WEEKLY;INTERVAL=3;BYDAY=SA', '2013-06-01 10:00', '2026-03-14', '2026-03-14'],
            'window right after start' => ['FREQ=DAILY;INTERVAL=2', '2026-02-20 09:00', '2026-03-01', '2026-03-10'],
        ];
    }

    #[DataProvider('rules')]
    public function testFastForwardKeepsExactlyTheSameOccurrences(
        string $rule,
        string $start,
        string $from,
        string $to,
    ): void {
        $event = $this->series($rule, $start);
        $window = $this->range($from, $to);

        $this->assertSame(
            $this->reference($event, $window),
            $this->starts($this->service->expand($event, $window))
        );
    }

    public function testExDatesInsideTheWindowStillApply(): void
    {
        $event = $this->series('FREQ=DAILY', '2018-01-01 09:00', new ExDate([
            new \DateTimeImmutable('2026-03-05'),
            new \DateTimeImmutable('2026-03-06'),
        ]));
        $window = $this->range('2026-03-01', '2026-03-10');

        $got = $this->starts($this->service->expand($event, $window));

        $this->assertSame($this->reference($event, $window), $got);
        $this->assertNotContains('2026-03-05 09:00', $got);
        $this->assertCount(8, $got);
    }

    public function testCountRulesAreNotMoved(): void
    {
        // COUNT counts from DTSTART; 10 daily occurrences from 2026-02-25
        // end on 2026-03-06.
        $event = $this->series('FREQ=DAILY;COUNT=10', '2026-02-25 09:00');

        $got = $this->starts($this->service->expand($event, $this->range('2026-03-01', '2026-03-31')));

        $this->assertSame(['2026-03-01 09:00', '2026-03-02 09:00', '2026-03-03 09:00', '2026-03-04 09:00',
            '2026-03-05 09:00', '2026-03-06 09:00'], $got);
    }

    public function testASeriesFromTheYear1000IsCheapToView(): void
    {
        $event = $this->series('FREQ=DAILY', '1000-01-01 09:00');

        $t = microtime(true);
        $got = $this->service->expand($event, $this->range('2026-03-01', '2026-03-31'));
        $elapsed = microtime(true) - $t;

        $this->assertCount(31, $got);
        $this->assertSame('2026-03-01 09:00', $got[0]->getStart()->format('Y-m-d H:i'));
        $this->assertLessThan(0.5, $elapsed, 'cost must not grow with the series age');
    }

    private function series(string $rule, string $start, ?ExDate $exDate = null): Event
    {
        return new Event(
            id: new EventId(1),
            uid: 'series',
            name: 'Series',
            description: '',
            location: '',
            start: new \DateTimeImmutable($start),
            duration: 60,
            createdBy: 'alice',
            type: EventType::REPEATING_EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: new Recurrence(new RecurrenceRule($rule), $exDate ?? new ExDate()),
        );
    }

    private function range(string $from, string $to): DateRange
    {
        return new DateRange(
            new \DateTimeImmutable($from . ' 00:00:00'),
            new \DateTimeImmutable($to . ' 23:59:59')
        );
    }

    /** @return list<string> */
    private function reference(Event $event, DateRange $window): array
    {
        $whole = new DateRange($event->start(), $window->endDate());
        $all = $this->service->expand($event, $whole);

        return array_values(array_filter(
            $this->starts($all),
            static fn (string $s): bool => $s >= $window->startDate()->format('Y-m-d H:i')
        ));
    }

    /**
     * @param array<\Icalendar\Recurrence\Occurrence> $occurrences
     * @return list<string>
     */
    private function starts(array $occurrences): array
    {
        return array_values(array_map(
            static fn ($o): string => $o->getStart()->format('Y-m-d H:i'),
            $occurrences
        ));
    }
}
