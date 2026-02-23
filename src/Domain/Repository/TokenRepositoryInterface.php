<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

/**
 * Interface for token storage (sessions, CSRF, etc.).
 */
interface TokenRepositoryInterface
{
    /**
     * Stores a token with associated data.
     *
     * @param string $token The token string
     * @param string $type Token type (e.g., 'session', 'csrf')
     * @param string $data Associated data (e.g., user login for session tokens)
     * @param int $ttlSeconds Time to live in seconds (0 = no expiration)
     */
    public function store(string $token, string $type, string $data, int $ttlSeconds = 0): void;

    /**
     * Retrieves data associated with a token.
     *
     * @param string $token The token string
     * @param string $type Token type
     * @return string|null The associated data, or null if not found/expired
     */
    public function get(string $token, string $type): ?string;

    /**
     * Validates a token exists and matches expected data.
     *
     * @param string $token The token string
     * @param string $type Token type
     * @param string $expectedData Expected data to match
     * @return bool True if token exists and data matches
     */
    public function validate(string $token, string $type, string $expectedData): bool;

    /**
     * Deletes a token.
     */
    public function delete(string $token, string $type): void;

    /**
     * Deletes all expired tokens.
     */
    public function deleteExpired(): void;

    /**
     * Deletes all tokens for a specific user/session.
     */
    public function deleteByData(string $type, string $data): void;
}
