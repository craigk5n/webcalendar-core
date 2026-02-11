<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Value object representing a list of Exception Dates (EXDATE).
 */
final readonly class ExDate
{
    /**
     * @param \DateTimeImmutable[] $dates
     */
    public function __construct(
        private array $dates = []
    ) {
    }

    /**
     * @return \DateTimeImmutable[]
     */
    public function dates(): array
    {
        return $this->dates;
    }

    public function isEmpty(): bool
    {
        return empty($this->dates);
    }
}
