<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Task;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventScope;

/**
 * Interface for Task persistence operations.
 */
interface TaskRepositoryInterface
{
    public function findById(EventId $id): ?Task;

    /**
     * @return Task[]
     */
    public function findByDateRange(DateRange $range, EventScope $scope): array;

    public function save(Task $task): void;

    public function delete(EventId $id): void;
}
