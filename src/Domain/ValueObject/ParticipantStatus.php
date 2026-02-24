<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Enumeration of participant statuses for calendar events.
 */
enum ParticipantStatus: string
{
    case ACCEPTED = 'A';
    case COMPLETED = 'C';
    case DELETED = 'D';
    case IN_PROGRESS = 'P';
    case REJECTED = 'R';
    case WAITING = 'W';

    /**
     * Checks if the participant is actively on the event.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::ACCEPTED, self::COMPLETED, self::IN_PROGRESS, self::WAITING], true);
    }
}
