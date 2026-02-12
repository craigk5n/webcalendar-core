<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Report;

/**
 * Interface for Report persistence operations.
 */
interface ReportRepositoryInterface
{
    public function findById(int $id): ?Report;

    /**
     * @return Report[]
     */
    public function findByOwner(string $owner): array;

    /**
     * @return Report[]
     */
    public function findAllGlobal(): array;

    public function save(Report $report): void;

    public function delete(int $id): void;
}
