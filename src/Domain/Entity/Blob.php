<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\Entity;

use WebCalendar\Core\Domain\ValueObject\BlobType;

/**
 * Domain entity representing an event Attachment or Comment (stored as BLOB).
 */
final readonly class Blob
{
    public function __construct(
        private int $id,
        private int $eventId,
        private string $login,
        private string $name,
        private string $description,
        private int $size,
        private string $mimeType,
        private BlobType $type,
        private \DateTimeImmutable $date,
        private string $content = ''
    ) {
        if (empty(trim($this->login))) {
            throw new \InvalidArgumentException('Login cannot be empty.');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function eventId(): int
    {
        return $this->eventId;
    }

    public function login(): string
    {
        return $this->login;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function type(): BlobType
    {
        return $this->type;
    }

    public function date(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function content(): string
    {
        return $this->content;
    }
}
