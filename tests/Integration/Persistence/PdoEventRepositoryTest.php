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

    public function testGetParticipantsWithStatus(): void
    {
        $event = new Event(
            id: new EventId(0),
            uid: 'status-test',
            name: 'Status Test',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-03-10 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
        $this->repository->save($event);

        // Creator auto-added with 'A' status
        $id = new EventId(1);
        $result = $this->repository->getParticipantsWithStatus($id);

        $this->assertCount(1, $result);
        $this->assertSame('A', $result['admin']);
    }

    public function testFindByStatus(): void
    {
        $date = new \DateTimeImmutable('2026-03-10 10:00:00');
        $tentative = new Event(new EventId(0), 'u1', 'Pending Event', '', '', $date, 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC, status: 'TENTATIVE');
        $confirmed = new Event(new EventId(0), 'u2', 'Confirmed Event', '', '', $date, 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);

        $this->repository->save($tentative);
        $this->repository->save($confirmed);

        $results = $this->repository->findByStatus('TENTATIVE');

        $this->assertCount(1, $results);
        $this->assertSame('Pending Event', $results[0]->name());
        $this->assertSame('TENTATIVE', $results[0]->status());
    }

    public function testCountByStatus(): void
    {
        $date = new \DateTimeImmutable('2026-03-10 10:00:00');
        $t1 = new Event(new EventId(0), 'u1', 'T1', '', '', $date, 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC, status: 'TENTATIVE');
        $t2 = new Event(new EventId(0), 'u2', 'T2', '', '', $date, 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC, status: 'TENTATIVE');
        $confirmed = new Event(new EventId(0), 'u3', 'C1', '', '', $date, 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);

        $this->repository->save($t1);
        $this->repository->save($t2);
        $this->repository->save($confirmed);

        $this->assertSame(2, $this->repository->countByStatus('TENTATIVE'));
        $this->assertSame(0, $this->repository->countByStatus('CANCELLED'));
    }

    public function testSaveParticipantsWithStatus(): void
    {
        $event = new Event(
            id: new EventId(0),
            uid: 'save-status-test',
            name: 'Save Status Test',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-03-10 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
        $this->repository->save($event);

        $id = new EventId(1);
        $this->repository->saveParticipantsWithStatus($id, [
            'admin' => 'A',
            'jdoe' => 'W',
        ]);

        $result = $this->repository->getParticipantsWithStatus($id);
        $this->assertCount(2, $result);
        $this->assertSame('A', $result['admin']);
        $this->assertSame('W', $result['jdoe']);
    }

    // -- Composite-key and cross-scope isolation regression coverage ------
    //
    // `webcal_entry_user` has PRIMARY KEY (cal_id, cal_login). Any method
    // that takes only one of those columns must be checked for cross-scope
    // leakage. Any destructive method must also be checked for the
    // self-input degenerate case (e.g. delete-then-reinsert on the same
    // row set).
    //
    // See also: tests in PdoCategoryRepositoryTest for the analogous
    // (cat_id, cat_owner) coverage on webcal_categories.

    public function testDeleteRemovesEventAndAllRelatedJunctionRows(): void
    {
        $event = new Event(
            id: new EventId(0),
            uid: 'delete-cascade',
            name: 'To Be Deleted',
            description: '',
            location: '',
            start: new \DateTimeImmutable('2026-04-01 10:00:00'),
            duration: 60,
            createdBy: 'admin',
            type: EventType::EVENT,
            access: AccessLevel::PUBLIC
        );
        $this->repository->save($event);

        $id = new EventId(1);
        $this->repository->saveParticipantsWithStatus($id, [
            'admin' => 'A',
            'jdoe' => 'W',
        ]);

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));

        $stmt = $this->pdo->query(
            'SELECT COUNT(*) FROM webcal_entry_user WHERE cal_id = 1'
        );
        $this->assertNotFalse($stmt);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'participant rows must cascade delete');

        $stmt = $this->pdo->query(
            'SELECT COUNT(*) FROM webcal_entry_repeats WHERE cal_id = 1'
        );
        $this->assertNotFalse($stmt);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'recurrence rows must cascade delete');
    }

    public function testDeleteDoesNotTouchOtherEventsJunctionRows(): void
    {
        // Cross-event isolation: deleting event 1 must not remove
        // webcal_entry_user rows belonging to event 2, even for the same
        // participant login.
        $e1 = new Event(new EventId(0), 'e1', 'Event One', '', '', new \DateTimeImmutable('2026-04-01 10:00:00'), 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);
        $e2 = new Event(new EventId(0), 'e2', 'Event Two', '', '', new \DateTimeImmutable('2026-04-02 10:00:00'), 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);
        $this->repository->save($e1);
        $this->repository->save($e2);

        $this->repository->saveParticipantsWithStatus(new EventId(1), ['admin' => 'A', 'jdoe' => 'W']);
        $this->repository->saveParticipantsWithStatus(new EventId(2), ['admin' => 'A', 'jdoe' => 'A']);

        $this->repository->delete(new EventId(1));

        // Event 2's junction rows must be untouched.
        $survivors = $this->repository->getParticipantsWithStatus(new EventId(2));
        $this->assertCount(2, $survivors);
        $this->assertSame('A', $survivors['admin']);
        $this->assertSame('A', $survivors['jdoe']);
        $this->assertNotNull($this->repository->findById(new EventId(2)));
    }

    public function testUpdateParticipantStatusIsScopedToEventAndUser(): void
    {
        // Cross-event + cross-user isolation. Updating (event=1, login=jdoe)
        // must not touch (event=1, login=admin), (event=2, login=jdoe),
        // or (event=2, login=admin).
        $e1 = new Event(new EventId(0), 'e1', 'Event One', '', '', new \DateTimeImmutable('2026-04-01 10:00:00'), 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);
        $e2 = new Event(new EventId(0), 'e2', 'Event Two', '', '', new \DateTimeImmutable('2026-04-02 10:00:00'), 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);
        $this->repository->save($e1);
        $this->repository->save($e2);

        $this->repository->saveParticipantsWithStatus(new EventId(1), ['admin' => 'A', 'jdoe' => 'W']);
        $this->repository->saveParticipantsWithStatus(new EventId(2), ['admin' => 'W', 'jdoe' => 'W']);

        $this->repository->updateParticipantStatus(new EventId(1), 'jdoe', 'R');

        $e1Status = $this->repository->getParticipantsWithStatus(new EventId(1));
        $e2Status = $this->repository->getParticipantsWithStatus(new EventId(2));

        // Only the targeted row changed.
        $this->assertSame('R', $e1Status['jdoe']);
        $this->assertSame('A', $e1Status['admin'], 'admin on event 1 must be untouched');
        $this->assertSame('W', $e2Status['jdoe'], 'jdoe on event 2 must be untouched');
        $this->assertSame('W', $e2Status['admin'], 'admin on event 2 must be untouched');
    }

    public function testSaveParticipantsDoesNotTouchOtherEventsRows(): void
    {
        // saveParticipants delete-then-insert is scoped to a single
        // cal_id, but the DELETE must only match rows for that cal_id.
        $e1 = new Event(new EventId(0), 'e1', 'Event One', '', '', new \DateTimeImmutable('2026-04-01 10:00:00'), 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);
        $e2 = new Event(new EventId(0), 'e2', 'Event Two', '', '', new \DateTimeImmutable('2026-04-02 10:00:00'), 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);
        $this->repository->save($e1);
        $this->repository->save($e2);

        $this->repository->saveParticipantsWithStatus(new EventId(1), ['admin' => 'A', 'jdoe' => 'W']);
        $this->repository->saveParticipantsWithStatus(new EventId(2), ['admin' => 'A', 'jdoe' => 'A']);

        // Replace event 1's participant list — event 2 must be unaffected.
        $this->repository->saveParticipants(new EventId(1), ['admin']);

        $e1Status = $this->repository->getParticipantsWithStatus(new EventId(1));
        $e2Status = $this->repository->getParticipantsWithStatus(new EventId(2));

        $this->assertCount(1, $e1Status);
        $this->assertArrayHasKey('admin', $e1Status);
        $this->assertCount(2, $e2Status, 'event 2 participants must be untouched');
    }

    public function testSaveParticipantsWithStatusIdempotentReplay(): void
    {
        // Saving the same participant list twice must not leave orphans
        // or duplicate rows. Regression pin for the delete-then-insert
        // pattern used in saveParticipantsWithStatus.
        $event = new Event(new EventId(0), 'replay', 'Replay', '', '', new \DateTimeImmutable('2026-04-01 10:00:00'), 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC);
        $this->repository->save($event);

        $id = new EventId(1);
        $list = ['admin' => 'A', 'jdoe' => 'W'];

        $this->repository->saveParticipantsWithStatus($id, $list);
        $this->repository->saveParticipantsWithStatus($id, $list);

        $result = $this->repository->getParticipantsWithStatus($id);
        $this->assertCount(2, $result);
        $this->assertSame('A', $result['admin']);
        $this->assertSame('W', $result['jdoe']);
    }

    public function testSearchDoesNotReuseNamedPlaceholders(): void
    {
        // Regression: search() used to bind one `:keyword` against a SQL
        // string that referenced :keyword twice (once for cal_name LIKE,
        // once for cal_description LIKE). That works under PDO emulated
        // prepares and SQLite but explodes with SQLSTATE[HY093] on
        // MySQL/PostgreSQL native prepares — i.e. any consumer that
        // hardens its config with PDO::ATTR_EMULATE_PREPARES=false
        // (such as webcalendar-wp's PdoFactory). Same pattern as
        // testReassignEventsDoesNotReuseNamedPlaceholders for categories.
        $strictPdo = new StrictPlaceholderPdo('sqlite::memory:');
        $strictPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->loadSchemaInto($strictPdo);

        $repo = new PdoEventRepository($strictPdo);
        $repo->save(new Event(new EventId(0), 'a', 'Project Meeting', 'discuss', '', new \DateTimeImmutable('2026-02-11 10:00:00'), 30, 'admin', EventType::EVENT, AccessLevel::PUBLIC));
        $repo->save(new Event(new EventId(0), 'b', 'Lunch', 'meeting topic in description', '', new \DateTimeImmutable('2026-02-11 12:00:00'), 60, 'admin', EventType::EVENT, AccessLevel::PUBLIC));

        // Must not throw the strict-placeholder error.
        $hits = $repo->search('meeting');

        // Both events match — name on row 1, description on row 2 — proving
        // both halves of the OR are bound to the same value.
        $this->assertCount(2, $hits);
    }

    /**
     * Loads the SQLite schema into an arbitrary PDO connection so a
     * test can use a PDO subclass (e.g. StrictPlaceholderPdo) without
     * going through RepositoryTestCase::setUp().
     */
    private function loadSchemaInto(\PDO $pdo): void
    {
        $path = __DIR__ . '/../../../src/Infrastructure/Persistence/sqlite-schema.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            $this->fail("Failed to load schema from $path");
        }
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }
}
