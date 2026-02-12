<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Resource;

/**
 * Interface for Resource persistence operations.
 */
interface ResourceRepositoryInterface
{
    public function findByLogin(string $login): ?Resource;

    /**
     * @return Resource[]
     */
    public function findAll(): array;

    /**
     * @return Resource[]
     */
    public function findByAdmin(string $adminLogin): array;

    public function save(Resource $resource): void;

    public function delete(string $login): void;
}
