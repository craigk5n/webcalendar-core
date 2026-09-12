<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\CalendarAccessRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\CalendarAccess;
use WebCalendar\Core\Domain\ValueObject\EventType;

/**
 * Resolves what one user may do with another user's calendar.
 *
 * The repository stores grants verbatim; this service decides which of them
 * applies. Legacy's access_user_calendar() consults four keys in order, and
 * that order is preserved here because installations depend on it:
 *
 *   1. grantee -> owner          the specific grant
 *   2. grantee -> __default__    "what this user gets on any calendar"
 *   3. __default__ -> owner      "what anyone gets on this calendar"
 *   4. __default__ -> __default__  the site-wide default
 *
 * The first row found wins outright; permissions are not merged across the
 * chain. Ahead of all four, two short-circuits apply: a user always has full
 * access to their own calendar, and an admin has full access to anyone's.
 *
 * (Legacy gates the admin case on its ADMIN_OVERRIDE_UAC setting. Core has no
 * equivalent switch and PermissionService already treats admin as an
 * unconditional bypass per PRD 9.6, so this follows the sibling service.)
 */
final class CalendarAccessService
{
    public function __construct(
        private readonly CalendarAccessRepositoryInterface $accessRepository
    ) {
    }

    /**
     * The grant that governs $actor's use of $ownerLogin's calendar.
     *
     * Never returns null -- an unmatched pair yields a grant conveying
     * nothing, so callers can ask it questions without null checks.
     */
    public function effectiveAccess(User $actor, string $ownerLogin): CalendarAccess
    {
        if ($actor->isAdmin()) {
            return CalendarAccess::full($actor->login(), $ownerLogin);
        }

        return $this->resolve($actor->login(), $ownerLogin);
    }

    /**
     * As {@see effectiveAccess()}, for a caller that has a login but no User
     * -- an anonymous visitor, for whom legacy uses the `__public__` pseudo
     * user. Admin short-circuiting is not available on this path.
     */
    public function accessFor(string $granteeLogin, string $ownerLogin): CalendarAccess
    {
        return $this->resolve($granteeLogin, $ownerLogin);
    }

    /**
     * Whether $actor may see an entry of this type and access level on
     * $ownerLogin's calendar.
     */
    public function canView(
        User $actor,
        string $ownerLogin,
        EventType $type,
        AccessLevel $access
    ): bool {
        return $this->effectiveAccess($actor, $ownerLogin)->canView($type, $access);
    }

    public function canEdit(
        User $actor,
        string $ownerLogin,
        EventType $type,
        AccessLevel $access
    ): bool {
        return $this->effectiveAccess($actor, $ownerLogin)->canEdit($type, $access);
    }

    public function canApprove(
        User $actor,
        string $ownerLogin,
        EventType $type,
        AccessLevel $access
    ): bool {
        return $this->effectiveAccess($actor, $ownerLogin)->canApprove($type, $access);
    }

    /**
     * Walks the four-key chain, most specific first.
     */
    private function resolve(string $granteeLogin, string $ownerLogin): CalendarAccess
    {
        if ($granteeLogin === $ownerLogin) {
            return CalendarAccess::full($granteeLogin, $ownerLogin);
        }

        $default = CalendarAccess::DEFAULT_LOGIN;

        $candidates = [
            [$granteeLogin, $ownerLogin],
            [$granteeLogin, $default],
            [$default, $ownerLogin],
            [$default, $default],
        ];

        foreach ($candidates as [$grantee, $owner]) {
            $grant = $this->accessRepository->find($grantee, $owner);

            if ($grant !== null) {
                return $grant;
            }
        }

        return CalendarAccess::none($granteeLogin, $ownerLogin);
    }
}
