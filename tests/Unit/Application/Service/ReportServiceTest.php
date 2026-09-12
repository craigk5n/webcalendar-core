<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ReportService;
use WebCalendar\Core\Domain\Repository\ReportRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Entity\Report;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Application\Service\CalendarAccessService;
use WebCalendar\Core\Domain\Repository\CalendarAccessRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\CalendarAccess;
use WebCalendar\Core\Domain\ValueObject\CalendarPermission;

final class ReportServiceTest extends TestCase
{
    /** @var ReportRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $reportRepository;
    /** @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventRepository;
    private ReportService $reportService;

    protected function setUp(): void
    {
        $this->reportRepository = $this->createMock(ReportRepositoryInterface::class);
        $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $eventService = new EventService($this->eventRepository, $userRepository);
        $this->reportService = new ReportService($this->reportRepository, $eventService);
    }

    public function testGenerateReport(): void
    {
        $report = new Report(
            id: 1,
            owner: 'admin',
            name: 'Test Report',
            type: 'daily',
            templates: [
                'E' => 'Event: ${name}'
            ]
        );

        $event = new Event(
            id: new EventId(123),
            uid: 'uid-1',
            name: 'Meeting',
            description: 'Discuss things',
            location: 'Office',
            start: new \DateTimeImmutable('2026-02-11 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $result = $this->reportService->generateEventReport($report, $event);
        
        $this->assertSame('Event: Meeting', $result);
    }

    // ---------------------------------------------------------------------
    // User scoping for generateFullReport()
    //
    // The $userLogin parameter used to be accepted and ignored, and the query
    // ran with neither a user nor an access level -- the repository's
    // "admin path, no access filter" branch -- so a report returned every
    // user's PRIVATE and CONFIDENTIAL entries to whoever ran it.
    // ---------------------------------------------------------------------

    private function createReport(): Report
    {
        return new Report(
            id: 1,
            owner: 'jdoe',
            name: 'Test Report',
            type: 'daily',
            templates: ['E' => 'Event: ${name}']
        );
    }

    private function createRange(): DateRange
    {
        return new DateRange(
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-02-28')
        );
    }

    public function testReportOnYourOwnCalendarSeesYourOwnEntries(): void
    {
        $actor = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $range = $this->createRange();

        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->with(
                $this->identicalTo($range),
                $this->identicalTo($actor),
                $this->isNull(),
                $this->identicalTo(['jdoe'])
            )
            ->willReturn([]);

        $this->reportService->generateFullReport($this->createReport(), $range, $actor);
    }

    public function testTargetDefaultsToTheActorsOwnCalendar(): void
    {
        $actor = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $range = $this->createRange();

        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->with($range, $actor, null, ['jdoe'])
            ->willReturn([]);

        // Explicitly naming your own login must behave the same as omitting it.
        $this->reportService->generateFullReport($this->createReport(), $range, $actor, 'jdoe');
    }

    public function testNonAdminReportingOnAnotherCalendarSeesOnlyPublicEntries(): void
    {
        $actor = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $range = $this->createRange();

        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->with(
                $this->identicalTo($range),
                $this->isNull(),
                $this->identicalTo('P'),
                $this->identicalTo(['bsmith'])
            )
            ->willReturn([]);

        $this->reportService->generateFullReport($this->createReport(), $range, $actor, 'bsmith');
    }

    public function testAdminReportingOnAnotherCalendarSeesAllOfThatUsersEntries(): void
    {
        $admin = new User('admin', 'Ada', 'Root', 'admin@example.com', true, true);
        $range = $this->createRange();

        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->with(
                $this->identicalTo($range),
                $this->isNull(),
                $this->isNull(),
                $this->identicalTo(['bsmith'])
            )
            ->willReturn([]);

        $this->reportService->generateFullReport($this->createReport(), $range, $admin, 'bsmith');
    }

    /**
     * Whatever the scoping, the query must never run unfiltered across every
     * user's calendar -- that is the branch that leaked private entries.
     */
    public function testReportNeverQueriesEveryCalendarAtOnce(): void
    {
        $admin = new User('admin', 'Ada', 'Root', 'admin@example.com', true, true);
        $range = $this->createRange();

        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->logicalNot($this->isNull())
            )
            ->willReturn([]);

