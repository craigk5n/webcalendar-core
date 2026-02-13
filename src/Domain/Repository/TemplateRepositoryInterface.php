<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Repository;

/**
 * Interface for User Template persistence.
 */
interface TemplateRepositoryInterface
{
    public function get(string $login, string $type): ?string;

    /**
     * @return array<string, string> Map of type to template text for a user.
     */
    public function getAllForUser(string $login): array;

    public function set(string $login, string $type, string $text): void;

    public function delete(string $login, string $type): void;
}
