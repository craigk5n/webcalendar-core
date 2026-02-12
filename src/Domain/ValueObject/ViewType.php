<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Enumeration of Custom View types.
 */
enum ViewType: string
{
    case DAY = 'D';
    case WEEK = 'W';
    case MONTH = 'M';
}
