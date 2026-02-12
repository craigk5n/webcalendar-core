<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Assistant;
use WebCalendar\Core\Domain\Repository\AssistantRepositoryInterface;

/**
 * PDO-based implementation of AssistantRepositoryInterface.
 */
final readonly class PdoAssistantRepository implements AssistantRepositoryInterface
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * @return string[]
     */
    public function findAssistantsForBoss(string $bossLogin): array
    {
        $stmt = $this->pdo->prepare('SELECT cal_assistant FROM webcal_asst WHERE cal_boss = :boss');
        $stmt->execute(['boss' => $bossLogin]);
        /** @var string[] $logins */
        $logins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $logins;
    }

    /**
     * @return string[]
     */
    public function findBossesForAssistant(string $asstLogin): array
    {
        $stmt = $this->pdo->prepare('SELECT cal_boss FROM webcal_asst WHERE cal_assistant = :asst');
        $stmt->execute(['asst' => $asstLogin]);
        /** @var string[] $logins */
        $logins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $logins;
    }

    public function save(Assistant $assistant): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM webcal_asst WHERE cal_boss = :boss AND cal_assistant = :asst');
        $stmt->execute(['boss' => $assistant->boss(), 'asst' => $assistant->assistant()]);
        
        if (!$stmt->fetch()) {
            $sql = 'INSERT INTO webcal_asst (cal_boss, cal_assistant) VALUES (:boss, :asst)';
            $this->pdo->prepare($sql)->execute(['boss' => $assistant->boss(), 'asst' => $assistant->assistant()]);
        }
    }

    public function delete(string $bossLogin, string $asstLogin): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM webcal_asst WHERE cal_boss = :boss AND cal_assistant = :asst');
        $stmt->execute(['boss' => $bossLogin, 'asst' => $asstLogin]);
    }
}
