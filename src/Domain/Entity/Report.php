<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * Domain entity representing a custom Report.
 */
final readonly class Report
{
    /**
     * @param array<string, string> $templates Associative array of templates [type => text].
     */
    public function __construct(
        private int $id,
        private string $owner,
        private string $name,
        private string $type,
        private bool $isGlobal = false,
        private array $templates = []
    ) {
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Report name cannot be empty.');
        }
        if (empty(trim($this->owner))) {
            throw new \InvalidArgumentException('Report owner cannot be empty.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isGlobal(): bool
    {
        return $this->isGlobal;
    }

    /**
     * @return array<string, string>
     */
    public function templates(): array
    {
        return $this->templates;
    }

    public function template(string $type): ?string
    {
        return $this->templates[$type] ?? null;
    }
}
