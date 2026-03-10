<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\PermissionRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\Permission;

/**
 * Service for checking user permissions (UAC).
 */
final readonly class PermissionService
{
    public function __construct(
        private PermissionRepositoryInterface $permissionRepository
    ) {
    }

    /**
     * Checks if a user has permission to access a system function.
     */
    public function canAccess(User $user, Permission $permission): bool
    {
        // Admin users bypass all checks (PRD 9.6)
        if ($user->isAdmin()) {
            return true;
        }

        $bitmask = $this->permissionRepository->getPermissions($user->login());
        $index = $permission->value;

        if (!isset($bitmask[$index])) {
            return false;
        }

        return $bitmask[$index] === 'Y';
    }
}
