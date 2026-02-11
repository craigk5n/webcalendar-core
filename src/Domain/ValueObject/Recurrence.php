<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Value object aggregating all recurrence-related information for an event.
 */
final readonly class Recurrence
{
    public function __construct(
        private ?RecurrenceRule $rule = null,
        private ExDate $exDate = new ExDate(),
        private RDate $rDate = new RDate()
    ) {
    }

    public function rule(): ?RecurrenceRule
    {
        return $this->rule;
    }

    public function exDate(): ExDate
    {
        return $this->exDate;
    }

    public function rDate(): RDate
    {
        return $this->rDate;
    }

    public function isRepeating(): bool
    {
        return $this->rule !== null || !$this->rDate->isEmpty();
    }
}
