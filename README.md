# WebCalendar Core

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHPStan Level 9](https://img.shields.io/badge/phpstan-level%209-brightgreen.svg)](https://phpstan.org/)
[![PHPUnit Tests](https://github.com/craigk5n/webcalendar-core/actions/workflows/tests.yml/badge.svg)](https://github.com/craigk5n/webcalendar-core/actions)
[![codecov](https://codecov.io/gh/craigk5n/webcalendar-core/graph/badge.svg)](https://codecov.io/gh/craigk5n/webcalendar-core)

Pure PHP 8.2+ business logic library for [WebCalendar](https://www.k5n.us/webcalendar/), a multi-user calendar and scheduling application. This package provides domain models, application services, and repository interfaces with **zero UI code** -- designed to be consumed by any frontend or framework.

## Architecture

This library follows Clean Architecture principles with strict layer separation:

```
WebCalendar\Core\
├── Domain\
│   ├── Entity\          # Event, User, Category, Group, Layer, Task, Journal, ...
│   ├── ValueObject\     # EventId, DateRange, RecurrenceRule, AccessLevel, ...
│   └── Repository\      # Interfaces only (17 repository contracts)
├── Application\
│   ├── Service\         # 26 stateless services (EventService, UserService, ...)
│   ├── DTO\             # Request/Response objects
│   └── Contract\        # AuthService, EmailProvider, WebhookProvider
└── Infrastructure\
    ├── Persistence\     # PDO repository implementations (MySQL, PostgreSQL, SQLite)
    └── ICal\            # RFC 5545 import/export via craigk5n/php-icalendar-core
```

All services use constructor injection and are stateless. Repository interfaces live in the Domain layer; implementations live in Infrastructure.

## Ecosystem

This library is part of a ground-up rewrite of [WebCalendar](https://github.com/craigk5n/webcalendar), replacing the legacy monolithic PHP application with a modern, layered architecture.

| Project | Status | Purpose |
|---------|--------|---------|
| [**webcalendar**](https://github.com/craigk5n/webcalendar) | Legacy | Original monolithic PHP application |
| **webcalendar-core** (this repo) | In development | Business logic library (Composer package) |
| **webcalendar-web** | Planned | Standalone frontend consuming the REST API |
| **webcalendar-wp** | In development | WordPress plugin using core as a dependency |

## Requirements

- PHP 8.2 or higher
- PDO extension (with driver for your database)
- One of: MySQL 5.7+ / MariaDB 10.3+, PostgreSQL 12+, or SQLite 3.25+

## Installation

```bash
composer require craigk5n/webcalendar-core
```

## Quick Start

```php
<?php

declare(strict_types=1);

use WebCalendar\Core\Application\Service\EventService;
use WebCalendar\Core\Application\Service\PermissionService;
use WebCalendar\Core\Infrastructure\Persistence\PdoEventRepository;

// Set up your PDO connection
$pdo = new PDO('mysql:host=localhost;dbname=webcalendar', 'user', 'pass');

// Wire up dependencies
$eventRepository = new PdoEventRepository($pdo);
$permissionService = new PermissionService(/* ... */);
$eventService = new EventService($eventRepository, $permissionService);

// Fetch events for a date range
$events = $eventService->getEventsForDateRange(
  calendarId: 1,
  startDate: 20260201,
  endDate: 20260228,
  login: 'admin'
);
```

## Development

### Setup

```bash
git clone https://github.com/craigk5n/webcalendar-core.git
cd webcalendar-core
composer install
```

### Running Tests

```bash
# All tests
composer test

# Unit tests only
./vendor/bin/phpunit --testsuite Unit

# Integration tests only
./vendor/bin/phpunit --testsuite Integration

# With coverage report
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text
```

### Static Analysis

```bash
# PHPStan (Level 9)
composer phpstan

# Code style check
composer cs-check

# Auto-fix code style
composer cs-fix
```

### Code Standards

- `declare(strict_types=1)` on all files
- PSR-12 coding style
- PHPStan Level 9 with zero errors
- 2-space indentation, 80-character line length

## Database

Schema files for all three supported databases are included:

- `src/Infrastructure/Persistence/mysql-schema.sql`
- `src/Infrastructure/Persistence/postgresql-schema.sql`
- `src/Infrastructure/Persistence/sqlite-schema.sql`

All tables use the `webcal_` prefix. Dates are stored as integers in `YYYYMMDD` format; times as `HHMMSS` (-1 for untimed/all-day events).

## iCalendar Support

RFC 5545 import/export is handled via [`craigk5n/php-icalendar-core`](https://github.com/craigk5n/php-icalendar-core). The library uses its own domain entities for business logic and persistence, with a mapper layer for iCal I/O:

- **Import:** ICS string &rarr; `Parser::parse()` &rarr; `EventMapper::fromVEvent()` &rarr; domain entity
- **Export:** domain entity &rarr; `EventMapper::toVEvent()` &rarr; `Writer::write()` &rarr; ICS string
- **Recurrence:** RFC 5545-correct expansion via `RecurrenceService`

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Ensure tests pass (`composer test`)
4. Ensure static analysis passes (`composer phpstan`)
5. Submit a pull request

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE) for details.
