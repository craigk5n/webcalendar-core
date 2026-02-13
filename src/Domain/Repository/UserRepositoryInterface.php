<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

/**
 * Interface for User persistence operations.
 */
interface UserRepositoryInterface
{
    /**
     * Finds a user by their unique login.
     */
    public function findByLogin(string $login): ?User;

    /**
     * Returns all users in the system.
     * @return User[]
     */
    public function findAll(): array;

    /**
     * Persists a user.
     */
    public function save(User $user): void;

    /**
     * Deletes a user by their login.
     */
    public function delete(string $login): void;

    /**
     * Returns all preferences for a user.
     * @return UserPreference[]
     */
    public function getPreferences(string $login): array;

    /**
     * Saves a preference for a user.
     */
    public function savePreference(string $login, UserPreference $preference): void;

    /**
     * Gets the password hash for a user.
     */
    public function getPasswordHash(string $login): ?string;

    /**
     * Sets the password hash for a user.
     */
    public function setPassword(string $login, string $hash): void;
}
