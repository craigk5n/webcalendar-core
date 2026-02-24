<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Exception\EventNotFoundException;
use WebCalendar\Core\Domain\Repository\EventRepositoryInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\ParticipantStatus;

final class EventServiceTest extends TestCase
{
    /** @var EventRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $eventRepository;
    /** @var UserRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $userRepository;
    private EventService $eventService;

    protected function setUp(): void
    {
        $this->eventRepository = $this->createMock(EventRepositoryInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->eventService = new EventService($this->eventRepository, $this->userRepository);
    }

    private function createUser(string $login, bool $isAdmin = false): User
    {
        return new User($login, 'First', 'Last', $login . '@example.com', $isAdmin, true);
    }

    private function createEvent(int $id, string $createdBy): Event
    {
        return new Event(
            id: new EventId($id),
            uid: 'uid-' . $id,
            name: 'Test Event',
            description: '',
            location: '',
            start: new \DateTimeImmutable(),
            duration: 60,
            createdBy: $createdBy,
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
    }

    public function testGetEventsInDateRange(): void
    {
        $range = new DateRange(
            new \DateTimeImmutable('2026-02-11 00:00:00'),
            new \DateTimeImmutable('2026-02-11 23:59:59')
        );

        $user = $this->createUser('jdoe');
        $events = [];

        $this->eventRepository->expects($this->once())
            ->method('findByDateRange')
            ->with($range, $user)
            ->willReturn($events);

        $result = $this->eventService->getEventsInDateRange($range, $user);
        $this->assertInstanceOf(\WebCalendar\Core\Domain\ValueObject\EventCollection::class, $result);
        $this->assertSame($events, $result->all());
    }

    public function testCreateEvent(): void
    {
        $actor = $this->createUser('admin');
        $event = $this->createEvent(1, 'admin');

        $this->eventRepository->expects($this->once())
            ->method('save')
            ->with($event);

        $this->eventService->createEvent($event, $actor);
    }

    public function testDeleteEventThrowsExceptionIfNotFound(): void
    {
        $id = new EventId(999);
        $actor = $this->createUser('admin');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->with($id)
            ->willReturn(null);

        $this->expectException(EventNotFoundException::class);
        $this->eventService->deleteEvent($id, $actor);
    }

    public function testUpdateEventThrowsExceptionIfNotFound(): void
    {
        $id = new EventId(999);
        $actor = $this->createUser('admin');
        $event = $this->createEvent(999, 'admin');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->with($id)
            ->willReturn(null);

        $this->expectException(EventNotFoundException::class);
        $this->eventService->updateEvent($event, $actor);
    }

    public function testOwnerCanDeleteOwnEvent(): void
    {
        $event = $this->createEvent(1, 'jdoe');
        $actor = $this->createUser('jdoe');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->eventRepository->expects($this->once())
            ->method('delete');

        $this->eventService->deleteEvent(new EventId(1), $actor);
    }

    public function testOwnerCanUpdateOwnEvent(): void
    {
        $event = $this->createEvent(1, 'jdoe');
        $actor = $this->createUser('jdoe');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->eventRepository->expects($this->once())
            ->method('save');

        $this->eventService->updateEvent($event, $actor);
    }

    public function testAdminCanDeleteAnyEvent(): void
    {
        $event = $this->createEvent(1, 'otheruser');
        $admin = $this->createUser('admin', isAdmin: true);

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->eventRepository->expects($this->once())
            ->method('delete');

        $this->eventService->deleteEvent(new EventId(1), $admin);
    }

    public function testAdminCanUpdateAnyEvent(): void
    {
        $event = $this->createEvent(1, 'otheruser');
        $admin = $this->createUser('admin', isAdmin: true);

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->eventRepository->expects($this->once())
            ->method('save');

        $this->eventService->updateEvent($event, $admin);
    }

    public function testNonOwnerCannotDeleteOthersEvent(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('attacker');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->eventRepository->expects($this->never())
            ->method('delete');

        $this->expectException(AuthorizationException::class);
        $this->eventService->deleteEvent(new EventId(1), $actor);
    }

    public function testNonOwnerCannotUpdateOthersEvent(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('attacker');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->eventRepository->expects($this->never())
            ->method('save');

        $this->expectException(AuthorizationException::class);
        $this->eventService->updateEvent($event, $actor);
    }

    // --- approve/reject tests (now with event-exists + participant validation) ---

    public function testUserCanApproveForThemselves(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('jdoe');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->with(new EventId(123))
            ->willReturn($event);

        $this->eventRepository->expects($this->once())
            ->method('getParticipantsWithStatus')
            ->with(new EventId(123))
            ->willReturn(['jdoe' => 'W', 'owner' => 'A']);

        $this->eventRepository->expects($this->once())
            ->method('updateParticipantStatus')
            ->with(new EventId(123), 'jdoe', 'A');

        $this->eventService->approveEvent(new EventId(123), 'jdoe', $actor);
    }

    public function testUserCanRejectForThemselves(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('jdoe');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->with(new EventId(123))
            ->willReturn($event);

        $this->eventRepository->expects($this->once())
            ->method('getParticipantsWithStatus')
            ->with(new EventId(123))
            ->willReturn(['jdoe' => 'W', 'owner' => 'A']);

        $this->eventRepository->expects($this->once())
            ->method('updateParticipantStatus')
            ->with(new EventId(123), 'jdoe', 'R');

        $this->eventService->rejectEvent(new EventId(123), 'jdoe', $actor);
    }

    public function testAdminCanApproveForAnyone(): void
    {
        $event = $this->createEvent(1, 'owner');
        $admin = $this->createUser('admin', isAdmin: true);

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->with(new EventId(123))
            ->willReturn($event);

        $this->eventRepository->expects($this->once())
            ->method('getParticipantsWithStatus')
            ->with(new EventId(123))
            ->willReturn(['jdoe' => 'W', 'owner' => 'A']);

        $this->eventRepository->expects($this->once())
            ->method('updateParticipantStatus')
            ->with(new EventId(123), 'jdoe', 'A');

        $this->eventService->approveEvent(new EventId(123), 'jdoe', $admin);
    }

    public function testUserCannotApproveForOthers(): void
    {
        $actor = $this->createUser('attacker');

        $this->eventRepository->expects($this->never())
            ->method('updateParticipantStatus');

        $this->expectException(AuthorizationException::class);
        $this->eventService->approveEvent(new EventId(123), 'victim', $actor);
    }

    public function testUserCannotRejectForOthers(): void
    {
        $actor = $this->createUser('attacker');

        $this->eventRepository->expects($this->never())
            ->method('updateParticipantStatus');

        $this->expectException(AuthorizationException::class);
        $this->eventService->rejectEvent(new EventId(123), 'victim', $actor);
    }

    // --- setParticipantStatus tests ---

    public function testSetParticipantStatusThrowsIfEventNotFound(): void
    {
        $actor = $this->createUser('jdoe');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->with(new EventId(1))
            ->willReturn(null);

        $this->expectException(EventNotFoundException::class);
        $this->eventService->setParticipantStatus(
            new EventId(1),
            'jdoe',
            ParticipantStatus::ACCEPTED,
            $actor
        );
    }

    public function testSetParticipantStatusThrowsIfNotParticipant(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('jdoe');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->eventRepository->expects($this->once())
            ->method('getParticipantsWithStatus')
            ->willReturn(['owner' => 'A']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a participant');
        $this->eventService->setParticipantStatus(
            new EventId(1),
            'jdoe',
            ParticipantStatus::ACCEPTED,
            $actor
        );
    }

    // --- setParticipants tests ---

    public function testSetParticipantsValidatesLogins(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('owner');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->userRepository->expects($this->exactly(2))
            ->method('findByLogin')
            ->willReturnMap([
                ['owner', $this->createUser('owner')],
                ['ghost', null],
            ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ghost');
        $this->eventService->setParticipants(new EventId(1), ['owner', 'ghost'], $actor);
    }

    public function testSetParticipantsDeduplicates(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('owner');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->userRepository->method('findByLogin')
            ->willReturnCallback(fn(string $l) => $this->createUser($l));

        $this->eventRepository->expects($this->once())
            ->method('getParticipantsWithStatus')
            ->willReturn(['owner' => 'A']);

        $this->eventRepository->expects($this->once())
            ->method('saveParticipantsWithStatus')
            ->with(
                new EventId(1),
                $this->callback(function (array $p): bool {
                    // 'jdoe' appears only once despite being passed twice
                    return count($p) === 2
                        && isset($p['owner'], $p['jdoe'])
                        && $p['jdoe'] === 'W';
                })
            );

        $this->eventService->setParticipants(
            new EventId(1),
            ['jdoe', 'jdoe', 'owner'],
            $actor
        );
    }

    public function testSetParticipantsPreservesExistingStatuses(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('owner');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->userRepository->method('findByLogin')
            ->willReturnCallback(fn(string $l) => $this->createUser($l));

        $this->eventRepository->expects($this->once())
            ->method('getParticipantsWithStatus')
            ->willReturn(['owner' => 'A', 'jdoe' => 'R']);

        $this->eventRepository->expects($this->once())
            ->method('saveParticipantsWithStatus')
            ->with(
                new EventId(1),
                $this->callback(function (array $p): bool {
                    return $p['owner'] === 'A'
                        && $p['jdoe'] === 'R'
                        && $p['newguy'] === 'W';
                })
            );

        $this->eventService->setParticipants(
            new EventId(1),
            ['owner', 'jdoe', 'newguy'],
            $actor
        );
    }

    public function testSetParticipantsEnsuresCreatorStays(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('owner');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->userRepository->method('findByLogin')
            ->willReturnCallback(fn(string $l) => $this->createUser($l));

        $this->eventRepository->expects($this->once())
            ->method('getParticipantsWithStatus')
            ->willReturn(['owner' => 'A']);

        $this->eventRepository->expects($this->once())
            ->method('saveParticipantsWithStatus')
            ->with(
                new EventId(1),
                $this->callback(function (array $p): bool {
                    // Owner auto-added even though only 'jdoe' was in the list
                    return count($p) === 2
                        && isset($p['owner'], $p['jdoe']);
                })
            );

        // Only pass 'jdoe', creator 'owner' should be auto-added
        $this->eventService->setParticipants(new EventId(1), ['jdoe'], $actor);
    }

    public function testSetParticipantsAuthorizationCheck(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('attacker');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->eventRepository->expects($this->never())
            ->method('saveParticipantsWithStatus');

        $this->expectException(AuthorizationException::class);
        $this->eventService->setParticipants(new EventId(1), ['attacker'], $actor);
    }

    // --- addParticipant tests ---

    public function testAddParticipantValidatesLogin(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('owner');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->userRepository->expects($this->once())
            ->method('findByLogin')
            ->with('ghost')
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->eventService->addParticipant(new EventId(1), 'ghost', $actor);
    }

    public function testAddParticipantNoOpIfAlreadyPresent(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('owner');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->userRepository->method('findByLogin')
            ->willReturn($this->createUser('jdoe'));

        $this->eventRepository->expects($this->once())
            ->method('getParticipantsWithStatus')
            ->willReturn(['owner' => 'A', 'jdoe' => 'W']);

        $this->eventRepository->expects($this->never())
            ->method('saveParticipantsWithStatus');

        $this->eventService->addParticipant(new EventId(1), 'jdoe', $actor);
    }

    public function testAddParticipantAssignsWaitingStatus(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('owner');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->userRepository->method('findByLogin')
            ->willReturn($this->createUser('newguy'));

        $this->eventRepository->expects($this->once())
            ->method('getParticipantsWithStatus')
            ->willReturn(['owner' => 'A']);

        $this->eventRepository->expects($this->once())
            ->method('saveParticipantsWithStatus')
            ->with(
                new EventId(1),
                $this->callback(function (array $p): bool {
                    return $p['newguy'] === 'W' && $p['owner'] === 'A';
                })
            );

        $this->eventService->addParticipant(new EventId(1), 'newguy', $actor);
    }

    // --- removeParticipant tests ---

    public function testRemoveParticipantBlocksRemovingCreator(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('owner');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot remove event creator');
        $this->eventService->removeParticipant(new EventId(1), 'owner', $actor);
    }

    public function testRemoveParticipantNoOpIfNotPresent(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('owner');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->eventRepository->expects($this->once())
            ->method('getParticipantsWithStatus')
            ->willReturn(['owner' => 'A']);

        $this->eventRepository->expects($this->never())
            ->method('saveParticipantsWithStatus');

        $this->eventService->removeParticipant(new EventId(1), 'nobody', $actor);
    }

    public function testRemoveParticipantRemovesSuccessfully(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('owner');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->eventRepository->expects($this->once())
            ->method('getParticipantsWithStatus')
            ->willReturn(['owner' => 'A', 'jdoe' => 'W']);

        $this->eventRepository->expects($this->once())
            ->method('saveParticipantsWithStatus')
            ->with(
                new EventId(1),
                $this->callback(function (array $p): bool {
                    return count($p) === 1 && isset($p['owner']) && !isset($p['jdoe']);
                })
            );

        $this->eventService->removeParticipant(new EventId(1), 'jdoe', $actor);
    }

    public function testApproveEventThrowsIfEventNotFound(): void
    {
        $actor = $this->createUser('jdoe');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        $this->expectException(EventNotFoundException::class);
        $this->eventService->approveEvent(new EventId(999), 'jdoe', $actor);
    }

    public function testApproveEventThrowsIfNotParticipant(): void
    {
        $event = $this->createEvent(1, 'owner');
        $actor = $this->createUser('jdoe');

        $this->eventRepository->expects($this->once())
            ->method('findById')
            ->willReturn($event);

        $this->eventRepository->expects($this->once())
            ->method('getParticipantsWithStatus')
            ->willReturn(['owner' => 'A']);

        $this->expectException(\InvalidArgumentException::class);
        $this->eventService->approveEvent(new EventId(1), 'jdoe', $actor);
    }
}
