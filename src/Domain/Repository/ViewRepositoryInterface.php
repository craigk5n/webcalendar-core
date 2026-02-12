<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\View;

/**
 * Interface for Custom View persistence operations.
 */
interface ViewRepositoryInterface
{
    public function findById(int $id): ?View;

    /**
     * @return View[]
     */
    public function findByOwner(string $owner): array;

    /**
     * @return View[]
     */
    public function findAllGlobal(): array;

    public function save(View $view): void;

    public function delete(int $id): void;

    /**
     * Gets all users (logins) assigned to a view.
     * @return string[]
     */
    public function getUsers(int $viewId): array;

    /**
     * Assigns users to a view.
     * @param string[] $logins
     */
    public function setUsers(int $viewId, array $logins): void;
}
