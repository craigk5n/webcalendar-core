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
final class DatabaseAuthService implements AuthServiceInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly ?RateLimiterInterface $rateLimiter = null,
        private readonly int $maxLoginAttempts = 5,
        private readonly int $lockoutWindow = 900, // 15 minutes
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function authenticate(string $username, string $password): bool
    {
        $identifier = $this->getIdentifier($username);

        if (!$this->reserveAttempt($identifier, 'login')) {
            $this->logger->warning('Login rate limit exceeded', [
                'username' => $username,
                'remaining' => 0
            ]);
            return false;
        }

        $hash = $this->getActiveAccountHash($username);
        $valid = $hash !== null && password_verify($password, $hash);

        if ($valid) {
            // The reserved attempt counted as a failure until now.
            $this->clearRateLimit($identifier, 'login');
            $this->logger->info('User authenticated successfully', ['username' => $username]);
        } else {
            $this->logger->warning('Authentication failed', ['username' => $username]);
        }

        return $valid;
    }

    /**
     * Rate limited in its own 'verify' bucket: a wrong hash does not use up
     * password-login attempts, and a right one does not reset them.
     */
    public function verifyHash(string $username, string $hash): bool
    {
        $identifier = $this->getIdentifier($username);

        if (!$this->reserveAttempt($identifier, 'verify')) {
            return false;
        }

        $storedHash = $this->getActiveAccountHash($username);
        $valid = $storedHash !== null && hash_equals($storedHash, $hash);

        if ($valid) {
            $this->clearRateLimit($identifier, 'verify');
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

    /**
     * Returns the stored password hash only for an existing, enabled account
     * whose login is the one supplied. Disabled accounts (cal_enabled = 'N')
     * cannot authenticate.
     *
     * The lookup runs under the column's collation. MySQL's defaults
     * (utf8mb4_unicode_ci, utf8mb4_0900_ai_ci) ignore accents and, for
     * PAD SPACE collations, trailing spaces, so 'àlice' or 'alice ' would
     * find alice's row while getting a rate-limit bucket of its own. The
     * matched login must equal the supplied one under the same folding the
     * bucket key uses (ASCII case only).
     */
    private function getActiveAccountHash(string $username): ?string
    {
        $hash = $this->userRepository->getPasswordHash($username);
        if ($hash === null) {
            return null;
        }

        $user = $this->userRepository->findByLogin($username);
        if ($user === null || !$user->isEnabled()) {
            $this->logger->warning('Login refused for disabled or missing account', [
                'username' => $username
            ]);
            return null;
        }

        if (strtolower($user->login()) !== strtolower($username)) {
            $this->logger->warning('Login refused: supplied login only matches by collation', [
                'username' => $username
            ]);
            return null;
        }

        return $hash;
    }

    private function getIdentifier(string $username): string
    {
        return 'login:' . strtolower($username);
    }

    /**
     * Records this attempt before it is checked, then allows it only if it
     * is within the limit counting itself. Checking first and recording
     * after a slow password_verify() let every concurrent request pass the
     * check before any of them was counted.
     *
     * The attempt stays recorded as a failure unless the caller clears the
     * bucket on success. An attempt made while already locked out is not
     * recorded, so the lockout still expires on schedule while someone
     * keeps trying.
     */
    private function reserveAttempt(string $identifier, string $action): bool
    {
        if ($this->rateLimiter === null) {
            return true;
        }

        if (!$this->rateLimiter->isAllowed($identifier, $action, $this->maxLoginAttempts, $this->lockoutWindow)) {
            return false;
        }

        $this->rateLimiter->recordAttempt($identifier, $action, $this->lockoutWindow);

        return $this->rateLimiter->isAllowed(
            $identifier,
            $action,
            $this->maxLoginAttempts + 1,
            $this->lockoutWindow
        );
    }

    private function clearRateLimit(string $identifier, string $action): void
    {
        $this->rateLimiter?->clear($identifier, $action);
    }
}
