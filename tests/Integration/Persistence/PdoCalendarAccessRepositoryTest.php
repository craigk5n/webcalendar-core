<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Integration\Persistence;

use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\CalendarAccess;
use WebCalendar\Core\Domain\ValueObject\CalendarPermission;
use WebCalendar\Core\Domain\ValueObject\EventType;
use WebCalendar\Core\Infrastructure\Persistence\PdoCalendarAccessRepository;
use WebCalendar\Core\Tests\Integration\RepositoryTestCase;

final class PdoCalendarAccessRepositoryTest extends RepositoryTestCase
{
    private PdoCalendarAccessRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PdoCalendarAccessRepository($this->pdo);
    }

    private function grant(string $grantee, string $owner, int $view = 511): CalendarAccess
    {
        return new CalendarAccess(
            $grantee,
            $owner,
            new CalendarPermission($view),
            CalendarPermission::none(),
            CalendarPermission::none()
        );
    }

    /**
     * webcal_access_user's primary key is (cal_login, cal_other_user), so
     * every fixture set here deliberately collides on each half of it:
     * jdoe appears as grantee twice, and bsmith as owner twice.
     */
    private function seedGrid(): void
    {
        $this->repository->save($this->grant('jdoe', 'bsmith', 73));
        $this->repository->save($this->grant('jdoe', 'carol', 7));
        $this->repository->save($this->grant('alice', 'bsmith', 511));
        $this->repository->save($this->grant(CalendarAccess::DEFAULT_LOGIN, 'bsmith', 1));
    }

    /**
     * @param CalendarAccess[] $grants
     * @return string[]
     */
    private function pairsOf(array $grants): array
    {
        $pairs = array_map(
            static fn (CalendarAccess $g): string => $g->grantee() . '->' . $g->owner(),
            $grants
        );
        sort($pairs);
        return $pairs;
    }

    private function countRows(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM webcal_access_user');
        $this->assertNotFalse($stmt);

        return (int)$stmt->fetchColumn();
    }

    public function testSaveAndFindRoundTripsEveryColumn(): void
    {
        $access = new CalendarAccess(
            'jdoe',
            'bsmith',
            new CalendarPermission(73),
            new CalendarPermission(146),
            new CalendarPermission(292),
            canInvite: false,
            canEmail: true,
            seeTimeOnly: true
        );

        $this->repository->save($access);
        $found = $this->repository->find('jdoe', 'bsmith');

        $this->assertNotNull($found);
        $this->assertSame('jdoe', $found->grantee());
        $this->assertSame('bsmith', $found->owner());
        $this->assertSame(73, $found->view()->value());
        $this->assertSame(146, $found->edit()->value());
        $this->assertSame(292, $found->approve()->value());
        $this->assertFalse($found->canInvite());
        $this->assertTrue($found->canEmail());
        $this->assertTrue($found->seeTimeOnly());
    }

    public function testFindReturnsNullWhenNoGrantExists(): void
    {
        $this->assertNull($this->repository->find('nobody', 'bsmith'));
    }

    public function testFindMatchesWildcardLoginsLiterallyRatherThanExpandingThem(): void
    {
        $this->repository->save($this->grant(CalendarAccess::DEFAULT_LOGIN, 'bsmith', 1));

        // The repository stores rows verbatim; expanding __default__ is the
        // service's job, so a specific pair must still miss.
        $this->assertNull($this->repository->find('jdoe', 'bsmith'));
        $this->assertNotNull($this->repository->find(CalendarAccess::DEFAULT_LOGIN, 'bsmith'));
    }

    public function testSaveReplacesAnExistingGrantForTheSamePair(): void
    {
        $this->repository->save($this->grant('jdoe', 'bsmith', 73));
        $this->repository->save($this->grant('jdoe', 'bsmith', 511));

        $found = $this->repository->find('jdoe', 'bsmith');

        $this->assertNotNull($found);
        $this->assertSame(511, $found->view()->value());
        $this->assertSame(1, $this->countRows(), 'save must replace, not accumulate');
    }

    public function testPermissionsSurviveTheRoundTripAsBehaviour(): void
    {
        $this->repository->save($this->grant('jdoe', 'bsmith', 73));
        $found = $this->repository->find('jdoe', 'bsmith');

        $this->assertNotNull($found);
        $this->assertTrue($found->canView(EventType::EVENT, AccessLevel::PRIVATE));
        $this->assertFalse($found->canView(EventType::TASK, AccessLevel::PUBLIC));
    }

    // ---------------------------------------------------------------------
    // Composite-key and cross-scope isolation regression coverage
    //
    // PRIMARY KEY (cal_login, cal_other_user). Every method below takes less
    // than the full key, so each is exercised against fixtures that collide
    // on the half it filters by.
    // ---------------------------------------------------------------------

    public function testFindByGranteeReturnsEveryCalendarThatUserCanReach(): void
    {
        $this->seedGrid();

        // jdoe collides with himself on cal_login across two owners.
        $this->assertSame(
            ['jdoe->bsmith', 'jdoe->carol'],
            $this->pairsOf($this->repository->findByGrantee('jdoe'))
        );
    }

    public function testFindByOwnerReturnsEveryUserWhoCanReachThatCalendar(): void
    {
        $this->seedGrid();

        // bsmith collides with himself on cal_other_user across three grantees.
        $this->assertSame(
            ['__default__->bsmith', 'alice->bsmith', 'jdoe->bsmith'],
            $this->pairsOf($this->repository->findByOwner('bsmith'))
        );
    }

    public function testFindByGranteeAndFindByOwnerDoNotBleedIntoEachOther(): void
    {
        $this->seedGrid();

        // 'carol' is an owner, never a grantee; 'alice' the reverse.
        $this->assertSame([], $this->repository->findByGrantee('carol'));
        $this->assertSame([], $this->repository->findByOwner('alice'));
    }

    public function testDeleteRemovesOnlyTheNamedPair(): void
    {
        $this->seedGrid();

        $this->repository->delete('jdoe', 'bsmith');

        $this->assertNull($this->repository->find('jdoe', 'bsmith'));
        // Same grantee, different owner -- must survive.
        $this->assertNotNull($this->repository->find('jdoe', 'carol'));
        // Same owner, different grantee -- must survive.
        $this->assertNotNull($this->repository->find('alice', 'bsmith'));
        $this->assertSame(3, $this->countRows());
    }

    public function testDeletingAnUnknownPairIsNotAnErrorAndChangesNothing(): void
    {
        $this->seedGrid();

        $this->repository->delete('nobody', 'nowhere');

        $this->assertSame(4, $this->countRows());
    }

    public function testDeleteAllForRemovesGrantsInBothDirections(): void
    {
        $this->seedGrid();

        // bsmith is an owner in three rows; jdoe is a grantee in two.
        $this->repository->deleteAllFor('jdoe');

        $this->assertNull($this->repository->find('jdoe', 'bsmith'));
        $this->assertNull($this->repository->find('jdoe', 'carol'));
        // Rows that merely share a partial key with the deleted user stay.
        $this->assertNotNull($this->repository->find('alice', 'bsmith'));
        $this->assertNotNull($this->repository->find(CalendarAccess::DEFAULT_LOGIN, 'bsmith'));
        $this->assertSame(2, $this->countRows());
    }

    public function testDeleteAllForRemovesRowsWhereTheUserIsTheOwnerToo(): void
    {
        $this->seedGrid();

        $this->repository->deleteAllFor('bsmith');

        $this->assertSame(
            ['jdoe->carol'],
            $this->pairsOf($this->repository->findByGrantee('jdoe'))
        );
        $this->assertSame(1, $this->countRows());
    }

    public function testDeleteAllForAnUnknownUserLeavesEveryRowIntact(): void
    {
        $this->seedGrid();

        $this->repository->deleteAllFor('stranger');

        $this->assertSame(4, $this->countRows());
    }

    /**
     * A user may hold a grant over their own calendar in the data even though
     * the service short-circuits that case; the degenerate row must round
     * trip rather than collide with anything.
     */
    public function testAGrantWhereGranteeAndOwnerAreTheSameUserRoundTrips(): void
    {
        $this->seedGrid();

        $this->repository->save($this->grant('jdoe', 'jdoe', 511));

        $this->assertNotNull($this->repository->find('jdoe', 'jdoe'));
        $this->assertNotNull($this->repository->find('jdoe', 'bsmith'));
        $this->assertSame(5, $this->countRows());
    }

    /**
     * save() is delete-then-insert. Re-saving an identical row must leave the
     * table exactly as it was rather than dropping it.
     */
    public function testResavingTheSameGrantIsIdempotent(): void
    {
        $this->seedGrid();
        $before = $this->countRows();

        $this->repository->save($this->grant('jdoe', 'bsmith', 73));

        $this->assertSame($before, $this->countRows());
        $found = $this->repository->find('jdoe', 'bsmith');
        $this->assertNotNull($found);
        $this->assertSame(73, $found->view()->value());
    }

    public function testAZeroPermissionGrantIsStoredRatherThanTreatedAsAbsent(): void
    {
        $this->repository->save($this->grant('jdoe', 'bsmith', CalendarPermission::NONE));

        $found = $this->repository->find('jdoe', 'bsmith');

        $this->assertNotNull($found, 'an explicit denial is a row, not a missing row');
        $this->assertTrue($found->view()->isNone());
    }

    /**
     * Legacy tolerates junk in these integer columns; a migrated row must not
     * break the whole lookup.
     */
    public function testACorruptPermissionColumnDegradesToNoAccess(): void
    {
        $this->pdo->prepare(
            'INSERT INTO webcal_access_user (cal_login, cal_other_user, cal_can_view,
             cal_can_edit, cal_can_approve, cal_can_invite, cal_can_email, cal_see_time_only)
             VALUES (?, ?, ?, 0, 0, ?, ?, ?)'
        )->execute(['jdoe', 'bsmith', 0, 'Y', 'Y', 'N']);

        $found = $this->repository->find('jdoe', 'bsmith');

        $this->assertNotNull($found);
        $this->assertTrue($found->view()->isNone());
    }
}
