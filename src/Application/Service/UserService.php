<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\Exception\AuthorizationException;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\UserPreference;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for managing Users and their preferences.
 */
final readonly class UserService
{
    private LoggerInterface $logger;

    public function __construct(
        private UserRepositoryInterface $userRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
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
     * 
     * @throws AuthorizationException if actor is not an admin
     */
    public function createUser(User $user, User $actor): void
    {
        $this->assertAdmin($actor, 'create user');
        $this->logger->info('User created', ['login' => $user->login(), 'actor' => $actor->login()]);
        $this->userRepository->save($user);
    }

    /**
     * Updates an existing user.
     * 
     * @throws AuthorizationException if actor is not admin or the user being updated
     */
    public function updateUser(User $user, User $actor): void
    {
        $this->assertAdminOrSelf($actor, $user->login(), 'update user');
        $this->logger->info('User updated', ['login' => $user->login(), 'actor' => $actor->login()]);
        $this->userRepository->save($user);
    }

    /**
     * Returns all users.
     * 
     * @throws AuthorizationException if actor is not an admin
     * @return User[]
     */
    public function getAllUsers(User $actor): array
    {
        $this->assertAdmin($actor, 'list all users');
        return $this->userRepository->findAll();
    }

    /**
     * Deletes a user.
     * 
     * @throws AuthorizationException if actor is not an admin
     */
    public function deleteUser(string $login, User $actor): void
    {
        $this->assertAdmin($actor, 'delete user');
        $this->logger->info('User deleted', ['login' => $login, 'actor' => $actor->login()]);
        $this->userRepository->delete($login);
    }

    /**
     * Gets all preferences for a user.
     * 
     * @throws AuthorizationException if actor is not admin or the user whose preferences are being accessed
     * @return UserPreference[]
     */
    public function getPreferences(string $login, User $actor): array
    {
        $this->assertAdminOrSelf($actor, $login, 'view preferences');
        return $this->userRepository->getPreferences($login);
    }

    /**
     * Updates a single preference for a user.
     * 
     * @throws AuthorizationException if actor is not admin or the user whose preference is being updated
     */
    public function updatePreference(string $login, string $key, string $value, User $actor): void
    {
        $this->assertAdminOrSelf($actor, $login, 'update preference');
        $this->userRepository->savePreference($login, new UserPreference($key, $value));
    }

    /**
     * Hashes a password for storage.
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    /**
     * Asserts that the actor is an admin.
     */
    private function assertAdmin(User $actor, string $action): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        throw AuthorizationException::adminRequired($action);
    }

    /**
     * Asserts that the actor is an admin or the target user.
     */
    private function assertAdminOrSelf(User $actor, string $targetLogin, string $action): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($actor->login() === $targetLogin) {
            return;
        }

        throw AuthorizationException::notOwner($action, 0, $actor->login());
    }
}
