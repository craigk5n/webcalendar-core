<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Journal;
use WebCalendar\Core\Domain\ValueObject\EventId;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * Interface for Journal persistence operations.
 */
interface JournalRepositoryInterface
{
    public function findById(EventId $id): ?Journal;

    /**
     * @return Journal[]
     */
    public function findByDateRange(DateRange $range, ?string $user = null): array;

    public function save(Journal $journal): void;

    public function delete(EventId $id): void;
}
