<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Security;

use WebCalendar\Core\Application\Contract\AuthServiceInterface;
use WebCalendar\Core\Application\Contract\RateLimiterInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Default implementation of AuthServiceInterface using the database repository.
 * Includes rate limiting to prevent brute force attacks.
 */
final readonly class DatabaseAuthService implements AuthServiceInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private UserRepositoryInterface $userRepository,
        private ?RateLimiterInterface $rateLimiter = null,
        private int $maxLoginAttempts = 5,
        private int $lockoutWindow = 900, // 15 minutes
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function authenticate(string $username, string $password): bool
    {
        $identifier = $this->getIdentifier($username);

        // Check rate limit
        if ($this->rateLimiter !== null && !$this->rateLimiter->isAllowed($identifier, 'login', $this->maxLoginAttempts, $this->lockoutWindow)) {
            $this->logger->warning('Login rate limit exceeded', [
                'username' => $username,
                'remaining' => 0
            ]);
            return false;
        }

        $hash = $this->userRepository->getPasswordHash($username);
        
        if ($hash === null) {
            $this->recordFailedAttempt($identifier);
            return false;
        }

        $valid = password_verify($password, $hash);

        if ($valid) {
            // Clear rate limit on successful login
            $this->clearRateLimit($identifier);
            $this->logger->info('User authenticated successfully', ['username' => $username]);
        } else {
            $this->recordFailedAttempt($identifier);
            $this->logger->warning('Authentication failed', ['username' => $username]);
        }

        return $valid;
    }

    public function verifyHash(string $username, string $hash): bool
    {
        $identifier = $this->getIdentifier($username);

        // Check rate limit
        if ($this->rateLimiter !== null && !$this->rateLimiter->isAllowed($identifier, 'verify', $this->maxLoginAttempts, $this->lockoutWindow)) {
            return false;
        }

        $storedHash = $this->userRepository->getPasswordHash($username);
        
        if ($storedHash === null) {
            $this->recordFailedAttempt($identifier);
            return false;
        }

        $valid = hash_equals($storedHash, $hash);

        if ($valid) {
            $this->clearRateLimit($identifier);
        } else {
            $this->recordFailedAttempt($identifier);
        }

        return $valid;
    }

    /**
     * Gets remaining login attempts for a user.
     */
    public function getRemainingAttempts(string $username): int
    {
        if ($this->rateLimiter === null) {
            return PHP_INT_MAX;
        }

        $identifier = $this->getIdentifier($username);
        return $this->rateLimiter->getRemainingAttempts($identifier, 'login', $this->maxLoginAttempts, $this->lockoutWindow);
    }

    /**
     * Gets seconds until lockout expires.
     */
    public function getLockoutTime(string $username): int
    {
        if ($this->rateLimiter === null) {
            return 0;
        }

        $identifier = $this->getIdentifier($username);
        return $this->rateLimiter->getResetTime($identifier, 'login', $this->lockoutWindow);
    }

    private function getIdentifier(string $username): string
    {
        return 'login:' . strtolower($username);
    }

    private function recordFailedAttempt(string $identifier): void
    {
        if ($this->rateLimiter !== null) {
            $this->rateLimiter->recordAttempt($identifier, 'login', $this->lockoutWindow);
        }
    }

    private function clearRateLimit(string $identifier): void
    {
        if ($this->rateLimiter !== null) {
            $this->rateLimiter->clear($identifier, 'login');
        }
    }
}
