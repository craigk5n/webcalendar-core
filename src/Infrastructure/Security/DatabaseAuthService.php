<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Security;

use WebCalendar\Core\Application\Contract\AuthServiceInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;

/**
 * Default implementation of AuthServiceInterface using the database repository.
 */
final readonly class DatabaseAuthService implements AuthServiceInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository
    ) {
    }

    public function authenticate(string $username, string $password): bool
    {
        $hash = $this->userRepository->getPasswordHash($username);
        
        if ($hash === null) {
            return false;
        }

        return password_verify($password, $hash);
    }

    public function verifyHash(string $username, string $hash): bool
    {
        $storedHash = $this->userRepository->getPasswordHash($username);
        
        if ($storedHash === null) {
            return false;
        }

        return $hash === $storedHash;
    }
}
