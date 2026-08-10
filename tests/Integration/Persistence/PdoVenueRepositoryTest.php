<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Domain\Entity\Venue;
use WebCalendar\Core\Domain\ValueObject\VenueId;
use WebCalendar\Core\Infrastructure\Persistence\PdoVenueRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

final class PdoVenueRepositoryTest extends RepositoryTestCase
{
    private PdoVenueRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PdoVenueRepository($this->pdo);
    }

    /** Insert a bare event row referencing a venue. */
    private function insertEvent(int $calId, ?int $venueId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO webcal_entry (cal_id, cal_create_by, cal_date, cal_duration, cal_name, cal_venue_id)
             VALUES (:id, :by, 20260910, 60, :name, :venue)'
        );
        $stmt->execute(['id' => $calId, 'by' => 'admin', 'name' => "Event $calId", 'venue' => $venueId]);
    }

    private function venueRefOf(int $calId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT cal_venue_id FROM webcal_entry WHERE cal_id = :id');
        $stmt->execute(['id' => $calId]);
        $value = $stmt->fetchColumn();
        return $value === null || $value === false ? null : (int) $value;
    }

    public function testSaveInsertsUnsavedVenueAndReturnsItWithItsClaimedId(): void
    {
        $venue = new Venue(
            id: new VenueId(0),
            name: 'Community Hall',
            address: '1 Main St',
            city: 'Harrisonburg',
            state: 'VA',
            zip: '22801',
            country: 'USA',
            latitude: 38.4496,
            longitude: -78.8689,
            url: 'https://hall.example.com',
            phone: '+1-555-0100',
        );

        $saved = $this->repository->save($venue);

        $this->assertGreaterThan(0, $saved->id()->value());
        $found = $this->repository->findById($saved->id());
        $this->assertNotNull($found);
        $this->assertSame('Community Hall', $found->name());
        $this->assertSame('1 Main St', $found->address());
        $this->assertSame('USA', $found->country());
        $this->assertEqualsWithDelta(38.4496, $found->latitude(), 0.0000001);
        $this->assertEqualsWithDelta(-78.8689, $found->longitude(), 0.0000001);
        $this->assertSame('+1-555-0100', $found->phone());
    }

    public function testSaveUpdatesAnExistingVenueInPlace(): void
    {
        $saved = $this->repository->save(new Venue(id: new VenueId(0), name: 'Old Name', city: 'Dayton'));

        $this->repository->save(new Venue(id: $saved->id(), name: 'New Name'));

        $found = $this->repository->findById($saved->id());
        $this->assertNotNull($found);
        $this->assertSame('New Name', $found->name());
        $this->assertNull($found->city(), 'update overwrites the whole row');
        $this->assertCount(1, $this->repository->findAll());
    }

    public function testFindByNameIsCaseInsensitive(): void
    {
        $this->repository->save(new Venue(id: new VenueId(0), name: 'Back Room'));

        $this->assertNotNull($this->repository->findByName('back room'));
        $this->assertNotNull($this->repository->findByName('BACK ROOM'));
        $this->assertNull($this->repository->findByName('Front Room'));
    }

    public function testFindAllIsOrderedByName(): void
    {
        $this->repository->save(new Venue(id: new VenueId(0), name: 'Zoo Pavilion'));
        $this->repository->save(new Venue(id: new VenueId(0), name: 'Art Center'));

        $names = array_map(static fn (Venue $v): string => $v->name(), $this->repository->findAll());

        $this->assertSame(['Art Center', 'Zoo Pavilion'], $names);
    }

    // ---- Composite-key and cross-scope isolation regression coverage ----

    public function testDeleteNullsEventReferencesButOnlyItsOwn(): void
    {
        $doomed = $this->repository->save(new Venue(id: new VenueId(0), name: 'Doomed'));
        $other  = $this->repository->save(new Venue(id: new VenueId(0), name: 'Survivor'));
        $this->insertEvent(100, $doomed->id()->value());
        $this->insertEvent(101, $other->id()->value());
        $this->insertEvent(102, null);

        $this->repository->delete($doomed->id());

        $this->assertNull($this->repository->findById($doomed->id()));
        $this->assertNull($this->venueRefOf(100), 'reference nulled, event kept');
        $this->assertSame($other->id()->value(), $this->venueRefOf(101), 'other venues untouched');
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM webcal_entry WHERE cal_id = 100');
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'delete never cascades to events');
    }

    public function testDeleteOfUnknownIdIsANoOp(): void
    {
        $kept = $this->repository->save(new Venue(id: new VenueId(0), name: 'Kept'));

        $this->repository->delete(new VenueId(9999));

        $this->assertNotNull($this->repository->findById($kept->id()));
    }

    public function testReassignEventsMovesOnlyTheFromVenuesEvents(): void
    {
        $from  = $this->repository->save(new Venue(id: new VenueId(0), name: 'From'));
        $to    = $this->repository->save(new Venue(id: new VenueId(0), name: 'To'));
        $other = $this->repository->save(new Venue(id: new VenueId(0), name: 'Other'));
        $this->insertEvent(100, $from->id()->value());
        $this->insertEvent(101, $other->id()->value());

        $this->repository->reassignEvents($from->id(), $to->id());

        $this->assertSame($to->id()->value(), $this->venueRefOf(100));
        $this->assertSame($other->id()->value(), $this->venueRefOf(101), 'cross-scope rows untouched');
        $this->assertSame(1, $this->repository->countEvents($to->id()));
        $this->assertSame(0, $this->repository->countEvents($from->id()));
    }

    public function testSelfReassignIsAGuardedNoOp(): void
    {
        $venue = $this->repository->save(new Venue(id: new VenueId(0), name: 'Same'));
        $this->insertEvent(100, $venue->id()->value());

        $this->repository->reassignEvents($venue->id(), $venue->id());

        $this->assertSame($venue->id()->value(), $this->venueRefOf(100));
        $this->assertSame(1, $this->repository->countEvents($venue->id()));
    }

    public function testCountEventsIsZeroForUnreferencedVenue(): void
    {
        $venue = $this->repository->save(new Venue(id: new VenueId(0), name: 'Lonely'));

        $this->assertSame(0, $this->repository->countEvents($venue->id()));
    }
}
