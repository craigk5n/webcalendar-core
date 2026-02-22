<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\Recurrence;
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;
use WebCalendar\Core\Domain\ValueObject\ExDate;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

final class PdoEventRepositoryTest extends RepositoryTestCase
{
    private PdoEventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PdoEventRepository($this->pdo);
    }

    public function testSaveAndFindById(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = new Event(
            id: new EventId(0),
            uid: 'uid-123',
            name: 'Test Event',
            description: 'Desc',
            location: 'Location',
            start: $start,
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $this->repository->save($event);

        // Max ID should be 1
        $foundEvent = $this->repository->findById(new EventId(1));

        $this->assertNotNull($foundEvent);
        $this->assertSame('uid-123', $foundEvent->uid());
        $this->assertSame('Test Event', $foundEvent->name());
        $this->assertSame(60, $foundEvent->duration());
        $this->assertSame('2026-02-11 10:00:00', $foundEvent->start()->format('Y-m-d H:i:s'));
    }

    public function testFindByUid(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event = new Event(
            id: new EventId(0),
            uid: 'unique-uid',
            name: 'Unique Event',
            description: '',
            location: '',
            start: $start,
            duration: 30,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $this->repository->save($event);

        $foundEvent = $this->repository->findByUid('unique-uid');

        $this->assertNotNull($foundEvent);
        $this->assertSame('Unique Event', $foundEvent->name());
    }

    public function testFindByDateRange(): void
    {
        $date1 = new \DateTimeImmutable('2026-02-10 10:00:00');
        $date2 = new \DateTimeImmutable('2026-02-15 10:00:00');
        
        $event1 = new Event(new EventId(0), 'u1', 'E1', '', '', $date1, 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);
        $event2 = new Event(new EventId(0), 'u2', 'E2', '', '', $date2, 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);

        $this->repository->save($event1);
        $this->repository->save($event2);

        $range = new DateRange(
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-02-12')
        );

        $events = $this->repository->findByDateRange($range);
        
        $this->assertCount(1, $events);
        $this->assertSame('E1', $events[0]->name());
    }

    public function testSearch(): void
    {
        $date = new \DateTimeImmutable('2026-02-11 10:00:00');
        $event1 = new Event(new EventId(0), 'u1', 'Meeting with Bob', 'Discussion', '', $date, 30, 'admin', EventType::EVENT, AccessLevel::PUBLIC);
        $event2 = new Event(new EventId(0), 'u2', 'Lunch', 'Eat food', '', $date, 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);

        $this->repository->save($event1);
        $this->repository->save($event2);

        $results = $this->repository->search('Meeting');
        $this->assertCount(1, $results);
        $this->assertSame('Meeting with Bob', $results->all()[0]->name());

        $results = $this->repository->search('food');
        $this->assertCount(1, $results);
        $this->assertSame('Lunch', $results->all()[0]->name());
    }

    public function testSaveCreatesParticipantRowForCreator(): void
    {
        $event = new Event(
            id: new EventId(0),
            uid: 'participant-test',
            name: 'Participant Test',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-03-10 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $this->repository->save($event);

        // Verify the participant row was created in webcal_entry_user
        $stmt = $this->pdo->prepare(
            'SELECT cal_login, cal_status FROM webcal_entry_user WHERE cal_id = :id'
        );
        $stmt->execute(['id' => 1]); // First event gets ID 1
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertIsArray($row);
        $this->assertSame('admin', $row['cal_login']);
        $this->assertSame('A', $row['cal_status']);
    }

    public function testUpdateDoesNotDuplicateParticipantRow(): void
    {
        $event = new Event(
            id: new EventId(0),
            uid: 'no-dup-test',
            name: 'Original',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-03-10 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $this->repository->save($event);

        // Update the same event (now has ID 1)
        $updated = new Event(
            id: new EventId(1),
            uid: 'no-dup-test',
            name: 'Updated',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-03-10 11:00:00'),
            duration: 90,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );

        $this->repository->save($updated);

        // Should still be exactly 1 participant row, not 2
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM webcal_entry_user WHERE cal_id = :id'
        );
        $stmt->execute(['id' => 1]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testRecurrencePersistence(): void
    {
        $start = new \DateTimeImmutable('2026-02-11 10:00:00');
        $recurrence = new Recurrence(
            rule: new RecurrenceRule('FREQ=WEEKLY;BYDAY=MO,WE'),
            exDate: new ExDate([new \DateTimeImmutable('2026-02-16')])
        );

        $event = new Event(
            id: new EventId(0),
            uid: 'rec-1',
            name: 'Recurring Event',
            description: '',
            location: '',
            start: $start,
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC,
            recurrence: $recurrence
        );

        $this->repository->save($event);

        $foundEvent = $this->repository->findByUid('rec-1');
        $this->assertNotNull($foundEvent);
        $this->assertTrue($foundEvent->recurrence()->isRepeating());
        $this->assertSame('FREQ=WEEKLY;BYDAY=MO,WE', $foundEvent->recurrence()->rule()?->toString());
        $this->assertCount(1, $foundEvent->recurrence()->exDate()->dates());
        $this->assertSame('2026-02-16', $foundEvent->recurrence()->exDate()->dates()[0]->format('Y-m-d'));
    }
}
