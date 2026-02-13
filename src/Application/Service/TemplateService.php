<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Repository\TemplateRepositoryInterface;

/**
 * Service for managing user-customizable templates (headers/footers).
 */
final readonly class TemplateService
{
    public function __construct(
        private TemplateRepositoryInterface $templateRepository
    ) {
    }

    public function getTemplate(string $login, string $type): ?string
    {
        return $this->templateRepository->get($login, $type);
    }

    /**
     * @return array<string, string>
     */
    public function getAllTemplatesForUser(string $login): array
    {
        return $this->templateRepository->getAllForUser($login);
    }

    public function setTemplate(string $login, string $type, string $text): void
    {
        $this->templateRepository->set($login, $type, $text);
    }

    public function deleteTemplate(string $login, string $type): void
    {
        $this->templateRepository->delete($login, $type);
    }
}
