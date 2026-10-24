<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration;

use WebCalendar\Core\Application\Service\ExportService;
use WebCalendar\Core\Application\Service\ImportService;
use WebCalendar\Core\Application\Service\RecurrenceService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventCollection;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventScope;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\ExDate;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;

/**
 * Removed and moved occurrences of a series, through iCal import, storage
 * and expansion — asserting what a calendar would actually show.
 *
 * Google and Outlook export a moved or edited occurrence as a second VEVENT
 * with the series' UID and a RECURRENCE-ID naming the occurrence it replaces,
 * and a cancelled one as the same with STATUS:CANCELLED. Matched on UID
 * alone, such an override was either skipped (the edit lost) or, when a
 * remote calendar sync updated existing events, written over the series
 * itself, collapsing it to one event.
 */
final class RecurringIcalImportTest extends RepositoryTestCase
{
    private PdoEventRepository $repository;
    private ImportService $importService;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository    = new PdoEventRepository($this->pdo);
        $this->importService = new ImportService($this->repository, new EventMapper());
        $this->user          = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
    }

    private const MASTER = <<<ICS
BEGIN:VEVENT
UID:series-1
SUMMARY:Book club
DTSTART:20260305T190000
DURATION:PT1H
RRULE:FREQ=MONTHLY;COUNT=4
END:VEVENT
ICS;

    private const MOVED_APRIL = <<<ICS
BEGIN:VEVENT
UID:series-1
RECURRENCE-ID:20260405T190000
SUMMARY:Book club (moved)
DTSTART:20260406T200000
DURATION:PT1H
END:VEVENT
ICS;

    private const CANCELLED_MAY = <<<ICS
