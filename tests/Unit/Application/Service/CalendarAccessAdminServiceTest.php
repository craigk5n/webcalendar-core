<?php

declare(strict_types=1);

namespace WebCalendar\Core\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use WebCalendar\Core\Application\Service\CalendarAccessAdminService;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Repository\CalendarAccessRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\CalendarAccess;
use WebCalendar\Core\Domain\ValueObject\CalendarPermission;
use WebCalendar\Core\Application\Service\ActivityLogService;
use WebCalendar\Core\Domain\Entity\ActivityLogEntry;
use WebCalendar\Core\Domain\Repository\ActivityLogRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\DateRange;

final class CalendarAccessAdminServiceTest extends TestCase
{
    /** @var CalendarAccessRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $repository;
    private CalendarAccessAdminService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CalendarAccessRepositoryInterface::class);
        $this->service = new CalendarAccessAdminService($this->repository);
    }

    private function user(string $login, bool $isAdmin = false): User
    {
        return new User($login, 'Test', 'User', "$login@example.com", $isAdmin, true);
    }

    private function grant(string $grantee, string $owner, int $view = 511, int $edit = 0, int $approve = 0): CalendarAccess
    {
        return new CalendarAccess(
            $grantee,
            $owner,
            new CalendarPermission($view),
            new CalendarPermission($edit),
            new CalendarPermission($approve)
        );
    }

    // -- who may write a grant -------------------------------------------

    public function testAdminMayGrantAnyoneAccessToAnyCalendar(): void
    {
        $this->repository->expects($this->once())->method('save');

        $this->service->grant($this->user('admin', true), $this->grant('jdoe', 'bsmith'));
    }

    public function testAUserMayGrantOthersAccessToTheirOwnCalendar(): void
    {
        $this->repository->expects($this->once())->method('save');

        // bsmith is the owner here -- he is handing jdoe access to his own.
        $this->service->grant($this->user('bsmith'), $this->grant('jdoe', 'bsmith'));
    }

    /**
     * The escalation this rule exists to stop: writing yourself a grant over
     * somebody else's calendar.
     */
    public function testAUserMayNotGrantThemselvesAccessToAnotherCalendar(): void
    {
        $this->repository->expects($this->never())->method('save');

        $this->expectException(AuthorizationException::class);
        $this->service->grant($this->user('jdoe'), $this->grant('jdoe', 'bsmith'));
    }

    public function testAUserMayNotGrantAccessBetweenTwoOtherPeople(): void
    {
        $this->repository->expects($this->never())->method('save');

        $this->expectException(AuthorizationException::class);
        $this->service->grant($this->user('carol'), $this->grant('jdoe', 'bsmith'));
    }

    /**
     * "Anyone may see every calendar" is a site-wide statement, so it needs
     * an admin -- it falls out of the owner rule, but is worth pinning.
     */
    public function testANonAdminMayNotWriteAWildcardOwnerGrant(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->grant(
            $this->user('jdoe'),
            $this->grant('jdoe', CalendarAccess::DEFAULT_LOGIN)
        );
    }

    public function testANonAdminMayOpenTheirOwnCalendarToEveryone(): void
    {
        $this->repository->expects($this->once())->method('save');

        // __default__ as the grantee, bsmith as owner: "anyone may see mine".
        $this->service->grant(
            $this->user('bsmith'),
            $this->grant(CalendarAccess::DEFAULT_LOGIN, 'bsmith')
        );
    }

    // -- grants that could never take effect ------------------------------

    public function testRejectsASelfGrant(): void
    {
        $this->repository->expects($this->never())->method('save');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->grant($this->user('jdoe'), $this->grant('jdoe', 'jdoe'));
    }

    public function testRejectsEditAccessForTheAnonymousPseudoUser(): void
    {
        $this->repository->expects($this->never())->method('save');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->grant(
            $this->user('admin', true),
            $this->grant(CalendarAccess::PUBLIC_LOGIN, 'bsmith', view: 511, edit: 73)
        );
    }

