<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Repository\TokenRepositoryInterface;

/**
 * PDO-based implementation of TokenRepositoryInterface.
 */
final readonly class PdoTokenRepository implements TokenRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private string $tablePrefix = '',
    ) {
    }

    public function store(string $token, string $type, string $data, int $ttlSeconds = 0): void
    {
        $expiresAt = $ttlSeconds > 0
            ? time() + $ttlSeconds
            : null;

        $inTransaction = $this->pdo->inTransaction();
        if (!$inTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // Delete any existing token with same value and type
            $this->delete($token, $type);

            $sql = "INSERT INTO {$this->tablePrefix}webcal_tokens
                    (cal_token, cal_type, cal_data, cal_created_at, cal_expires_at)
                    VALUES (:token, :type, :data, :created_at, :expires_at)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'token' => $token,
                'type' => $type,
                'data' => $data,
                'created_at' => time(),
                'expires_at' => $expiresAt,
            ]);

            if (!$inTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$inTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function get(string $token, string $type): ?string
    {
        // Probabilistic cleanup: ~1% of calls
        if (random_int(1, 100) === 1) {
            $this->deleteExpired();
        }

        $sql = "SELECT cal_data FROM {$this->tablePrefix}webcal_tokens 
                WHERE cal_token = :token AND cal_type = :type 
                AND (cal_expires_at IS NULL OR cal_expires_at > :now)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'token' => $token,
            'type' => $type,
            'now' => time(),
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!is_array($result) || !isset($result['cal_data'])) {
            return null;
        }

        return is_string($result['cal_data']) ? $result['cal_data'] : null;
    }

    public function validate(string $token, string $type, string $expectedData): bool
    {
        $data = $this->get($token, $type);
        return $data !== null && $data === $expectedData;
    }

    public function delete(string $token, string $type): void
    {
        $sql = "DELETE FROM {$this->tablePrefix}webcal_tokens 
                WHERE cal_token = :token AND cal_type = :type";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'token' => $token,
            'type' => $type,
        ]);
    }

    public function deleteExpired(): void
    {
        $sql = "DELETE FROM {$this->tablePrefix}webcal_tokens 
                WHERE cal_expires_at IS NOT NULL AND cal_expires_at < :now";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['now' => time()]);
    }

    public function deleteByData(string $type, string $data): void
    {
        $sql = "DELETE FROM {$this->tablePrefix}webcal_tokens 
                WHERE cal_type = :type AND cal_data = :data";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'type' => $type,
            'data' => $data,
        ]);
    }
}
