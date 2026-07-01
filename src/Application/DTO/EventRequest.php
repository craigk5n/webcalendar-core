<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\DTO;

/**
 * Data Transfer Object for Event creation/update requests.
 */
final class EventRequest
{
    public function __construct(
        public readonly string $name,
        public readonly string $start,
        public readonly int $duration,
        public readonly string $description = '',
        public readonly string $location = '',
        public readonly string $type = 'E',
        public readonly string $access = 'P',
        public readonly ?string $rrule = null,
        public readonly ?string $status = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            start: is_string($data['start'] ?? null) ? $data['start'] : '',
            duration: is_numeric($data['duration'] ?? null) ? (int)$data['duration'] : 0,
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            location: is_string($data['location'] ?? null) ? $data['location'] : '',
            type: is_string($data['type'] ?? null) ? $data['type'] : 'E',
            access: is_string($data['access'] ?? null) ? $data['access'] : 'P',
            rrule: is_string($data['rrule'] ?? null) ? $data['rrule'] : null,
            status: is_string($data['status'] ?? null) ? $data['status'] : null
        );
    }
}
