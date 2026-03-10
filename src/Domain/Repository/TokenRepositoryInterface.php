<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

/**
 * Interface for token storage (sessions, CSRF, etc.).
 */
interface TokenRepositoryInterface
{
    /**
     * Stores a token with associated data and optional TTL.
     */
    public function store(string $token, string $type, string $data, int $ttlSeconds = 0): void;

    /**
     * Retrieves data associated with a token, or null if expired/missing.
     */
    public function get(string $token, string $type): ?string;

    /**
     * Validates a token exists, is not expired, and matches expected data.
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
