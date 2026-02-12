<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

use WebCalendar\Core\Domain\ValueObject\ActivityLogType;

/**
 * Domain entity representing an Activity Log entry.
 */
final readonly class ActivityLogEntry
{
    public function __construct(
        private int $id,
        private int $entryId,
        private string $login,
        private ?string $userCal,
        private ActivityLogType $type,
        private \DateTimeImmutable $date,
        private string $text = ''
    ) {
        if (empty(trim($this->login))) {
            throw new \InvalidArgumentException('Login cannot be empty.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function entryId(): int
    {
        return $this->entryId;
    }

    public function login(): string
    {
        return $this->login;
    }

    public function userCal(): ?string
    {
        return $this->userCal;
    }

    public function type(): ActivityLogType
    {
        return $this->type;
    }

    public function date(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function text(): string
    {
        return $this->text;
    }
}