BEGIN:VEVENT
UID:series-1
RECURRENCE-ID:20260505T190000
SUMMARY:Book club
DTSTART:20260505T190000
DURATION:PT1H
STATUS:CANCELLED
END:VEVENT
ICS;

    private function ics(string ...$vevents): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
            . implode("\r\n", $vevents)
            . "\r\nEND:VCALENDAR\r\n";
    }

    /**
     * What the calendar shows for March–July 2026, as "title @ Y-m-d H:i".
     *
     * @return string[]
     */
    private function shown(): array
    {
        $range = new DateRange(
            new \DateTimeImmutable('2026-03-01 00:00:00'),
            new \DateTimeImmutable('2026-07-31 23:59:59')
        );
        $expander = new RecurrenceService();
        $shown = [];
        foreach ($this->repository->findByDateRange($range, EventScope::administrative()) as $event) {
            foreach ($expander->expand($event, $range) as $occurrence) {
                $shown[] = $event->name() . ' @ ' . $occurrence->getStart()->format('Y-m-d H:i');
            }
        }
        sort($shown);
        return $shown;
    }

    public function testExDateInTheFileHidesThatOccurrence(): void
    {
        $master = str_replace('RRULE:', "EXDATE:20260405T190000\r\nRRULE:", self::MASTER);

        $this->importService->importIcal($this->ics($master), $this->user);

        $this->assertSame([
            'Book club @ 2026-03-05 19:00',
            'Book club @ 2026-05-05 19:00',
            'Book club @ 2026-06-05 19:00',
        ], $this->shown());
    }

    public function testCommaSeparatedExDateListHidesEveryListedOccurrence(): void
    {
        // RFC 5545 §3.8.5.1 allows several dates in one EXDATE. php-icalendar-core
        // before 1.2.1 could not parse that and dropped the property, so every
        // listed occurrence came back. Parsed from text: building the VEVENT in
        // code skips the parser and hides the failure.
        $master = str_replace('RRULE:', "EXDATE:20260405T190000,20260605T190000\r\nRRULE:", self::MASTER);

        $this->importService->importIcal($this->ics($master), $this->user);

        $this->assertSame([
            'Book club @ 2026-03-05 19:00',
            'Book club @ 2026-05-05 19:00',
        ], $this->shown());
    }

    public function testMovedOccurrenceReplacesTheOriginalAndKeepsTheSeries(): void
    {
        $result = $this->importService->importIcal($this->ics(self::MASTER, self::MOVED_APRIL), $this->user);

        $this->assertSame(2, $result->importedCount);
        $this->assertSame([
            'Book club (moved) @ 2026-04-06 20:00',
            'Book club @ 2026-03-05 19:00',
            'Book club @ 2026-05-05 19:00',
            'Book club @ 2026-06-05 19:00',
        ], $this->shown());
    }

    public function testOverrideBeforeItsSeriesInTheFileStillWorks(): void
    {
        $this->importService->importIcal($this->ics(self::MOVED_APRIL, self::MASTER), $this->user);

        $this->assertSame([
            'Book club (moved) @ 2026-04-06 20:00',
            'Book club @ 2026-03-05 19:00',
            'Book club @ 2026-05-05 19:00',
            'Book club @ 2026-06-05 19:00',
        ], $this->shown());
    }

    public function testSyncWithUpdatesDoesNotCollapseTheSeries(): void
    {
        // The remote-calendar path: updateExisting = true. The override used
        // to be written over the series row, leaving one event.
        $ics = $this->ics(self::MASTER, self::MOVED_APRIL);

        $this->importService->importIcal($ics, $this->user, true);
        $second = $this->importService->importIcal($ics, $this->user, true);

        $this->assertSame(0, $second->importedCount);
        $this->assertSame(2, $second->updatedCount);
        $this->assertSame([], $second->warnings);
        $this->assertSame([
            'Book club (moved) @ 2026-04-06 20:00',
            'Book club @ 2026-03-05 19:00',
            'Book club @ 2026-05-05 19:00',
            'Book club @ 2026-06-05 19:00',
        ], $this->shown());
    }

    public function testReimportWithoutUpdatesIsANoOp(): void
    {
        $ics = $this->ics(self::MASTER, self::MOVED_APRIL);

        $this->importService->importIcal($ics, $this->user);
        $second = $this->importService->importIcal($ics, $this->user);

        $this->assertSame(0, $second->importedCount);
        $this->assertSame(2, $second->skippedCount);
        $this->assertSame([], $second->warnings);
        $this->assertCount(4, $this->shown());
    }

    public function testOverrideArrivingInALaterSyncExcludesTheOriginal(): void
    {
        $this->importService->importIcal($this->ics(self::MASTER), $this->user, true);
        $this->importService->importIcal($this->ics(self::MASTER, self::MOVED_APRIL), $this->user, true);

        $this->assertNotContains('Book club @ 2026-04-05 19:00', $this->shown());
        $this->assertContains('Book club (moved) @ 2026-04-06 20:00', $this->shown());
    }

    public function testCancelledOccurrenceIsRemovedWithoutAnEventOfItsOwn(): void
    {
        $result = $this->importService->importIcal($this->ics(self::MASTER, self::CANCELLED_MAY), $this->user);

        $this->assertSame(1, $result->importedCount);
        $this->assertSame([
            'Book club @ 2026-03-05 19:00',
            'Book club @ 2026-04-05 19:00',
            'Book club @ 2026-06-05 19:00',
        ], $this->shown());
    }

    public function testOverrideWithoutItsSeriesIsKeptAsAnEvent(): void
    {
        // A feed filtered by date range can carry the override and not the
        // series. The override is still the best information there is.
        $this->importService->importIcal($this->ics(self::MOVED_APRIL), $this->user);

        $this->assertSame(['Book club (moved) @ 2026-04-06 20:00'], $this->shown());
    }

    public function testCancellationAlreadyExcludedInTheSeriesDoesNotFail(): void
    {
        // Google lists a cancelled occurrence both ways: as an EXDATE on the
        // series and as a STATUS:CANCELLED override. Two exclusions for one
        // day used to break the (cal_id, cal_date) primary key.
        $master = str_replace('RRULE:', "EXDATE:20260505T190000\r\nRRULE:", self::MASTER);

        $result = $this->importService->importIcal($this->ics($master, self::CANCELLED_MAY), $this->user, true);

        $this->assertSame([], $result->warnings);
        $this->assertNotContains('Book club @ 2026-05-05 19:00', $this->shown());
    }

    public function testExportedExclusionSurvivesImportElsewhere(): void
    {
        $series = new Event(
            id: new EventId(0),
            uid: 'export-1',
            name: 'Standup',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-03-02 09:30:00'),
            duration: 15,
            createdBy: 'jdoe',
            type: EventType::REPEATING_EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: new Recurrence(
                rule: new RecurrenceRule('FREQ=WEEKLY;COUNT=3'),
                // As the repository hands it back: right day, request's clock.
                exDate: new ExDate([new \DateTimeImmutable('2026-03-09 15:46:51')]),
            ),
        );
        $ics = (new ExportService(new EventMapper()))->exportIcal(new EventCollection([$series]));

        $this->importService->importIcal($ics, $this->user);

        $this->assertSame([
            'Standup @ 2026-03-02 09:30',
            'Standup @ 2026-03-16 09:30',
        ], $this->shown());
    }

    public function testMovedOccurrenceLaterCancelledUpstreamLeavesNothingBehind(): void
    {
        // The override first moves April's meeting, then a later sync says
        // it is cancelled. The moved copy stored by the first sync must go:
        // the sync's "deleted upstream" check still sees its UID in the feed.
        $cancelledApril = str_replace(
            ["SUMMARY:Book club (moved)\r\nDTSTART:20260406T200000", "END:VEVENT"],
            ["SUMMARY:Book club\r\nDTSTART:20260405T190000", "STATUS:CANCELLED\r\nEND:VEVENT"],
            str_replace("\n", "\r\n", self::MOVED_APRIL)
        );

        $this->importService->importIcal($this->ics(self::MASTER, self::MOVED_APRIL), $this->user, true);
        $this->importService->importIcal($this->ics(self::MASTER, $cancelledApril), $this->user, true);

        $this->assertSame([
            'Book club @ 2026-03-05 19:00',
            'Book club @ 2026-05-05 19:00',
            'Book club @ 2026-06-05 19:00',
        ], $this->shown());
    }
}
