<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\ActivityLogEntry;
use WebCalendar\Core\Domain\Repository\ActivityLogRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\ActivityLogType;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * Service for managing the system activity log (audit trail).
 */
final readonly class ActivityLogService
{
    public function __construct(
        private ActivityLogRepositoryInterface $activityLogRepository
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
     * Retrieves log entries for a specific date range and optional user.
     * 
     * @return ActivityLogEntry[]
     */
    public function getLogs(DateRange $range, ?string $login = null): array
    {
        return $this->activityLogRepository->findByDateRange($range, $login);
    }
}
