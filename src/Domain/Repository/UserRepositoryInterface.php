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
     * Returns only enabled users.
     * @return User[]
     */
    public function findAllEnabled(): array;

    /**
     * Persists a user.
     */
    public function save(User $user): void;

    /**
     * Deletes a user by their login, along with the data they own.
     *
     * Entries the user created are deleted when nobody else attends them.
     * Entries other users also attend are reassigned to $successor so the
     * attendees keep them; with no successor they are deleted too. Nothing
     * may stay owned by the bare login, or a later account created with the
     * same login would inherit it.
     *
     * @throws \DomainException if an entry to be deleted has PENDING or PAID
     *         ticket orders; nothing is deleted in that case.
     */
    public function delete(string $login, ?string $successor = null): void;

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
