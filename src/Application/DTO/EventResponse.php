<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\DTO;

use WebCalendar\Core\Domain\Entity\Event;

/**
 * Data Transfer Object for Event responses.
 */
final class EventResponse implements \JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly string $uid,
        public readonly string $name,
        public readonly string $description,
        public readonly string $location,
        public readonly string $start,
        public readonly int $duration,
        public readonly string $createdBy,
        public readonly string $type,
        public readonly string $access,
        public readonly ?string $rrule = null,
        public readonly int $sequence = 0,
        public readonly ?string $status = null
    ) {
    }

    public static function fromEntity(Event $event): self
    {
        return new self(
            id: $event->id()->value(),
            uid: $event->uid(),
            name: $event->name(),
            description: $event->description(),
            location: $event->location(),
            start: $event->start()->format(\DateTimeInterface::ATOM),
            duration: $event->duration(),
            createdBy: $event->createdBy(),
            type: $event->type()->value,
            access: $event->access()->value,
            rrule: $event->recurrence()->rule()?->toString(),
            sequence: $event->sequence(),
            status: $event->status()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'uid' => $this->uid,
            'name' => $this->name,
            'description' => $this->description,
            'location' => $this->location,
            'start' => $this->start,
            'duration' => $this->duration,
            'createdBy' => $this->createdBy,
            'type' => $this->type,
            'access' => $this->access,
            'rrule' => $this->rrule,
            'sequence' => $this->sequence,
            'status' => $this->status,
        ];
    }
}
