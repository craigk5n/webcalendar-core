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
use WebCalendar\Core\Domain\ValueObject\ExDate;

/**
 * Performance tests for recurrence expansion.
 *
 * Ensures that expanding recurring events into occurrences completes within
 * acceptable time limits, even for large recurrence patterns.
 */
final class RecurrenceExpansionPerformanceTest extends TestCase
{
    private RecurrenceService $service;

    protected function setUp(): void
    {
        $this->service = new RecurrenceService();
    }

    private function createRepeatingEvent(
        string $rrule,
        string $startDate = '2026-01-01 10:00:00',
        int $duration = 60,
        ?ExDate $exDate = null,
    ): Event {
        return new Event(
            id: new EventId(1),
            uid: 'perf-test-' . md5($rrule),
            name: 'Performance Test Event',
            description: '',
            location: '',
            start: new \DateTimeImmutable($startDate),
            duration: $duration,
            createdBy: 'test',
            type: EventType::REPEATING_EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: new Recurrence(
                rule: new RecurrenceRule($rrule),
                exDate: $exDate ?? new ExDate(),
            ),
        );
    }

    /**
     * Daily event expanded over 1 year (~365 occurrences).
     */
    public function testDailyExpansionOverOneYear(): void
    {
        $event = $this->createRepeatingEvent('FREQ=DAILY');
        $range = new DateRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-12-31 23:59:59'),
        );

        $start = microtime(true);
        $occurrences = $this->service->expand($event, $range);
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(365, count($occurrences));
        $this->assertLessThan(1.0, $elapsed, "Daily expansion over 1 year took {$elapsed}s (limit: 1s)");
    }

    /**
     * Weekly event expanded over 5 years (~260 occurrences).
     */
    public function testWeeklyExpansionOverFiveYears(): void
    {
        $event = $this->createRepeatingEvent('FREQ=WEEKLY');
        $range = new DateRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2030-12-31 23:59:59'),
        );

        $start = microtime(true);
        $occurrences = $this->service->expand($event, $range);
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(260, count($occurrences));
        $this->assertLessThan(1.0, $elapsed, "Weekly expansion over 5 years took {$elapsed}s (limit: 1s)");
    }

    /**
     * Monthly event expanded over 10 years (120 occurrences).
     */
    public function testMonthlyExpansionOverTenYears(): void
    {
        $event = $this->createRepeatingEvent('FREQ=MONTHLY;COUNT=120');
        $range = new DateRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2035-12-31 23:59:59'),
        );

        $start = microtime(true);
        $occurrences = $this->service->expand($event, $range);
        $elapsed = microtime(true) - $start;

        $this->assertCount(120, $occurrences);
        $this->assertLessThan(0.5, $elapsed, "Monthly expansion over 10 years took {$elapsed}s (limit: 0.5s)");
    }

    /**
     * Complex BYDAY rule: MWF weekly over 2 years (~312 occurrences).
     */
    public function testComplexBydayExpansion(): void
    {
        $event = $this->createRepeatingEvent('FREQ=WEEKLY;BYDAY=MO,WE,FR');
        $range = new DateRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2027-12-31 23:59:59'),
        );

        $start = microtime(true);
        $occurrences = $this->service->expand($event, $range);
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(300, count($occurrences));
        $this->assertLessThan(1.0, $elapsed, "MWF weekly expansion over 2 years took {$elapsed}s (limit: 1s)");
    }

    /**
     * Daily event with many EXDATE exclusions (~300 occurrences after 65 exclusions).
     */
    public function testDailyWithManyExdates(): void
    {
        $exDates = [];
        $dt = new \DateTimeImmutable('2026-01-01 10:00:00');
        // Exclude every Saturday and Sunday (roughly 2/7 of days)
        for ($i = 0; $i < 365; $i++) {
            $day = $dt->modify("+{$i} days");
            $dow = (int) $day->format('N');
            if ($dow === 6 || $dow === 7) {
                $exDates[] = $day;
            }
        }

        $event = $this->createRepeatingEvent(
            'FREQ=DAILY',
            exDate: new ExDate($exDates),
        );

        $range = new DateRange(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-12-31 23:59:59'),
        );

        $start = microtime(true);
        $occurrences = $this->service->expand($event, $range);
        $elapsed = microtime(true) - $start;

        // ~365 days minus ~104 weekends = ~261
        $this->assertGreaterThanOrEqual(250, count($occurrences));
        $this->assertLessThan(1.0, $elapsed, "Daily with EXDATE expansion took {$elapsed}s (limit: 1s)");
    }

    /**
     * Narrow window query on a long-running daily event.
     * Tests that expansion over a small range doesn't degrade even
     * when the event spans many years.
     */
    public function testNarrowRangeOnLongRunningEvent(): void
    {
        $event = $this->createRepeatingEvent(
            'FREQ=DAILY',
            startDate: '2020-01-01 10:00:00',
        );

        // Query only 1 week in 2026 (even though event started in 2020)
        $range = new DateRange(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-07 23:59:59'),
        );

        $start = microtime(true);
        $occurrences = $this->service->expand($event, $range);
        $elapsed = microtime(true) - $start;

        $this->assertCount(7, $occurrences);
        $this->assertLessThan(2.0, $elapsed, "Narrow range on 6-year daily event took {$elapsed}s (limit: 2s)");
    }

    /**
     * Multiple events expanded in batch (simulates get_events with many recurring events).
     */
    public function testBatchExpansionOfMultipleEvents(): void
    {
        $range = new DateRange(
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            new \DateTimeImmutable('2026-02-28 23:59:59'),
        );

        $events = [];
        $rules = [
            'FREQ=DAILY',
            'FREQ=WEEKLY;BYDAY=MO,WE,FR',
            'FREQ=WEEKLY',
            'FREQ=MONTHLY;BYMONTHDAY=1,15',
            'FREQ=DAILY;INTERVAL=3',
        ];

        for ($i = 0; $i < 20; $i++) {
            $events[] = new Event(
                id: new EventId($i + 1),
                uid: 'batch-' . $i,
                name: "Batch Event {$i}",
                description: '',
                location: '',
                start: new \DateTimeImmutable('2026-01-01 ' . sprintf('%02d', 8 + ($i % 10)) . ':00:00'),
                duration: 30,
                createdBy: 'test',
                type: EventType::REPEATING_EVENT,
                access: AccessLevel::PUBLIC,
                recurrence: new Recurrence(
                    rule: new RecurrenceRule($rules[$i % count($rules)]),
                ),
            );
        }

        $start = microtime(true);
        $totalOccurrences = 0;
        foreach ($events as $event) {
            $occurrences = $this->service->expand($event, $range);
            $totalOccurrences += count($occurrences);
        }
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThan(100, $totalOccurrences);
        $this->assertLessThan(2.0, $elapsed, "Batch expansion of 20 events took {$elapsed}s (limit: 2s)");
    }
}
