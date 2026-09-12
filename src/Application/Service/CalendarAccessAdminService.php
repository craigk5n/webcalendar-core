<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Repository\CalendarAccessRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\CalendarAccess;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Administers per-calendar grants (legacy `webcal_access_user`).
 *
 * Read-side resolution lives in {@see CalendarAccessService}; this is the
 * write side, with the authorization legacy's access.php applies.
 *
 * The rule there is easy to miss, because the page expresses it by swapping
 * its two form fields:
 *
 *     // If user is not admin,
 *     // reverse values so they are granting access to their own calendar.
 *     if( ! $is_admin )
 *       list( $puser, $pouser ) = [$pouser, $puser];
 *
 * In other words an admin may write any grant at all, and everyone else may
 * only give other people access to *their own* calendar. Nobody but an admin
 * can hand themselves access to someone else's -- which would otherwise be a
 * one-request privilege escalation.
 */
final class CalendarAccessAdminService
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly CalendarAccessRepositoryInterface $accessRepository,
        ?LoggerInterface $logger = null,
        private readonly ?ActivityLogService $activityLog = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Creates or replaces a grant.
     *
     * @throws AuthorizationException if $actor may not write this grant.
     * @throws \InvalidArgumentException if the grant could never take effect.
     */
    public function grant(User $actor, CalendarAccess $access): void
    {
        $this->assertMayAdminister($actor, $access->owner(), 'grant calendar access');
        $this->assertGrantIsMeaningful($access);

        $this->accessRepository->save($access);

        $this->logger->info('Calendar access granted', [
            'grantee' => $access->grantee(),
            'owner' => $access->owner(),
            'view' => $access->view()->value(),
            'edit' => $access->edit()->value(),
            'approve' => $access->approve()->value(),
            'actor' => $actor->login(),
        ]);

        $this->audit($actor, $access->owner(), sprintf(
            'Granted %s access to %s calendar (view=%d, edit=%d, approve=%d)',
            $access->grantee(),
            $access->owner(),
            $access->view()->value(),
            $access->edit()->value(),
            $access->approve()->value()
        ));
    }

    /**
     * Removes one grant. Removing an absent grant is not an error.
     *
     * @throws AuthorizationException if $actor may not write this grant.
     */
    public function revoke(User $actor, string $grantee, string $owner): void
    {
        $this->assertMayAdminister($actor, $owner, 'revoke calendar access');

        $this->accessRepository->delete($grantee, $owner);

        $this->logger->info('Calendar access revoked', [
            'grantee' => $grantee,
            'owner' => $owner,
            'actor' => $actor->login(),
        ]);

        $this->audit($actor, $owner, sprintf('Revoked %s access to %s calendar', $grantee, $owner));
    }

    /**
     * Every grant over one calendar -- "who can see mine".
     *
     * @throws AuthorizationException if $actor is neither an admin nor the owner.
     * @return CalendarAccess[]
     */
    public function listGrantsOnCalendar(User $actor, string $ownerLogin): array
    {
        $this->assertMayAdminister($actor, $ownerLogin, 'list grants on calendar');

        return $this->accessRepository->findByOwner($ownerLogin);
    }

    /**
     * Every grant one user holds -- "what can they see".
     *
     * Readable by an admin, or by that user about themselves. It is
     * deliberately not readable by the calendars' owners: the answer spans
     * other people's calendars, which are not theirs to enumerate.
     *
     * @throws AuthorizationException if $actor is neither an admin nor $granteeLogin.
     * @return CalendarAccess[]
     */
    public function listGrantsHeldBy(User $actor, string $granteeLogin): array
    {
        if (!$actor->isAdmin() && $actor->login() !== $granteeLogin) {
            throw AuthorizationException::notSelf('list grants held by', $granteeLogin, $actor->login());
        }

        return $this->accessRepository->findByGrantee($granteeLogin);
    }

    /**
     * Drops every grant a user holds and every grant over their calendar.
     *
     * Admin only: it reaches across calendars in both directions, so no owner
     * has standing to run it.
     *
     * @throws AuthorizationException if $actor is not an admin.
     */
    public function revokeAllFor(User $actor, string $login): void
    {
        if (!$actor->isAdmin()) {
            throw AuthorizationException::adminRequired('revoke all calendar access');
        }

        $this->accessRepository->deleteAllFor($login);

        $this->logger->info('All calendar access revoked for user', [
            'login' => $login,
            'actor' => $actor->login(),
        ]);

        $this->audit($actor, $login, sprintf('Revoked all calendar access for %s', $login));
    }

    /**
     * An admin may write any grant; anyone else only grants over their own
     * calendar.
     */
    private function assertMayAdminister(User $actor, string $ownerLogin, string $action): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($actor->login() === $ownerLogin) {
            return;
        }

        throw AuthorizationException::notSelf($action, $ownerLogin, $actor->login());
    }

    /**
     * Rejects grants that could never have an effect, rather than storing a
     * row that silently does nothing.
     */
    private function assertGrantIsMeaningful(CalendarAccess $access): void
    {
        if ($access->grantee() === $access->owner()) {
            throw new \InvalidArgumentException(
                'A user already has full access to their own calendar; a self-grant can never take effect.'
            );
        }

        // Legacy zeroes edit and approve for the anonymous pseudo-user rather
        // than refusing the request. Storing something other than what was
        // asked for is worse in a library, so this says no instead.
        if ($access->grantee() === CalendarAccess::PUBLIC_LOGIN) {
            if (!$access->edit()->isNone() || !$access->approve()->isNone()) {
                throw new \InvalidArgumentException(sprintf(
                    'The "%s" pseudo-user may only be granted view access.',
                    CalendarAccess::PUBLIC_LOGIN
                ));
            }
        }
    }

    /**
     * Permission changes belong in the audit trail (CLAUDE.md), when the
     * caller has wired one up.
     *
     * ActivityLogType has no dedicated permission code and adding one would
     * write rows that older consumers cannot map, so these are recorded as
     * EXTRA with the detail in the text. The entry id is 0: a grant change is
     * not about any one event.
     */
    private function audit(User $actor, string $affectedCalendar, string $text): void
    {
        $this->activityLog?->log(
            entryId: 0,
            login: $actor->login(),
            userCal: $affectedCalendar,
            type: ActivityLogType::EXTRA,
            text: $text
        );
    }
}
