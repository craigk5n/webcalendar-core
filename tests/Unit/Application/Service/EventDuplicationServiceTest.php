<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Application\Service\EventDuplicationService;
use WebCalendar\Core\Domain\Entity\Category;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\Reminder;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\CategoryRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\ReminderRepositoryInterface;
use WebCalendar\Core\Domain\Repository\SiteExtraRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;

final class EventDuplicationServiceTest extends TestCase
{
    /** @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private EventRepositoryInterface $events;

    /** @var CategoryRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private CategoryRepositoryInterface $categories;

    /** @var ReminderRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private ReminderRepositoryInterface $reminders;

    /** @var SiteExtraRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private SiteExtraRepositoryInterface $siteExtras;

    /** @var \WebCalendar\Core\Domain\Repository\ActivityLogRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private \WebCalendar\Core\Domain\Repository\ActivityLogRepositoryInterface $activityLogRepository;

    private EventDuplicationService $service;

    protected function setUp(): void
    {
        $this->events = $this->createMock(EventRepositoryInterface::class);
        $this->categories = $this->createMock(CategoryRepositoryInterface::class);
        $this->reminders = $this->createMock(ReminderRepositoryInterface::class);
        $this->siteExtras = $this->createMock(SiteExtraRepositoryInterface::class);
        // ActivityLogService is final; assert through its repository.
        $this->activityLogRepository = $this->createMock(
            \WebCalendar\Core\Domain\Repository\ActivityLogRepositoryInterface::class
        );

        $this->service = new EventDuplicationService(
            $this->events,
            $this->categories,
            $this->reminders,
            $this->siteExtras,
            new ActivityLogService($this->activityLogRepository),
        );
    }

    private function actor(): User
    {
        return new User('copier', 'Co', 'Pier', 'copier@example.com', false, true);
    }

    private function original(): Event
    {
        return new Event(
            id: new EventId(10),
            uid: 'orig-uid',
            name: 'Weekly Class',
            description: 'Bring a mat.',
            location: 'Community Hall',
            start: new \DateTimeImmutable('2026-09-01 18:00:00'),
            duration: 60,
            createdBy: 'teacher',
            type: EventType::REPEATING_EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: new Recurrence(new RecurrenceRule('FREQ=WEEKLY;COUNT=8')),
            image: 'https://example.com/class.png',
            venueId: new \WebCalendar\Core\Domain\ValueObject\VenueId(5),
            organizerId: new \WebCalendar\Core\Domain\ValueObject\OrganizerId(7),
            conferenceUrl: 'https://meet.example.com/class',
            conferenceLabel: 'Google Meet',
        );
    }

    /**
     * Every descriptive field the original carries must reach the copy.
     *
     * Written field-by-field on purpose: the duplicate used to be built with
     * a hand-rolled constructor call that simply never mentioned the
     * conference fields, so they were silently dropped. Anything added to
     * Event from here on is carried by with() and covered by the
     * Event::with() reflection guard.
     */
    public function testDuplicateCarriesEveryDescriptiveField(): void
    {
        $this->events->method('findById')->with(new EventId(10))->willReturn($this->original());
        $capturedSave = null;
        $this->wireSaveThenFind($capturedSave);

        $this->service->duplicate(new EventId(10), 'copy-uid', $this->actor());

        $this->assertNotNull($capturedSave);
        $original = $this->original();
        $this->assertSame($original->description(), $capturedSave->description());
        $this->assertSame($original->location(), $capturedSave->location());
        $this->assertEquals($original->start(), $capturedSave->start());
        $this->assertSame($original->duration(), $capturedSave->duration());
        $this->assertSame($original->type(), $capturedSave->type());
        $this->assertSame($original->access(), $capturedSave->access());
        $this->assertEquals($original->recurrence(), $capturedSave->recurrence());
        $this->assertSame($original->status(), $capturedSave->status());
        $this->assertSame($original->isAllDay(), $capturedSave->isAllDay());
        $this->assertSame($original->image(), $capturedSave->image());
        $this->assertEquals($original->venueId(), $capturedSave->venueId());
        $this->assertEquals($original->organizerId(), $capturedSave->organizerId());
        // Regression: a duplicated online/hybrid event kept its physical
        // location but lost the link people actually join through.
        $this->assertSame($original->conferenceUrl(), $capturedSave->conferenceUrl());
        $this->assertSame($original->conferenceLabel(), $capturedSave->conferenceLabel());
    }

