# WebCalendar-Core Usage Guide

**Version:** 4.0  
**Target Audience:** Developers & AI Coding Agents  
**Project Scope:** Business Logic only (Zero UI).

## 1. Architecture Overview

`webcalendar-core` is a PHP 8.1+ library following **Clean Architecture**.

- **Domain Layer (`src/Domain`)**: Immutable Entities and Value Objects. Framework agnostic.
- **Application Layer (`src/Application`)**: Stateless Services orchestrating logic. Dependencies are injected via interfaces.
- **Infrastructure Layer (`src/Infrastructure`)**: Concrete implementations (PDO Repositories, ICal Mappers, Security utilities).

### Key Constraints for AI Agents
- **Statelessness**: Services do not hold state. Pass the `User` object into methods requiring context.
- **Immutability**: Value Objects (e.g., `EventId`, `DateRange`) are final and immutable.
- **Strict Typing**: All methods use strict types. `declare(strict_types=1)` is mandatory.

---

## 2. Integrating with WordPress

To use this library in a WordPress plugin (`webcalendar-wp`), you must implement the contract interfaces to bridge WordPress globals into the library.

### 2.1 Implementing Auth and User Bridge
Core depends on `AuthServiceInterface` and `UserRepositoryInterface`.

```php
use WebCalendar\Core\Application\Contract\AuthServiceInterface;
use WebCalendar\Core\Domain\Repository\UserRepositoryInterface;
use WebCalendar\Core\Domain\Entity\User;

class WpUserRepository implements UserRepositoryInterface {
    public function findByLogin(string $login): ?User {
        $wp_user = get_user_by('login', $login);
        if (!$wp_user) return null;
        return new User(
            $wp_user->user_login,
            $wp_user->first_name,
            $wp_user->last_name,
            $wp_user->user_email,
            current_user_can('manage_options')
        );
    }
    // ... implement other methods using $wpdb
}
```

### 2.2 Implementing Database Persistence
While the core provides `PdoUserRepository`, WordPress usually prefers `$wpdb`. You can either:
1.  **Use provided Repositories**: Instantiate `PdoEventRepository` with a `PDO` connection to the WP database.
2.  **Create WP-specific Repositories**: Implement `EventRepositoryInterface` using `$wpdb->prepare()`.

---

## 3. Core Service Registry

All operations should flow through these services. **AI Agent Tip**: Use constructor injection to access these.

| Service | Primary Purpose | Key Methods |
| :--- | :--- | :--- |
| **EventService** | Event CRUD & Approval | `getEventsInDateRange()`, `createEvent()`, `approveEvent()` |
| **TaskService** | VTODO management | `getTasksInDateRange()`, `updateTask()` |
| **RecurrenceService** | RRULE expansion | `expand(Event $event, DateRange $range)` |
| **BookingService** | Availability & Scheduling | `calculateAvailability()`, `createBooking()` |
| **NotificationService**| Email & Webhooks | `sendReminder()`, `notifyParticipants()` |
| **SecurityService** | JWT & CSRF Tokens | `generateToken()`, `validateCsrfToken()` |
| **SearchService** | Keyword & Filtered search| `search(string $keyword, ?DateRange $range)` |
| **TranslationService** | i18n support | `translate(string $key)`, `resetLanguage()` |

---

## 4. Common Operations (How-To)

### 4.1 Fetching Events for a Month View
```php
$eventService = new EventService($eventRepository);
$recurrenceService = new RecurrenceService();

$range = new DateRange(new DateTimeImmutable('first day of this month'), new DateTimeImmutable('last day of this month'));
$events = $eventService->getEventsInDateRange($range, $currentUser);

// Important: Recurring events must be expanded
foreach ($events as $event) {
    if ($event->recurrence()->isRepeating()) {
        $occurrences = $recurrenceService->expand($event, $range);
        // Map occurrences to UI
    }
}
```

### 4.2 Creating a Recurring Event (RFC 5545)
```php
use WebCalendar\Core\Domain\ValueObject\RecurrenceRule;
use WebCalendar\Core\Domain\ValueObject\Recurrence;

$rrule = new RecurrenceRule("FREQ=WEEKLY;BYDAY=MO,WE,FR;UNTIL=20261231T235959Z");
$recurrence = new Recurrence($rrule);

$event = new Event(
    new EventId(0), // 0 for new
    bin2hex(random_bytes(16)), // UID
    "Team Sync",
    "Weekly meeting",
    "Zoom",
    new DateTimeImmutable('2026-02-16 10:00:00'),
    60,
    "admin",
    EventType::EVENT,
    AccessLevel::PUBLIC,
    $recurrence
);

$eventService->createEvent($event);
```

---

## 5. Model Context Protocol (AI Integration)

The library includes an `McpToolHandler` designed for AI assistants. If your WordPress plugin exposes an MCP endpoint, delegate the tools here:

- `list_events`: Lists events in a range.
- `search_events`: Finds events by keyword.
- `add_event`: Creates a simple event from AI prompt data.
- `get_user_info`: Returns profile of the connected user.

```php
$handler = new McpToolHandler($eventService, $searchService, $userService);
$result = $handler->handle('add_event', $argsFromAI, $currentUser);
```

---

## 6. Data Mapping Table

For database operations, refer to these legacy-to-domain mappings:

| Table | Domain Entity | PK | Logic |
| :--- | :--- | :--- | :--- |
| `webcal_user` | `User` | `cal_login` | Auth & Preferences |
| `webcal_entry` | `Event` / `Task` | `cal_id` | Shared table, diff by `cal_type` |
| `webcal_entry_repeats` | `RecurrenceRule`| `cal_id` | RFC 5545 RRULE storage |
| `webcal_entry_log` | `ActivityLogEntry`| `cal_log_id`| Audit trail |
| `webcal_blob` | `Blob` | `cal_blob_id`| Attachments & Comments |

---

## 7. Quality Standards
- **PHPStan**: Must pass Level 9.
- **PHPUnit**: Core logic should maintain 100% coverage.
- **Dates**: Always use `DateTimeImmutable`.
- **Primary Keys**: Use `EventId` value object instead of raw integers.
