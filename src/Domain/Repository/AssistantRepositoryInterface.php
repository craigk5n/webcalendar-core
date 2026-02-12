<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

use WebCalendar\Core\Domain\Entity\Assistant;

/**
 * Interface for Assistant persistence operations.
 */
interface AssistantRepositoryInterface
{
    /**
     * @return string[] List of assistant logins for a boss.
     */
    public function findAssistantsForBoss(string $bossLogin): array;

    /**
     * @return string[] List of boss logins for an assistant.
     */
    public function findBossesForAssistant(string $asstLogin): array;

    public function save(Assistant $assistant): void;

    public function delete(string $bossLogin, string $asstLogin): void;
}
