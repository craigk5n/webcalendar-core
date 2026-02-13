<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Repository\TemplateRepositoryInterface;

/**
 * PDO-based implementation of TemplateRepositoryInterface.
 */
final readonly class PdoTemplateRepository implements TemplateRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private string $tablePrefix = '',
    ) {
    }

    public function get(string $login, string $type): ?string
    {
        $stmt = $this->pdo->prepare("SELECT cal_template_text FROM {$this->tablePrefix}webcal_user_template WHERE cal_login = :login AND cal_type = :type");
        $stmt->execute(['login' => $login, 'type' => $type]);
        $val = $stmt->fetchColumn();

        return is_string($val) ? $val : null;
    }

    /**
     * @return array<string, string>
     */
    public function getAllForUser(string $login): array
    {
        $stmt = $this->pdo->prepare("SELECT cal_type, cal_template_text FROM {$this->tablePrefix}webcal_user_template WHERE cal_login = :login");
        $stmt->execute(['login' => $login]);
        $templates = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $type = is_string($row['cal_type']) ? $row['cal_type'] : '';
                $text = is_string($row['cal_template_text']) ? $row['cal_template_text'] : '';
                if ($type !== '') {
                    $templates[$type] = $text;
                }
            }
        }

        return $templates;
    }

    public function set(string $login, string $type, string $text): void
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->tablePrefix}webcal_user_template WHERE cal_login = :login AND cal_type = :type");
        $stmt->execute(['login' => $login, 'type' => $type]);
        
        if ($stmt->fetch()) {
            $sql = "UPDATE {$this->tablePrefix}webcal_user_template SET cal_template_text = :text
                    WHERE cal_login = :login AND cal_type = :type";
        } else {
            $sql = "INSERT INTO {$this->tablePrefix}webcal_user_template (cal_login, cal_type, cal_template_text)
                    VALUES (:login, :type, :text)";
        }

        $this->pdo->prepare($sql)->execute(['login' => $login, 'type' => $type, 'text' => $text]);
    }

    public function delete(string $login, string $type): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_user_template WHERE cal_login = :login AND cal_type = :type");
        $stmt->execute(['login' => $login, 'type' => $type]);
    }
}
