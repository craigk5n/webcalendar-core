<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Enumeration of Activity Log type codes.
 */
enum ActivityLogType: string
{
    case CREATE = 'C';
    case APPROVE = 'A';
    case REJECT = 'R';
    case UPDATE = 'U';
    case NOTIFICATION = 'M';
    case REMINDER = 'E';
    case EXTRA = 'X';
}
