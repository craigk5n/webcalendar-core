<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Security;

use PDO;
use WebCalendar\Core\Application\Contract\RateLimiterInterface;

/**
 * PDO-based implementation of RateLimiterInterface.
 * 
 * Uses database table to track rate limit attempts.
 */
final readonly class PdoRateLimiter implements RateLimiterInterface
{
    public function __construct(
        private PDO $pdo,
        private string $tablePrefix = '',
    ) {
    }

    public function isAllowed(string $identifier, string $action, int $maxAttempts, int $windowSeconds): bool
    {
        // Probabilistic cleanup: ~1% of calls
        if (random_int(1, 100) === 1) {
            $this->cleanupExpired();
        }

        $count = $this->getAttemptCount($identifier, $action, $windowSeconds);
        return $count < $maxAttempts;
    }

    public function recordAttempt(string $identifier, string $action, int $windowSeconds): void
    {
        $sql = "INSERT INTO {$this->tablePrefix}webcal_rate_limits 
                (cal_identifier, cal_action, cal_attempt_at)
                VALUES (:identifier, :action, :attempt_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'identifier' => $this->hashIdentifier($identifier),
            'action' => $action,
            'attempt_at' => time(),
        ]);
    }

    public function getRemainingAttempts(string $identifier, string $action, int $maxAttempts, int $windowSeconds): int
    {
        $count = $this->getAttemptCount($identifier, $action, $windowSeconds);
        return max(0, $maxAttempts - $count);
    }

    public function getResetTime(string $identifier, string $action, int $windowSeconds = 3600): int
    {
        $cutoff = time() - $windowSeconds;

        $sql = "SELECT MIN(cal_attempt_at) as first_attempt
                FROM {$this->tablePrefix}webcal_rate_limits
                WHERE cal_identifier = :identifier AND cal_action = :action
                AND cal_attempt_at > :cutoff";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'identifier' => $this->hashIdentifier($identifier),
            'action' => $action,
            'cutoff' => $cutoff,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($result) || !isset($result['first_attempt'])) {
            return 0;
        }

        $firstAttempt = is_numeric($result['first_attempt']) ? (int)$result['first_attempt'] : 0;

        if ($firstAttempt === 0) {
            return 0;
        }

        return max(0, $firstAttempt + $windowSeconds - time());
    }

    public function clear(string $identifier, string $action): void
    {
        $sql = "DELETE FROM {$this->tablePrefix}webcal_rate_limits 
                WHERE cal_identifier = :identifier AND cal_action = :action";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'identifier' => $this->hashIdentifier($identifier),
            'action' => $action,
        ]);
    }

    /**
     * Gets the count of attempts within the window.
     */
    private function getAttemptCount(string $identifier, string $action, int $windowSeconds): int
    {
        $cutoff = time() - $windowSeconds;

        $sql = "SELECT COUNT(*) as count 
                FROM {$this->tablePrefix}webcal_rate_limits 
                WHERE cal_identifier = :identifier 
                AND cal_action = :action 
                AND cal_attempt_at > :cutoff";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'identifier' => $this->hashIdentifier($identifier),
            'action' => $action,
            'cutoff' => $cutoff,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!is_array($result) || !isset($result['count'])) {
            return 0;
        }

        return is_numeric($result['count']) ? (int)$result['count'] : 0;
    }

    /**
     * Removes expired rate limit entries.
     */
    private function cleanupExpired(): void
    {
        // Remove entries older than 1 hour
        $cutoff = time() - 3600;

        $sql = "DELETE FROM {$this->tablePrefix}webcal_rate_limits 
                WHERE cal_attempt_at < :cutoff";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['cutoff' => $cutoff]);
    }

    /**
     * Hashes the identifier for privacy (IP addresses, etc.).
     */
    private function hashIdentifier(string $identifier): string
    {
        return hash('sha256', $identifier);
    }
}
