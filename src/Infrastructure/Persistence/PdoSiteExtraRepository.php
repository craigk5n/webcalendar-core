<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Repository\SiteExtraRepositoryInterface;

/**
 * PDO-based implementation of SiteExtraRepositoryInterface.
 */
final readonly class PdoSiteExtraRepository implements SiteExtraRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private string $tablePrefix = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare("SELECT cal_name, cal_data FROM {$this->tablePrefix}webcal_site_extras WHERE cal_id = :id");
        $stmt->execute(['id' => $eventId]);
        $extras = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $name = is_string($row['cal_name']) ? $row['cal_name'] : '';
                if ($name !== '') {
                    $extras[$name] = $this->decodeValue($row['cal_data']);
                }
            }
        }

        return $extras;
    }

    /**
     * @param array<string, mixed> $extras
     */
    public function saveForEvent(int $eventId, array $extras): void
    {
        $this->deleteForEvent($eventId);

        $stmt = $this->pdo->prepare("INSERT INTO {$this->tablePrefix}webcal_site_extras (cal_id, cal_name, cal_type, cal_data) VALUES (:id, :name, 0, :data)");
        
        foreach ($extras as $name => $value) {
            $stmt->execute([
                'id' => $eventId,
                'name' => $name,
                'data' => $this->encodeValue($value)
            ]);
        }
    }

    public function deleteForEvent(int $eventId): void
    {
        $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_site_extras WHERE cal_id = :id")
            ->execute(['id' => $eventId]);
    }

    /**
     * Encodes a value for storage.
     * Uses JSON for arrays/objects, string for scalars.
     */
    private function encodeValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string)$value;
        }

        if (is_array($value) || is_object($value)) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return '';
            }
        }

        return '';
    }

    /**
     * Decodes a value from storage.
     * Tries JSON decode first for arrays/objects, returns as-is for scalars.
     * 
     * Note: Does NOT unserialize legacy PHP serialized data for security reasons.
     * Legacy serialized data will be returned as a string prefixed with a warning.
     */
    private function decodeValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        if ($value === '') {
            return '';
        }

        // Try JSON decode first
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Check if this looks like legacy serialized PHP data
        // We do NOT unserialize for security - return as string with warning
        if ($this->looksLikeSerializedPhp($value)) {
            return '[LEGACY_SERIALIZED_DATA - migration required] ' . $value;
        }

        // Return as plain string
        return $value;
    }

    /**
     * Checks if a string looks like PHP serialized data.
     */
    private function looksLikeSerializedPhp(string $value): bool
    {
        return preg_match('/^[aObiNs]:\d+:/i', $value) === 1;
    }
}
