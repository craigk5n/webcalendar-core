<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\DTO;

use WebCalendar\Core\Domain\Entity\Group;

/**
 * Data Transfer Object for Group responses.
 */
final readonly class GroupResponse implements \JsonSerializable
{
    public function __construct(
        public int $id,
        public string $owner,
        public string $name,
        public string $lastUpdate
    ) {
    }

    public static function fromEntity(Group $group): self
    {
        return new self(
            id: $group->id(),
            owner: $group->owner(),
            name: $group->name(),
            lastUpdate: $group->lastUpdate()->format(\DateTimeInterface::ATOM)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->owner,
            'name' => $this->name,
            'lastUpdate' => $this->lastUpdate,
        ];
    }
}
