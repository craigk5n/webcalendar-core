<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Enumeration of access levels for calendar entries.
 */
enum AccessLevel: string
{
    case PUBLIC = 'P';
    case CONFIDENTIAL = 'C';
    case PRIVATE = 'R';
}