    public function testRejectsApproveAccessForTheAnonymousPseudoUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->grant(
            $this->user('admin', true),
            $this->grant(CalendarAccess::PUBLIC_LOGIN, 'bsmith', view: 511, approve: 73)
        );
    }

    public function testAllowsViewOnlyAccessForTheAnonymousPseudoUser(): void
    {
        $this->repository->expects($this->once())->method('save');

        $this->service->grant(
            $this->user('admin', true),
            $this->grant(CalendarAccess::PUBLIC_LOGIN, 'bsmith', view: 7)
        );
    }

    // -- revoking ---------------------------------------------------------

    public function testOwnerMayRevokeAGrantOverTheirOwnCalendar(): void
    {
        $this->repository->expects($this->once())->method('delete')->with('jdoe', 'bsmith');

        $this->service->revoke($this->user('bsmith'), 'jdoe', 'bsmith');
    }

    public function testAUserMayNotRevokeGrantsOverSomeoneElsesCalendar(): void
    {
        $this->repository->expects($this->never())->method('delete');

        $this->expectException(AuthorizationException::class);
        $this->service->revoke($this->user('jdoe'), 'carol', 'bsmith');
    }

    public function testRevokingAnAbsentGrantIsNotAnError(): void
    {
        $this->repository->expects($this->once())->method('delete');

        $this->service->revoke($this->user('admin', true), 'nobody', 'nowhere');
    }

    public function testRevokeAllForRequiresAnAdmin(): void
    {
        $this->repository->expects($this->never())->method('deleteAllFor');

        $this->expectException(AuthorizationException::class);
        // Even over his own login: it reaches other people's calendars too.
        $this->service->revokeAllFor($this->user('bsmith'), 'bsmith');
    }

    public function testAdminMayRevokeAllForAUser(): void
    {
        $this->repository->expects($this->once())->method('deleteAllFor')->with('bsmith');

        $this->service->revokeAllFor($this->user('admin', true), 'bsmith');
    }

    // -- listing ----------------------------------------------------------

    public function testOwnerMayListWhoCanSeeTheirCalendar(): void
    {
        $this->repository->expects($this->once())->method('findByOwner')->with('bsmith')->willReturn([]);

        $this->service->listGrantsOnCalendar($this->user('bsmith'), 'bsmith');
    }

    public function testAUserMayNotListWhoCanSeeSomeoneElsesCalendar(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->listGrantsOnCalendar($this->user('jdoe'), 'bsmith');
    }

    public function testAUserMayListTheGrantsTheyHold(): void
    {
        $this->repository->expects($this->once())->method('findByGrantee')->with('jdoe')->willReturn([]);

        $this->service->listGrantsHeldBy($this->user('jdoe'), 'jdoe');
    }

    /**
     * The answer spans other people's calendars, so it is not the owners'
     * to read -- only the holder's, or an admin's.
     */
    public function testAnOwnerMayNotListTheGrantsAnotherUserHolds(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->listGrantsHeldBy($this->user('bsmith'), 'jdoe');
    }

    public function testAdminMayListTheGrantsAnyUserHolds(): void
    {
        $this->repository->expects($this->once())->method('findByGrantee')->with('jdoe')->willReturn([]);

        $this->service->listGrantsHeldBy($this->user('admin', true), 'jdoe');
    }

    // -- audit trail ------------------------------------------------------
    //
    // CLAUDE.md requires permission changes to reach webcal_entry_log.

    private function auditSpy(): RecordingActivityLogRepository
    {
        return new RecordingActivityLogRepository();
    }

    private function serviceAuditingTo(RecordingActivityLogRepository $spy): CalendarAccessAdminService
    {
        return new CalendarAccessAdminService(
            $this->repository,
            null,
            new ActivityLogService($spy)
        );
    }

    public function testGrantingIsRecordedInTheAuditTrail(): void
    {
        $spy = $this->auditSpy();

        $this->serviceAuditingTo($spy)
            ->grant($this->user('admin', true), $this->grant('jdoe', 'bsmith', 73));

        $this->assertCount(1, $spy->entries());
        $this->assertSame('admin', $spy->entries()[0]->login(), 'the actor is who did it');
        $this->assertSame('bsmith', $spy->entries()[0]->userCal(), 'the affected calendar');
        $this->assertStringContainsString('jdoe', $spy->entries()[0]->text());
        $this->assertStringContainsString('view=73', $spy->entries()[0]->text());
    }

    public function testRevokingIsRecordedInTheAuditTrail(): void
    {
        $spy = $this->auditSpy();

        $this->serviceAuditingTo($spy)->revoke($this->user('bsmith'), 'jdoe', 'bsmith');

        $this->assertCount(1, $spy->entries());
        $this->assertStringContainsString('Revoked', $spy->entries()[0]->text());
        $this->assertStringContainsString('jdoe', $spy->entries()[0]->text());
    }

    public function testRevokeAllIsRecordedInTheAuditTrail(): void
    {
        $spy = $this->auditSpy();

        $this->serviceAuditingTo($spy)->revokeAllFor($this->user('admin', true), 'bsmith');

        $this->assertCount(1, $spy->entries());
        $this->assertStringContainsString('Revoked all', $spy->entries()[0]->text());
    }

    /**
     * A refused write must leave no trace of having happened.
     */
    public function testARefusedGrantIsNotRecorded(): void
    {
        $spy = $this->auditSpy();

        try {
            $this->serviceAuditingTo($spy)
                ->grant($this->user('jdoe'), $this->grant('jdoe', 'bsmith'));
            $this->fail('expected AuthorizationException');
        } catch (AuthorizationException $e) {
            // expected
        }

        $this->assertSame([], $spy->entries());
    }

    public function testTheAuditTrailIsOptional(): void
    {
        // The default service has none wired; granting must still work.
        $this->repository->expects($this->once())->method('save');

        $this->service->grant($this->user('admin', true), $this->grant('jdoe', 'bsmith'));
    }
}
