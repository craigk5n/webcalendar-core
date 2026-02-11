<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

use Icalendar\Recurrence\RRule;

/**
 * Value object representing an RFC 5545 Recurrence Rule (RRULE).
 * 
 * Wraps the php-icalendar-core RRule class to provide domain-specific
 * recurrence logic while maintaining immutability.
 */
final readonly class RecurrenceRule
{
    private RRule $rrule;

    /**
     * @param string $rruleString Standard RRULE string (e.g. "FREQ=WEEKLY;INTERVAL=2")
     * @throws \InvalidArgumentException If the RRULE string is invalid.
     */
    public function __construct(string $rruleString)
    {
        try {
            $this->rrule = RRule::parse($rruleString);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid RRULE string: ' . $rruleString, 0, $e);
        }
    }

    /**
     * Creates a RecurrenceRule from an array of parts.
     * 
     * @param array<string, mixed> $parts
     */
    public static function fromParts(array $parts): self
    {
        $stringParts = [];
        foreach ($parts as $key => $value) {
            $stringParts[] = strtoupper($key) . '=' . $value;
        }
        
        return new self(implode(';', $stringParts));
    }

    /**
     * Returns the RRULE as a standard RFC 5545 string.
     */
    public function toString(): string
    {
        return $this->rrule->toString();
    }

    /**
     * Proxies to the underlying RRule object.
     */
    public function getRRule(): RRule
    {
        return $this->rrule;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
