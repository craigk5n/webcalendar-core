<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ImportService;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;

final class ImportServiceTest extends TestCase
{
    /** @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventRepository;
    private ImportService $importService;

    protected function setUp(): void
    {
        $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
        $this->importService = new ImportService(
            $this->eventRepository,
            new EventMapper()
        );
    }

    public function testImportIcalCreatesNewEvents(): void
    {
        $icsContent = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//WebCalendar//NONSGML v1.0//EN
BEGIN:VEVENT
UID:uid-1
SUMMARY:Event 1
DTSTART:20260211T100000Z
DURATION:PT1H
END:VEVENT
END:VCALENDAR
ICS;

        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $this->eventRepository->expects($this->once())
            ->method('findByUid')
            ->with('uid-1')
            ->willReturn(null);

        $this->eventRepository->expects($this->once())
            ->method('create')
            ->with($this->isInstanceOf(Event::class));

        $this->importService->importIcal($icsContent, $user);
    }
    private function existingEvent(int $id, string $uid): Event
    {
        return new Event(
            id: new EventId($id),
            uid: $uid,
            name: 'Old Name',
            description: 'old description',
            location: 'old location',
            start: new \DateTimeImmutable('2026-01-01 09:00:00'),
            duration: 30,
            createdBy: 'jdoe',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
    }

    private const ICS_ONE_EVENT = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//WebCalendar//NONSGML v1.0//EN
BEGIN:VEVENT
UID:uid-1
SUMMARY:New Name
DTSTART:20260211T100000Z
DURATION:PT1H
END:VEVENT
END:VCALENDAR
ICS;

    public function testImportIcalSkipsExistingEventsByDefault(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $this->eventRepository->method('findByUid')
            ->with('uid-1')
            ->willReturn($this->existingEvent(42, 'uid-1'));

        $this->eventRepository->expects($this->never())->method('create');
        $this->eventRepository->expects($this->never())->method('save');

        $result = $this->importService->importIcal(self::ICS_ONE_EVENT, $user);

        $this->assertSame(0, $result->importedCount);
        $this->assertSame(1, $result->skippedCount);
        $this->assertSame(0, $result->updatedCount);
    }

    public function testImportIcalUpdatesExistingEventWhenUpdateRequested(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $this->eventRepository->method('findByUid')
            ->with('uid-1')
            ->willReturn($this->existingEvent(42, 'uid-1'));

        $this->eventRepository->expects($this->never())->method('create');

        // The update must reuse the existing row's id, otherwise save() would
        // insert a duplicate instead of overwriting.
        $saved = null;
        $this->eventRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (Event $event) use (&$saved): bool {
                $saved = $event;
                return true;
            }));

        $result = $this->importService->importIcal(self::ICS_ONE_EVENT, $user, true);

        $this->assertInstanceOf(Event::class, $saved);
        $this->assertSame(42, $saved->id()->value(), 'update must target the existing row id');
        $this->assertSame('New Name', $saved->name(), 'update must carry the incoming values');

        $this->assertSame(1, $result->updatedCount);
        $this->assertSame(0, $result->skippedCount);
        $this->assertSame(0, $result->importedCount);
    }

    public function testImportIcalStillCreatesNewEventsWhenUpdateRequested(): void
    {
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $this->eventRepository->method('findByUid')->willReturn(null);
        $this->eventRepository->expects($this->once())->method('create');

        $result = $this->importService->importIcal(self::ICS_ONE_EVENT, $user, true);

        $this->assertSame(1, $result->importedCount);
        $this->assertSame(0, $result->updatedCount);
    }
}
