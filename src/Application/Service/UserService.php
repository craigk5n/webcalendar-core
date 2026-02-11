<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\UserPreference;

/**
 * Service for managing Users and their preferences.
 */
final readonly class UserService
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {
    }

    /**
     * Finds a user by their login.
     */
    public function getUserByLogin(string $login): ?User
    {
        return $this->userRepository->findByLogin($login);
    }

    /**
     * Creates a new user in the system.
     */
    public function createUser(User $user): void
    {
        $this->userRepository->save($user);
    }

    /**
     * Updates an existing user.
     */
    public function updateUser(User $user): void
    {
        $this->userRepository->save($user);
    }

    /**
     * Returns all users.
     * @return User[]
     */
    public function getAllUsers(): array
    {
        return $this->userRepository->findAll();
    }

    /**
     * Deletes a user.
     */
    public function deleteUser(string $login): void
    {
        $this->userRepository->delete($login);
    }

    /**
     * Gets all preferences for a user.
     * @return UserPreference[]
     */
    public function getPreferences(string $login): array
    {
        return $this->userRepository->getPreferences($login);
    }

    /**
     * Updates a single preference for a user.
     */
    public function updatePreference(string $login, string $key, string $value): void
    {
        $this->userRepository->savePreference($login, new UserPreference($key, $value));
    }
}
