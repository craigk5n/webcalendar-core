<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Enumeration of event types supported by WebCalendar.
 */
enum EventType: string
{
    case EVENT = 'E';
    case REPEATING_EVENT = 'M';
    case TASK = 'T';
    case JOURNAL = 'J';
    case REPEATING_TASK = 'N';
    case REPEATING_JOURNAL = 'O';

    /**
     * Checks if the type represents a repeating entry.
     */
    public function isRepeating(): bool
    {
        return in_array($this, [self::REPEATING_EVENT, self::REPEATING_TASK, self::REPEATING_JOURNAL], true);
    }
}
