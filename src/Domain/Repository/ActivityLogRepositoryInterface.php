<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\ActivityLogEntry;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * Interface for Activity Log persistence operations.
 */
interface ActivityLogRepositoryInterface
{
    public function save(ActivityLogEntry $entry): void;

    /**
     * @return ActivityLogEntry[]
     */
    public function findByDateRange(DateRange $range, ?string $login = null): array;
}