        $this->reportService->generateFullReport($this->createReport(), $range, $admin);
    }

    public function testReportStillRendersEventsThroughTheTemplate(): void
    {
        $actor = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);
        $range = $this->createRange();

        $event = new Event(
            id: new EventId(1),
            uid: 'uid-1',
            name: 'Meeting',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-02-11 10:00:00'),
            duration: 60,
            createdBy: 'jdoe',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $this->eventRepository->method('findByDateRange')->willReturn([$event]);

        $result = $this->reportService->generateFullReport($this->createReport(), $range, $actor);

        $this->assertSame("Event: Meeting\n", $result);
    }

    // ---------------------------------------------------------------------
    // Grant-driven scoping, once a CalendarAccessService is wired
    // ---------------------------------------------------------------------

    private function eventOf(int $id, string $name, EventType $type, AccessLevel $access): Event
    {
        return new Event(
            id: new EventId($id),
            uid: "uid-$id",
            name: $name,
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-02-11 10:00:00'),
            duration: 60,
            createdBy: 'bsmith',
            type: $type,
            access: $access
        );
    }

    /**
     * Builds a ReportService whose access service returns one fixed grant.
     */
    private function serviceGranting(int $viewMask): ReportService
    {
        $accessRepository = $this->createMock(CalendarAccessRepositoryInterface::class);
        $accessRepository->method('find')->willReturnCallback(
            static fn (string $grantee, string $owner): ?CalendarAccess => $grantee === 'jdoe' && $owner === 'bsmith'
                ? new CalendarAccess(
                    $grantee,
                    $owner,
                    new CalendarPermission($viewMask),
                    CalendarPermission::none(),
                    CalendarPermission::none()
                )
                : null
        );

        $eventService = new EventService(
            $this->eventRepository,
            $this->createMock(UserRepositoryInterface::class)
        );

        return new ReportService(
            $this->reportRepository,
            $eventService,
            new CalendarAccessService($accessRepository)
        );
    }

    public function testAGrantFiltersByEntryTypeAndAccessLevel(): void
    {
        $actor = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $this->eventRepository->method('findByDateRange')->willReturn([
            $this->eventOf(1, 'Public event', EventType::EVENT, AccessLevel::PUBLIC),
            $this->eventOf(2, 'Private event', EventType::EVENT, AccessLevel::PRIVATE),
            $this->eventOf(3, 'Public task', EventType::TASK, AccessLevel::PUBLIC),
        ]);

        // EVENT_WT: every access level, but events only.
        $result = $this->serviceGranting(73)->generateFullReport(
            $this->createReport(),
            $this->createRange(),
            $actor,
            'bsmith'
        );

        $this->assertStringContainsString('Public event', $result);
        $this->assertStringContainsString('Private event', $result);
        $this->assertStringNotContainsString('Public task', $result);
    }

    /**
     * The point of the whole exercise: a user who genuinely holds rights to
     * another calendar now sees its private entries, where the conservative
     * fallback would have shown them nothing but public ones.
     */
    public function testAFullGrantExposesPrivateEntriesTheFallbackWouldHide(): void
    {
        $actor = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $this->eventRepository->method('findByDateRange')->willReturn([
            $this->eventOf(1, 'Confidential review', EventType::EVENT, AccessLevel::CONFIDENTIAL),
        ]);

        $result = $this->serviceGranting(CalendarPermission::ALL)->generateFullReport(
            $this->createReport(),
            $this->createRange(),
            $actor,
            'bsmith'
        );

        $this->assertStringContainsString('Confidential review', $result);
    }

    public function testNoGrantYieldsAnEmptyReportWithoutQueryingTheCalendar(): void
    {
        $actor = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        // No grant matches 'carol', so the calendar must not be read at all.
        $this->eventRepository->expects($this->never())->method('findByDateRange');

        $result = $this->serviceGranting(CalendarPermission::ALL)->generateFullReport(
            $this->createReport(),
            $this->createRange(),
            $actor,
            'carol'
        );

        $this->assertSame('', $result);
    }

    public function testAnExplicitDenialAlsoSkipsTheQuery(): void
    {
        $actor = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $this->eventRepository->expects($this->never())->method('findByDateRange');

        $result = $this->serviceGranting(CalendarPermission::NONE)->generateFullReport(
            $this->createReport(),
            $this->createRange(),
            $actor,
            'bsmith'
        );

        $this->assertSame('', $result);
    }

    public function testYourOwnCalendarIsUnaffectedByGrants(): void
    {
        $actor = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->with($this->anything(), $this->identicalTo($actor), $this->isNull(), ['jdoe'])
            ->willReturn([]);

        $this->serviceGranting(CalendarPermission::NONE)->generateFullReport(
            $this->createReport(),
            $this->createRange(),
            $actor
        );
    }
}