    /**
     * Wire findByUid/save so 'copy-uid' resolves only AFTER save ran —
     * the pre-save collision check must see it as free.
     *
     * Callers assert against $capturedSave (what the service tried to
     * persist), so the stored entity is not returned.
     */
    private function wireSaveThenFind(?Event &$capturedSave): void
    {
        $saved = $this->original()->withId(new EventId(99));
        $stored = null;
        $this->events->method('save')->willReturnCallback(
            function (Event $e) use (&$capturedSave, &$stored, $saved): void {
                $capturedSave = $e;
                $stored = $saved;
            }
        );
        $this->events->method('findByUid')->willReturnCallback(
            static function (string $uid) use (&$stored): ?Event {
                return $uid === 'copy-uid' ? $stored : null;
            }
        );
    }

    public function testDuplicateCopiesTheWholeSeriesUnderANewIdentity(): void
    {
        $this->events->method('findById')->with(new EventId(10))->willReturn($this->original());
        $capturedSave = null;
        $this->wireSaveThenFind($capturedSave);

        $copy = $this->service->duplicate(new EventId(10), 'copy-uid', $this->actor());

        $this->assertNotNull($capturedSave);
        $this->assertSame(0, $capturedSave->id()->value(), 'saved as a new row');
        $this->assertSame('copy-uid', $capturedSave->uid());
        $this->assertSame('Weekly Class', $capturedSave->name(), 'title handling is the caller\'s job');
        $this->assertSame('copier', $capturedSave->createdBy(), 'the duplicator owns the copy');
        $this->assertSame('FREQ=WEEKLY;COUNT=8', $capturedSave->recurrence()->rule()?->toString());
        $this->assertSame(5, $capturedSave->venueId()?->value());
        $this->assertSame(99, $copy->id()->value());
    }

    public function testDuplicateCopiesSidecarsOntoTheNewEvent(): void
    {
        $this->events->method('findById')->willReturn($this->original());
        $capturedSave = null;
        $this->wireSaveThenFind($capturedSave);

        $this->events->method('getParticipantsWithStatus')->with(new EventId(10))
            ->willReturn(['teacher' => 'A', 'student' => 'W']);
        $this->events->expects($this->once())->method('saveParticipantsWithStatus')
            ->with(new EventId(99), ['teacher' => 'A', 'student' => 'W']);

        $this->categories->method('getForEvent')->willReturn([
            new Category(4, null, 'Classes', '#00f'),
        ]);
        $this->categories->expects($this->once())->method('assignToEvent')
            ->with(new EventId(99), 'copier', [4]);

        $this->reminders->method('findByEventId')->with(10)
            ->willReturn(new Reminder(eventId: 10, offset: 30));
        $this->reminders->expects($this->once())->method('save')
            ->with($this->callback(
                static fn (Reminder $r): bool => $r->eventId() === 99 && $r->offset() === 30
            ));

        $this->siteExtras->method('getForEvent')->with(10)->willReturn([['cal_name' => 'x']]);
        $this->siteExtras->expects($this->once())->method('saveForEvent')
            ->with(99, [['cal_name' => 'x']]);

        $this->activityLogRepository->expects($this->once())->method('save')
            ->with($this->callback(
                static fn (\WebCalendar\Core\Domain\Entity\ActivityLogEntry $entry): bool =>
                    $entry->entryId() === 99
                    && $entry->login() === 'copier'
                    && $entry->type() === ActivityLogType::CREATE
                    && str_contains($entry->text(), 'orig-uid')
            ));

        $this->service->duplicate(new EventId(10), 'copy-uid', $this->actor());
    }

    public function testDuplicateWithNewNameOverridesTheTitle(): void
    {
        $this->events->method('findById')->willReturn($this->original());
        $captured = null;
        $this->wireSaveThenFind($captured);

        $this->service->duplicate(new EventId(10), 'copy-uid', $this->actor(), 'Copy of Weekly Class');

        $this->assertNotNull($captured);
        $this->assertSame('Copy of Weekly Class', $captured->name());
    }

    public function testDuplicateOfAMissingEventThrows(): void
    {
        $this->events->method('findById')->willReturn(null);

        $this->expectException(\DomainException::class);
        $this->service->duplicate(new EventId(404), 'copy-uid', $this->actor());
    }

    public function testDuplicateOntoAnExistingUidIsRejectedBeforeWriting(): void
    {
        $this->events->method('findById')->willReturn($this->original());
        $this->events->method('findByUid')->with('orig-uid')->willReturn($this->original());
        $this->events->expects($this->never())->method('save');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->duplicate(new EventId(10), 'orig-uid', $this->actor());
    }
}
