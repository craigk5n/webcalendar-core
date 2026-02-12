<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Group;
use WebCalendar\Core\Domain\Repository\GroupRepositoryInterface;

/**
 * Service for managing user groups.
 */
final readonly class GroupService
{
    public function __construct(
        private GroupRepositoryInterface $groupRepository
    ) {
    }

    /**
     * Gets all groups owned by a specific user.
     * 
     * @return Group[]
     */
    public function getGroupsByOwner(string $login): array
    {
        return $this->groupRepository->findByOwner($login);
    }

    /**
     * Gets all groups in the system.
     * @return Group[]
     */
    public function getAllGroups(): array
    {
        return $this->groupRepository->findAll();
    }

    public function createGroup(Group $group): void
    {
        $this->groupRepository->save($group);
    }

    public function deleteGroup(int $id): void
    {
        $this->groupRepository->delete($id);
    }

    /**
     * Gets all member logins for a group.
     * @return string[]
     */
    public function getGroupMembers(int $groupId): array
    {
        return $this->groupRepository->getMembers($groupId);
    }

    public function addMember(int $groupId, string $login): void
    {
        $this->groupRepository->addMember($groupId, $login);
    }

    public function removeMember(int $groupId, string $login): void
    {
        $this->groupRepository->removeMember($groupId, $login);
    }
}
