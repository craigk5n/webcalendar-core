<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\ActivityLogEntry;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Repository\ActivityLogRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * Service for managing the system activity log (audit trail).
 */
final class ActivityLogService
{
    public function __construct(
        private readonly ActivityLogRepositoryInterface $activityLogRepository
    ) {
    }

    /**
     * Records a new activity in the log.
     */
    public function log(
        int $entryId,
        string $login,
        ?string $userCal,
        ActivityLogType $type,
        string $text = ''
    ): void {
        $entry = new ActivityLogEntry(
            id: 0, // 0 for new entry
            entryId: $entryId,
            login: $login,
            userCal: $userCal,
            type: $type,
            date: new \DateTimeImmutable(),
            text: $text
        );

        $this->activityLogRepository->save($entry);
    }

    /**
     * Retrieves log entries for a date range.
     *
     * The audit trail records who did what, so reading another user's
     * entries is itself a disclosure. This is not an EventScope question --
     * `webcal_entry_log` has no `cal_access` column, and the rows are not
     * calendar entries -- it is an authorization one:
     *
     * - an admin may read any user's entries, or all of them ($login null);
     * - anyone else may read only their own, and passing null means theirs
     *   rather than everybody's.
     *
     * @param User $actor The user reading the log.
     * @param string|null $login Whose entries to read; null means "all" for
     *                           an admin and "mine" for everyone else.
     * @throws AuthorizationException if a non-admin asks for another user's.
     * @return ActivityLogEntry[]
     */
    public function getLogs(DateRange $range, User $actor, ?string $login = null): array
    {
        if (!$actor->isAdmin()) {
            if ($login !== null && $login !== $actor->login()) {
                throw AuthorizationException::notSelf('read activity log', $login, $actor->login());
            }

            // A bare request means "my activity", never the whole site's.
            $login = $actor->login();
        }

        return $this->activityLogRepository->findByDateRange($range, $login);
    }
}
