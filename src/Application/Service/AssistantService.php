<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Assistant;
use WebCalendar\Core\Domain\Repository\AssistantRepositoryInterface;

/**
 * Service for managing boss/assistant relationships.
 */
final readonly class AssistantService
{
    public function __construct(
        private AssistantRepositoryInterface $assistantRepository
    ) {
    }

    /**
     * @return string[]
     */
    public function getAssistantsForBoss(string $bossLogin): array
    {
        return $this->assistantRepository->findAssistantsForBoss($bossLogin);
    }

    /**
     * @return string[]
     */
    public function getBossesForAssistant(string $asstLogin): array
    {
        return $this->assistantRepository->findBossesForAssistant($asstLogin);
    }

    public function assignAssistant(string $bossLogin, string $asstLogin): void
    {
        $this->assistantRepository->save(new Assistant($bossLogin, $asstLogin));
    }

    public function removeAssistant(string $bossLogin, string $asstLogin): void
    {
        $this->assistantRepository->delete($bossLogin, $asstLogin);
    }
}
