<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Contract;

/**
 * Interface for rate limiting operations.
 */
interface RateLimiterInterface
{
    /**
     * Checks if an action is allowed within the rate limit window.
     */
    public function isAllowed(string $identifier, string $action, int $maxAttempts, int $windowSeconds): bool;

    /**
     * Records an attempt for an identifier.
     */
    public function recordAttempt(string $identifier, string $action, int $windowSeconds): void;

    /**
     * Gets remaining attempts before the limit is hit.
     */
    public function getRemainingAttempts(string $identifier, string $action, int $maxAttempts, int $windowSeconds): int;

    /**
     * Seconds until the rate limit window resets (0 if not limited).
     */
    public function getResetTime(string $identifier, string $action, int $windowSeconds = 3600): int;

    /**
     * Clears all recorded attempts for an identifier + action pair.
     */
    public function clear(string $identifier, string $action): void;
}
