<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\DTO;

use WebCalendar\Core\Domain\Entity\Category;

/**
 * Data Transfer Object for Category responses.
 */
final readonly class CategoryResponse implements \JsonSerializable
{
    public function __construct(
        public int $id,
        public ?string $owner,
        public string $name,
        public ?string $color,
        public bool $enabled
    ) {
    }

    public static function fromEntity(Category $category): self
    {
        return new self(
            id: $category->id(),
            owner: $category->owner(),
            name: $category->name(),
            color: $category->color(),
            enabled: $category->isEnabled()
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
            'color' => $this->color,
            'enabled' => $this->enabled,
        ];
    }
}
