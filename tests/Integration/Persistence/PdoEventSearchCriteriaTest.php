<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Entity\Venue;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Domain\ValueObject\OrganizerId;
use WebCalendar\Core\Domain\ValueObject\SearchCriteria;
use WebCalendar\Core\Domain\ValueObject\VenueId;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;
use WebCalendar\Core\Infrastructure\Persistence\PdoVenueRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

/**
 * Epic 25 — the shared Filter Bar query surface. Fixture set:
 *
 *  - Yoga in the Park  (Sep 10, venue 1 @ Harrisonburg, category 4)
 *  - Board Meeting     (Sep 15, organizer 3, category 5)
 *  - Distant Concert   (Sep 20, own geo @ Philadelphia)
 *  - Yoga homework     (Sep 12, a task)
 */
final class PdoEventSearchCriteriaTest extends RepositoryTestCase
{
    private PdoEventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PdoEventRepository($this->pdo);
        $this->pdo->exec('DELETE FROM webcal_entry_categories');

        $venue = (new PdoVenueRepository($this->pdo))->save(new Venue(
            id: new VenueId(0),
            name: 'Harrisonburg Park',
            latitude: 38.4496,
            longitude: -78.8689,
        ));

        $this->insert('Yoga in the Park', 'stretching outside', '2026-09-10 10:00:00', EventType::EVENT, 'u-yoga', $venue->id(), null);
        $this->insert('Board Meeting', 'quarterly review', '2026-09-15 09:00:00', EventType::EVENT, 'u-board', null, new OrganizerId(3));
        $this->insert('Distant Concert', 'loud music', '2026-09-20 20:00:00', EventType::EVENT, 'u-concert', null, null);
        $this->insert('Yoga homework', 'practice at home', '2026-09-12 08:00:00', EventType::TASK, 'u-task', null, null);

        // The concert carries its own coordinates (Philadelphia).
        $this->pdo->exec("UPDATE webcal_entry SET cal_geo_lat = 39.9526, cal_geo_lon = -75.1652 WHERE cal_uid = 'u-concert'");

        // Categories: yoga -> 4, board -> 5.
        $yogaId = $this->idOf('u-yoga');
        $boardId = $this->idOf('u-board');
        $this->pdo->exec("INSERT INTO webcal_entry_categories (cal_id, cat_id, cat_order, cat_owner) VALUES ($yogaId, 4, 0, '')");
        $this->pdo->exec("INSERT INTO webcal_entry_categories (cal_id, cat_id, cat_order, cat_owner) VALUES ($boardId, 5, 0, '')");
    }

    private function insert(
        string $name,
        string $description,
        string $start,
        EventType $type,
        string $uid,
        ?VenueId $venueId,
        ?OrganizerId $organizerId,
    ): void {
        $this->repository->save(new Event(
            id: new EventId(0),
            uid: $uid,
            name: $name,
            description: $description,
            location: '',
            start: new \DateTimeImmutable($start),
            duration: 60,
            createdBy: 'admin',
            type: $type,
            access: AccessLevel::PUBLIC,
            venueId: $venueId,
            organizerId: $organizerId,
        ));
    }

    private function idOf(string $uid): int
    {
        $stmt = $this->pdo->prepare('SELECT cal_id FROM webcal_entry WHERE cal_uid = :uid');
        $stmt->execute(['uid' => $uid]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function names(SearchCriteria $criteria): array
    {
        $names = [];
        foreach ($this->repository->searchByCriteria($criteria) as $event) {
            $names[] = $event->name();
        }
        return $names;
    }

    public function testUnfilteredCriteriaReturnEverythingInDateOrder(): void
    {
        $this->assertSame(
            ['Yoga in the Park', 'Yoga homework', 'Board Meeting', 'Distant Concert'],
            $this->names(new SearchCriteria())
        );
    }

    public function testKeywordMatchesNameOrDescription(): void
    {
        $this->assertSame(['Yoga in the Park', 'Yoga homework'], $this->names(new SearchCriteria(keyword: 'yoga')));
        $this->assertSame(['Board Meeting'], $this->names(new SearchCriteria(keyword: 'quarterly')));
    }

    public function testCategoryFilterIsAnyOf(): void
    {
        $this->assertSame(
            ['Yoga in the Park', 'Board Meeting'],
            $this->names(new SearchCriteria(categoryIds: [4, 5]))
        );
        $this->assertSame(['Board Meeting'], $this->names(new SearchCriteria(categoryIds: [5])));
    }

    public function testVenueAndOrganizerFilters(): void
    {
        $stmt = $this->pdo->query('SELECT venue_id FROM webcal_venue');
        $this->assertNotFalse($stmt);
        $venueId = (int) $stmt->fetchColumn();

        $this->assertSame(['Yoga in the Park'], $this->names(new SearchCriteria(venueIds: [$venueId])));
        $this->assertSame(['Board Meeting'], $this->names(new SearchCriteria(organizerIds: [3])));
    }

    public function testTypeFilter(): void
    {
        $this->assertSame(['Yoga homework'], $this->names(new SearchCriteria(types: [EventType::TASK])));
    }

    public function testDateRangeFilter(): void
    {
        $range = new DateRange(new \DateTimeImmutable('2026-09-11'), new \DateTimeImmutable('2026-09-16'));

        $this->assertSame(['Yoga homework', 'Board Meeting'], $this->names(new SearchCriteria(range: $range)));
    }

    public function testDistanceFilterUsesVenueCoordinates(): void
    {
        $near = new SearchCriteria(nearLatitude: 38.45, nearLongitude: -78.87, radiusKm: 10.0);

        $this->assertSame(['Yoga in the Park'], $this->names($near));
    }

    public function testDistanceFilterUsesTheEventsOwnCoordinates(): void
    {
        $philly = new SearchCriteria(nearLatitude: 39.95, nearLongitude: -75.16, radiusKm: 10.0);

        $this->assertSame(['Distant Concert'], $this->names($philly));
    }

    public function testPagination(): void
    {
        $this->assertSame(
            ['Yoga in the Park', 'Yoga homework'],
            $this->names(new SearchCriteria(limit: 2))
        );
        $this->assertSame(
            ['Board Meeting', 'Distant Concert'],
            $this->names(new SearchCriteria(limit: 2, offset: 2))
        );
    }

    public function testCombinedFiltersIntersect(): void
    {
        $range = new DateRange(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));
        $criteria = new SearchCriteria(keyword: 'yoga', range: $range, types: [EventType::EVENT]);

        $this->assertSame(['Yoga in the Park'], $this->names($criteria));
    }
}
