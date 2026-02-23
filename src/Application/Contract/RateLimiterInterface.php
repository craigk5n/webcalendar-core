<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Contract;

/**
 * Interface for rate limiting operations.
 */
interface RateLimiterInterface
{
    /**
     * Checks if an action is allowed for a given identifier.
     *
     * @param string $identifier Unique identifier (e.g., IP address, user login)
     * @param string $action The action being rate limited (e.g., 'login', 'api', 'import')
     * @param int $maxAttempts Maximum attempts allowed in the window
     * @param int $windowSeconds Time window in seconds
     * @return bool True if action is allowed, false if limit exceeded
     */
    public function isAllowed(string $identifier, string $action, int $maxAttempts, int $windowSeconds): bool;

    /**
     * Records an attempt for an identifier.
     *
     * @param string $identifier Unique identifier
     * @param string $action The action being recorded
     * @param int $windowSeconds Time window in seconds
     */
    public function recordAttempt(string $identifier, string $action, int $windowSeconds): void;

    /**
     * Gets remaining attempts for an identifier.
     *
     * @param string $identifier Unique identifier
     * @param string $action The action being checked
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return int Number of attempts remaining
     */
    public function getRemainingAttempts(string $identifier, string $action, int $maxAttempts, int $windowSeconds): int;

    /**
     * Gets seconds until the rate limit resets.
     *
     * @param string $identifier Unique identifier
     * @param string $action The action being checked
     * @param int $windowSeconds Time window in seconds
     * @return int Seconds until reset, 0 if not limited
     */
    public function getResetTime(string $identifier, string $action, int $windowSeconds = 3600): int;

    /**
     * Clears all attempts for an identifier.
     *
     * @param string $identifier Unique identifier
     * @param string $action The action to clear
     */
    public function clear(string $identifier, string $action): void;
}
