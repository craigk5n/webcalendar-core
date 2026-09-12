<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\ValueObject\CalendarAccess;

/**
 * Grants of one user's calendar to another (legacy `webcal_access_user`).
 *
 * Implementations store rows verbatim. Resolving which row applies -- the
 * `__default__` fallback chain, owner-is-actor, admin override -- is business
 * logic and belongs in the application layer, not here.
 */
interface CalendarAccessRepositoryInterface
{
    /**
     * Finds the exact grant for this pair, or null when none is stored.
     *
     * Both arguments are matched literally, so passing
     * {@see CalendarAccess::DEFAULT_LOGIN} looks up a wildcard row rather
     * than expanding one.
     */
    public function find(string $grantee, string $owner): ?CalendarAccess;

    /**
     * Every grant held by one user, over any calendar.
     *
     * @return CalendarAccess[]
     */
    public function findByGrantee(string $grantee): array;

    /**
     * Every grant over one user's calendar, held by anyone.
     *
     * @return CalendarAccess[]
     */
    public function findByOwner(string $owner): array;

    /**
     * Stores a grant, replacing any existing row for the same pair.
     */
    public function save(CalendarAccess $access): void;

    /**
     * Removes one grant. Absent rows are not an error.
     */
    public function delete(string $grantee, string $owner): void;

    /**
     * Removes every grant a user holds and every grant over their calendar.
     *
     * Both directions matter when a user is deleted: rows where they are the
     * grantee, and rows where they are the owner.
     */
    public function deleteAllFor(string $login): void;
}
