<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\DTO;

/**
 * Data Transfer Object for Event creation/update requests.
 */
final readonly class EventRequest
{
    public function __construct(
        public string $name,
        public string $start,
        public int $duration,
        public string $description = '',
        public string $location = '',
        public string $type = 'E',
        public string $access = 'P',
        public ?string $rrule = null,
        public ?string $status = null
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
