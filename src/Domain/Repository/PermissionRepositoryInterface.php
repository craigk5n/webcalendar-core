<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

/**
 * Interface for retrieving user permissions (UAC).
 */
interface PermissionRepositoryInterface
{
    /**
     * Gets the permission bitmask string for a user.
     * 
     * @param string $login The user's login identifier.
     * @return string A string of 'Y' and 'N' characters (max 64).
     */
    public function getPermissions(string $login): string;
}
