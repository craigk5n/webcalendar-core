<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Repository\ConfigRepositoryInterface;

/**
 * Service for managing global system settings.
 */
final readonly class ConfigService
{
    public function __construct(
        private ConfigRepositoryInterface $configRepository
    ) {
    }

    /**
     * Gets a system setting.
     */
    public function getSetting(string $key, ?string $default = null): ?string
    {
        return $this->configRepository->get($key) ?? $default;
    }

    /**
     * Gets all system settings.
     * 
     * @return array<string, string>
     */
    public function getAllSettings(): array
    {
        return $this->configRepository->getAll();
    }

    /**
     * Updates or creates a system setting.
     */
    public function updateSetting(string $key, string $value): void
    {
        $this->configRepository->set($key, $value);
    }

    /**
     * Deletes a system setting.
     */
    public function deleteSetting(string $key): void
    {
        $this->configRepository->delete($key);
    }
}
