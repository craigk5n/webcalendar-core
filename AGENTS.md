# AGENTS.md

Guidelines for AI coding agents working on webcalendar-core — a PHP 8.1+ business logic library (zero UI).

## Important: Legacy Code Reference

The `legacy/` directory contains the original WebCalendar codebase (v1.9.13) and is **for reference only**. It is:
- Listed in `.gitignore` and excluded from git commits
- Used to understand existing business logic during migration
- Not to be imported, required, or used in the new codebase
- Not a dependency of this package

This project is a clean rewrite using Composer for dependency management.

## Quick Facts

- **Language**: PHP 8.1+ with `declare(strict_types=1)`
- **Type**: Composer package for domain logic, services, repository interfaces
- **Style**: PSR-12, 2-space indentation, 80-character line length
- **Testing**: PHPUnit 9.x, target 90%+ branch coverage
- **Analysis**: PHPStan Level 8+ (zero errors)

## Architecture Rules

### Namespace Structure
```
WebCalendar\Core\
├── Domain\Entity\          # Event, User, Category, Group, etc.
├── Domain\ValueObject\     # EventId, DateRange, RecurrenceRule
├── Domain\Repository\      # Interfaces ONLY
├── Application\Service\     # EventService, UserService, etc.
├── Application\DTO\         # Request/Response objects
├── Application\Contract\    # External dependency interfaces
├── Infrastructure\Persistence\  # Repository implementations
└── Infrastructure\ICal\     # RFC 5545 mappers
```

### Design Principles
- **Constructor injection** for all dependencies — no global state
- **Repository pattern**: interfaces in Domain, implementations in Infrastructure
- **Contract interfaces** for external deps: `AuthenticationProvider`, `DatabaseConnection`, `Logger` (PSR-3)
- Services are **stateless** and **single-responsibility**

## Code Standards

```php
<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Event;

final class EventService
{
    public function __construct(
        private readonly EventRepositoryInterface $repository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function createEvent(CreateEventDTO $dto): Event
    {
        // Implementation
    }
}
```

- Use `final` for classes not intended for extension
- Use constructor property promotion with `readonly`
- Return type declarations required
- No mixed return types — be explicit

## Database Conventions

- Table prefix: `webcal_` (WordPress: `{wp_prefix}webcal_`)
- Columns: `cal_*` for entry tables, `cat_*` for category tables
- Dates: integers as `YYYYMMDD`
- Times: integers as `HHMMSS` (-1 = all-day)
- Event types: `E`=Event, `M`=Repeating, `T`=Task, `J`=Journal, `N`=Repeating Task, `O`=Repeating Journal
- Access levels: `P`=Public, `C`=Confidential, `R`=Private
- Schema files: `legacy/wizard/shared/tables-{mysql,postgres,sqlite3}.sql`

## iCalendar Integration

Use `craigk5n/php-icalendar-core` (namespace `Icalendar\`) for RFC 5545 operations:

- **Use for**: parsing, generation, recurrence expansion, validation
- **Do NOT use for**: domain storage, business logic, permission checks, database access

Hybrid approach:
- Domain entities ↔ Mapper layer ↔ php-icalendar-core components
- Example: `EventMapper::toVEvent($event)` or `EventMapper::fromVEvent($vevent)`

## Development Commands

```bash
# Dependencies
composer install

# Testing (run from legacy/ for legacy tests)
TBD

# Code style (legacy)
TBD: php-cs-fixer, phpcs ?

# Compile check
TBD: verify no PHP syntax errors

## Key Files Reference

- `PRD.md` — Requirements document (31 sections, 7 appendices)
- `API.md` — REST API contract reference (implementation belongs in `webcalendar-api`)
- `legacy/includes/functions.php` — Legacy business logic reference (for reference only, see below)
- `legacy/wizard/shared/tables-*.sql` — Database schema (for reference only)
- `legacy/docs/WebCalendar-Database.md` - Database schema documentation (for reference only)

**Note:** All files in `legacy/` are from the original WebCalendar application and are for reference only. Do not import or use them in the new codebase.

**Note:** REST API controllers, middleware, and OpenAPI specs belong in the `webcalendar-api` project, not here.

## What NOT to Do

1. No UI code (HTML, CSS, JS) — this is a library
2. No framework dependencies (Laravel, Symfony, etc.)
3. No direct database access from domain entities
4. No global state or static service access
5. No mixing php-icalendar-core with domain persistence

## Testing Requirements

- Unit tests for all services and mappers
- Mock external dependencies (repositories, loggers)
- Test edge cases: nulls, empty collections, boundary dates
- 90%+ branch coverage on core services
