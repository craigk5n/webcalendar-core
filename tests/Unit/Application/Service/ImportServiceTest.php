<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\ImportService;
use WebCalendar\Core\Domain\Repository\CategoryRepositoryInterface;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Infrastructure\ICal\EventMapper;
use WebCalendar\Core\Domain\Entity\Category;
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

    // ---- Category import ----------------------------------------------------

    /**
     * @var CategoryRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $categoryRepository;

    /** @var list<array{id: int, login: string, ids: list<int>}> */
    private array $assignCalls = [];

    /** @var list<string> */
    private array $lookedUpNames = [];

    /**
     * Builds an ImportService wired to a category-repository mock. findByName
     * resolves any name to a stable id (hash of the name) so distinct names get
     * distinct ids, and every assignToEvent call is recorded in $assignCalls.
     */
    private function serviceWithCategories(): ImportService
    {
        $this->categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $this->assignCalls = [];
        $this->lookedUpNames = [];

        $this->categoryRepository->method('findByName')
            ->willReturnCallback(function (string $name, ?string $owner = null): Category {
                $this->lookedUpNames[] = $name;
                return new Category($this->catId($name), $owner, $name, null);
            });

        $this->categoryRepository->method('assignToEvent')
            ->willReturnCallback(function (EventId $id, string $login, array $ids): void {
                /** @var list<int> $ids */
                $this->assignCalls[] = ['id' => $id->value(), 'login' => $login, 'ids' => $ids];
            });

        return new ImportService(
            $this->eventRepository,
            new EventMapper(),
            $this->categoryRepository
        );
    }

    private function catId(string $name): int
    {
        return (int) sprintf('%u', crc32($name)) % 100000 + 1;
    }

    private static function icsWithCategories(string $categoriesLine): string
    {
        return "BEGIN:VCALENDAR\n"
            . "VERSION:2.0\n"
            . "PRODID:-//WebCalendar//NONSGML v1.0//EN\n"
            . "BEGIN:VEVENT\n"
            . "UID:uid-1\n"
            . "SUMMARY:Event 1\n"
            . "DTSTART:20260211T100000Z\n"
            . "DURATION:PT1H\n"
            . "CATEGORIES:{$categoriesLine}\n"
            . "END:VEVENT\n"
            . "END:VCALENDAR\n";
    }

    public function testImportAssignsAllCategoriesInASingleCall(): void
    {
        // Bug C: assignToEvent replaces the whole set, so calling it per-name
        // kept only the last category. Multiple categories must survive.
        $service = $this->serviceWithCategories();
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $persisted = $this->existingEvent(7, 'uid-1');
        $this->eventRepository->method('findByUid')
            ->willReturnOnConsecutiveCalls(null, $persisted);

        $service->importIcal(self::icsWithCategories('Work,Personal'), $user);

        $this->assertCount(1, $this->assignCalls, 'categories must be assigned in one call');
        $this->assertSame(
            [$this->catId('Work'), $this->catId('Personal')],
            $this->assignCalls[0]['ids']
        );
    }

    public function testImportUnescapesCategoryNamesAndKeepsInternalSpaces(): void
    {
        // Bug A: an escaped comma is a literal comma inside one name, not a
        // separator; internal spaces are part of the name. Requires the
        // TextListValue CATEGORIES parsing added in php-icalendar-core 1.2.0.
        $service = $this->serviceWithCategories();
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $persisted = $this->existingEvent(7, 'uid-1');
        $this->eventRepository->method('findByUid')
            ->willReturnOnConsecutiveCalls(null, $persisted);

        // Single-quoted: '\\,' is the two bytes \ and , — real ICS escaping.
        $ics = self::icsWithCategories('Food\\,Drink,Team Meeting');

        $service->importIcal($ics, $user);

        $this->assertSame(['Food,Drink', 'Team Meeting'], $this->lookedUpNames);
        $this->assertCount(1, $this->assignCalls);
        $this->assertSame(
            [$this->catId('Food,Drink'), $this->catId('Team Meeting')],
            $this->assignCalls[0]['ids']
        );
    }

    public function testImportAssignsCategoriesToPersistedEventIdNotZero(): void
    {
        // Bug D: create() is void and Event is immutable, so the mapped event
        // still has EventId(0). Categories must attach to the real row id.
        $service = $this->serviceWithCategories();
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $persisted = $this->existingEvent(99, 'uid-1');
        $this->eventRepository->method('findByUid')
            ->willReturnOnConsecutiveCalls(null, $persisted);
        $this->eventRepository->expects($this->once())->method('create');

        $service->importIcal(self::icsWithCategories('Work'), $user);

        $this->assertCount(1, $this->assignCalls);
        $this->assertSame(99, $this->assignCalls[0]['id'], 'must assign to the persisted row id');
    }

    public function testUpdateReSyncsCategories(): void
    {
        // Bug B: on the update path categories were skipped entirely, so an
        // upstream CATEGORIES change never propagated after first sync.
        $service = $this->serviceWithCategories();
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $this->eventRepository->method('findByUid')
            ->willReturn($this->existingEvent(42, 'uid-1'));
        $this->eventRepository->expects($this->once())->method('save');

        $service->importIcal(self::icsWithCategories('Work,Personal'), $user, true);

        $this->assertCount(1, $this->assignCalls, 'update must re-sync categories');
        $this->assertSame(42, $this->assignCalls[0]['id']);
        $this->assertSame(
            [$this->catId('Work'), $this->catId('Personal')],
            $this->assignCalls[0]['ids']
        );
    }

    public function testUpdateWithoutFlagDoesNotTouchCategories(): void
    {
        $service = $this->serviceWithCategories();
        $user = new User('jdoe', 'John', 'Doe', 'john@example.com', false, true);

        $this->eventRepository->method('findByUid')
            ->willReturn($this->existingEvent(42, 'uid-1'));

        $service->importIcal(self::icsWithCategories('Work'), $user, false);

        $this->assertSame([], $this->assignCalls, 'skip path must not modify categories');
    }
}
