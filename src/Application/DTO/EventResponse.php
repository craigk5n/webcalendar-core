<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\DTO;

use WebCalendar\Core\Domain\Entity\Event;

/**
 * Data Transfer Object for Event responses.
 */
final readonly class EventResponse implements \JsonSerializable
{
    public function __construct(
        public int $id,
        public string $uid,
        public string $name,
        public string $description,
        public string $location,
        public string $start,
        public int $duration,
        public string $createdBy,
        public string $type,
        public string $access,
        public ?string $rrule = null,
        public int $sequence = 0,
        public ?string $status = null
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
