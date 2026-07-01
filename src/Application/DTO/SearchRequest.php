<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\DTO;

/**
 * Data Transfer Object for Search requests.
 */
final class SearchRequest
{
    public function __construct(
        public readonly string $query,
        public readonly ?string $start = null,
        public readonly ?string $end = null,
        public readonly ?int $categoryId = null,
        public readonly ?string $user = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $q = $data['q'] ?? $data['query'] ?? '';
        return new self(
            query: is_string($q) ? $q : '',
            start: is_string($data['start'] ?? null) ? $data['start'] : null,
            end: is_string($data['end'] ?? null) ? $data['end'] : null,
            categoryId: is_numeric($data['category'] ?? null) ? (int)$data['category'] : null,
            user: is_string($data['user'] ?? null) ? $data['user'] : null
        );
    }
}
