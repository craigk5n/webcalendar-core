<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

/**
 * Interface for system configuration persistence.
 */
interface ConfigRepositoryInterface
{
    public function get(string $key): ?string;

    /**
     * @return array<string, string>
     */
    public function getAll(): array;

    public function set(string $key, string $value): void;

    public function delete(string $key): void;
}
