<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Repository\ConfigRepositoryInterface;

/**
 * PDO-based implementation of ConfigRepositoryInterface.
 */
final readonly class PdoConfigRepository implements ConfigRepositoryInterface
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT cal_value FROM webcal_config WHERE cal_setting = :key');
        $stmt->execute(['key' => $key]);
        $val = $stmt->fetchColumn();

        return is_string($val) ? $val : null;
    }

    /**
     * @return array<string, string>
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query('SELECT cal_setting, cal_value FROM webcal_config');
        $settings = [];

        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $key = is_string($row['cal_setting']) ? $row['cal_setting'] : '';
                    $value = is_string($row['cal_value']) ? $row['cal_value'] : '';
                    if ($key !== '') {
                        $settings[$key] = $value;
                    }
                }
            }
        }

        return $settings;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM webcal_config WHERE cal_setting = :key');
        $stmt->execute(['key' => $key]);
        
        if ($stmt->fetch()) {
            $sql = 'UPDATE webcal_config SET cal_value = :value WHERE cal_setting = :key';
        } else {
            $sql = 'INSERT INTO webcal_config (cal_setting, cal_value) VALUES (:key, :value)';
        }

        $this->pdo->prepare($sql)->execute(['key' => $key, 'value' => $value]);
    }

    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM webcal_config WHERE cal_setting = :key');
        $stmt->execute(['key' => $key]);
    }
}
