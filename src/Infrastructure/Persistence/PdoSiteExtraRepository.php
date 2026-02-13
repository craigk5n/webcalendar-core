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
                    $extras[$name] = $row['cal_data'];
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
                'data' => is_scalar($value) ? (string)$value : serialize($value)
            ]);
        }
    }

    public function deleteForEvent(int $eventId): void
    {
        $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_site_extras WHERE cal_id = :id")
            ->execute(['id' => $eventId]);
    }
}
