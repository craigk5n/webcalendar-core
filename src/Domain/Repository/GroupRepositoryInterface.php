<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Group;

/**
 * Interface for Group persistence operations.
 */
interface GroupRepositoryInterface
{
    public function findById(int $id): ?Group;

    /**
     * @return Group[]
     */
    public function findAll(): array;

    /**
     * @return Group[]
     */
    public function findByOwner(string $owner): array;

    public function save(Group $group): void;

    public function delete(int $id): void;

    /**
     * Gets all member logins for a group.
     * @return string[]
     */
    public function getMembers(int $groupId): array;

    /**
     * Adds a member to a group.
     */
    public function addMember(int $groupId, string $login): void;

    /**
     * Removes a member from a group.
     */
    public function removeMember(int $groupId, string $login): void;
}
