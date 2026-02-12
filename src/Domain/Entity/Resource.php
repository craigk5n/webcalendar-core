<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

/**
 * Domain entity representing a non-user calendar Resource (room, equipment, etc.).
 */
final readonly class Resource
{
    public function __construct(
        private string $login,
        private string $name,
        private string $admin,
        private bool $isPublic = false,
        private ?string $url = null
    ) {
        if (empty(trim($this->login))) {
            throw new \InvalidArgumentException('Resource login cannot be empty.');
        }
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Resource name cannot be empty.');
        }
    }

    public function login(): string
    {
        return $this->login;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function admin(): string
    {
        return $this->admin;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function url(): ?string
    {
        return $this->url;
    }
}
