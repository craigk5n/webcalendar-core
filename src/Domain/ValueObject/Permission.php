<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Enumeration of system functions for Access Control (UAC).
 * 
 * Based on the 28 legacy function IDs defined in PRD Section 9.2.
 */
enum Permission: int
{
    case EVENT_VIEW = 0;
    case EVENT_EDIT = 1;
    case DAY_VIEW = 2;
    case WEEK_VIEW = 3;
    case MONTH_VIEW = 4;
    case YEAR_VIEW = 5;
    case ADMIN_HOME = 6;
    case REPORT = 7;
    case VIEW = 8;
    case VIEW_MANAGEMENT = 9;
    case CATEGORY_MANAGEMENT = 10;
    case LAYERS = 11;
    case SEARCH = 12;
    case ADVANCED_SEARCH = 13;
    case ACTIVITY_LOG = 14;
    case USER_MANAGEMENT = 15;
    case ACCOUNT_INFO = 16;
    case ACCESS_MANAGEMENT = 17;
    case PREFERENCES = 18;
    case SYSTEM_SETTINGS = 19;
    case IMPORT = 20;
    case EXPORT = 21;
    case PUBLISH = 22;
    case ASSISTANTS = 23;
    case TRAILER = 24;
    case HELP = 25;
    case ANOTHER_CALENDAR = 26;
    case SECURITY_AUDIT = 27;
}
