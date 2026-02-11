<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Contract;

/**
 * Interface for authentication services.
 */
interface AuthServiceInterface
{
    /**
     * Validates user credentials.
     */
    public function authenticate(string $username, string $password): bool;

    /**
     * Verifies a pre-hashed password (for session/cookie validation).
     */
    public function verifyHash(string $username, string $hash): bool;
}
