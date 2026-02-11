<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Value object representing a single user preference.
 */
final readonly class UserPreference
{
    public function __construct(
        private string $key,
        private string $value
    ) {
        if (empty(trim($this->key))) {
            throw new \InvalidArgumentException('Preference key cannot be empty.');
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function value(): string
    {
        return $this->value;
    }
}
