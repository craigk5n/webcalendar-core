<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Domain\Entity\Organizer;
use WebCalendar\Core\Domain\ValueObject\OrganizerId;
use WebCalendar\Core\Infrastructure\Persistence\PdoOrganizerRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

final class PdoOrganizerRepositoryTest extends RepositoryTestCase
{
    private PdoOrganizerRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PdoOrganizerRepository($this->pdo);
    }

    /** Insert a bare event row referencing an organizer. */
    private function insertEvent(int $calId, ?int $organizerId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO webcal_entry (cal_id, cal_create_by, cal_date, cal_duration, cal_name, cal_organizer_id)
             VALUES (:id, :by, 20260910, 60, :name, :organizer)'
        );
        $stmt->execute(['id' => $calId, 'by' => 'admin', 'name' => "Event $calId", 'organizer' => $organizerId]);
    }

    private function organizerRefOf(int $calId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT cal_organizer_id FROM webcal_entry WHERE cal_id = :id');
        $stmt->execute(['id' => $calId]);
        $value = $stmt->fetchColumn();
        return $value === null || $value === false ? null : (int) $value;
    }

    public function testSaveInsertsUnsavedOrganizerAndReturnsItWithItsClaimedId(): void
    {
        $organizer = new Organizer(
            id: new OrganizerId(0),
            name: 'Alice Organizer',
            email: 'alice@example.com',
            phone: '+1-555-0101',
            url: 'https://alice.example.com',
        );

        $saved = $this->repository->save($organizer);

        $this->assertGreaterThan(0, $saved->id()->value());
        $found = $this->repository->findById($saved->id());
        $this->assertNotNull($found);
        $this->assertSame('Alice Organizer', $found->name());
        $this->assertSame('alice@example.com', $found->email());
        $this->assertSame('+1-555-0101', $found->phone());
        $this->assertSame('https://alice.example.com', $found->url());
    }

    public function testSaveUpdatesAnExistingOrganizerInPlace(): void
    {
        $saved = $this->repository->save(new Organizer(id: new OrganizerId(0), name: 'Old', email: 'old@example.com'));

        $this->repository->save(new Organizer(id: $saved->id(), name: 'New'));

        $found = $this->repository->findById($saved->id());
        $this->assertNotNull($found);
        $this->assertSame('New', $found->name());
        $this->assertNull($found->email(), 'update overwrites the whole row');
        $this->assertCount(1, $this->repository->findAll());
    }

    public function testFindByNameIsCaseInsensitive(): void
    {
        $this->repository->save(new Organizer(id: new OrganizerId(0), name: 'Bob NoContact'));

        $this->assertNotNull($this->repository->findByName('bob nocontact'));
        $this->assertNull($this->repository->findByName('Carol'));
    }

    public function testFindAllIsOrderedByName(): void
    {
        $this->repository->save(new Organizer(id: new OrganizerId(0), name: 'Zed'));
        $this->repository->save(new Organizer(id: new OrganizerId(0), name: 'Alice'));

        $names = array_map(static fn (Organizer $o): string => $o->name(), $this->repository->findAll());

        $this->assertSame(['Alice', 'Zed'], $names);
    }

    // ---- Composite-key and cross-scope isolation regression coverage ----

    public function testDeleteNullsEventReferencesButOnlyItsOwn(): void
    {
        $doomed = $this->repository->save(new Organizer(id: new OrganizerId(0), name: 'Doomed'));
        $other  = $this->repository->save(new Organizer(id: new OrganizerId(0), name: 'Survivor'));
        $this->insertEvent(100, $doomed->id()->value());
        $this->insertEvent(101, $other->id()->value());

        $this->repository->delete($doomed->id());

        $this->assertNull($this->repository->findById($doomed->id()));
        $this->assertNull($this->organizerRefOf(100), 'reference nulled, event kept');
        $this->assertSame($other->id()->value(), $this->organizerRefOf(101), 'other organizers untouched');
    }

    public function testDeleteOfUnknownIdIsANoOp(): void
    {
        $kept = $this->repository->save(new Organizer(id: new OrganizerId(0), name: 'Kept'));

        $this->repository->delete(new OrganizerId(9999));

        $this->assertNotNull($this->repository->findById($kept->id()));
    }

    public function testReassignEventsMovesOnlyTheFromOrganizersEvents(): void
    {
        $from  = $this->repository->save(new Organizer(id: new OrganizerId(0), name: 'From'));
        $to    = $this->repository->save(new Organizer(id: new OrganizerId(0), name: 'To'));
        $other = $this->repository->save(new Organizer(id: new OrganizerId(0), name: 'Other'));
        $this->insertEvent(100, $from->id()->value());
        $this->insertEvent(101, $other->id()->value());

        $this->repository->reassignEvents($from->id(), $to->id());

        $this->assertSame($to->id()->value(), $this->organizerRefOf(100));
        $this->assertSame($other->id()->value(), $this->organizerRefOf(101), 'cross-scope rows untouched');
        $this->assertSame(1, $this->repository->countEvents($to->id()));
        $this->assertSame(0, $this->repository->countEvents($from->id()));
    }

    public function testSelfReassignIsAGuardedNoOp(): void
    {
        $organizer = $this->repository->save(new Organizer(id: new OrganizerId(0), name: 'Same'));
        $this->insertEvent(100, $organizer->id()->value());

        $this->repository->reassignEvents($organizer->id(), $organizer->id());

        $this->assertSame($organizer->id()->value(), $this->organizerRefOf(100));
        $this->assertSame(1, $this->repository->countEvents($organizer->id()));
    }
}
