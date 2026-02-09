# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WebCalendar is a multi-user PHP calendar application (legacy v1.9.13) being rewritten as a modern PHP 8.1+ system. This repository (`webcalendar-core`) is the **core business logic library** — a Composer package containing domain models, services, and repository interfaces with **zero** UI code.

The full ecosystem is three projects:
- **webcalendar-core** (this repo) — Pure PHP business logic, domain models, services, repository interfaces
- **webcalendar-web** — Standalone frontend (React SPA or Bootstrap+PHP) consuming the REST API
- **webcalendar-wp** — WordPress plugin using core as a Composer dependency

The `legacy/` directory contains the original monolithic PHP application **for reference only** during migration. It is excluded from git (see `.gitignore`) and must not be used as a dependency or imported into the new codebase.

## Development Commands

```bash
# Install dependencies
composer install

# Run all unit tests (from legacy directory for legacy tests)
cd legacy && ./vendor/bin/phpunit -c tests/phpunit.xml

# Run a single test file
cd legacy && ./vendor/bin/phpunit tests/functionsTest.php

# Verify all PHP files compile
cd legacy && tests/compile_test.sh

# Run individual legacy tests with bootstrap
cd legacy && ./vendor/bin/phpunit --bootstrap includes/functions.php tests/functionsTest.php

# Code style (legacy)
cd legacy && ./vendor/bin/php-cs-fixer fix
cd legacy && ./vendor/bin/phpcs

# Copy vendor assets to proper locations (Linux only)
cd legacy && make
```

## Architecture

### Target Namespace Structure (webcalendar-core)

```
WebCalendar\Core\
├── Domain\
│   ├── Entity\          # Event, User, Category, Group, Layer, Reminder, Resource
│   ├── ValueObject\     # EventId, DateRange, RecurrenceRule, AccessLevel, EventType
│   └── Repository\      # Interfaces only (no implementations)
├── Application\
│   ├── Service\         # EventService, UserService, PermissionService, etc.
│   ├── DTO\             # Request/Response objects
│   └── Contract\        # Interfaces for external dependencies
├── Infrastructure\
│   ├── Persistence\     # Repository implementations
│   └── ICal\            # RFC 5545 handling via craigk5n/php-icalendar-core
└── Contract\            # API contracts, OpenAPI specs
```

### Key Design Principles
- **Constructor injection** for all service dependencies — no global state
- **Repository pattern** with interfaces in Domain, implementations in Infrastructure
- **Contract interfaces** for external deps: `AuthenticationProvider`, `DatabaseConnection`, `Logger` (PSR-3)
- All services are **stateless**
- WordPress integration via bridge classes (WpAuthenticationProvider, WpDatabaseConnection, WpLogger)

### REST API
- All endpoints under `/api/v2/`
- JSON responses with consistent envelope format
- Auth: session-based (Cookie+CSRF) for browsers, Bearer token for API clients, X-API-Key for MCP
- OpenAPI 3.0 spec in `Contract/openapi.yaml`

## Database Conventions

- All tables use `webcal_` prefix (WordPress: `{wp_prefix}webcal_`)
- Column naming: `cal_` prefix for most tables, `cat_` for category tables
- Dates stored as integers: YYYYMMDD format
- Times stored as integers: HHMMSS format (-1 = untimed/all-day)
- Event types: E=Event, M=Repeating, T=Task, J=Journal, N=Repeating Task, O=Repeating Journal
- Access levels: P=Public, C=Confidential, R=Private
- Schema SQL files: `legacy/wizard/shared/tables-{mysql,postgres,sqlite3}.sql`
- Database changes must include migration SQL for MySQL, PostgreSQL, and SQLite3

Key tables: `webcal_entry` (events), `webcal_entry_user` (participants), `webcal_entry_repeats` (recurrence), `webcal_categories`, `webcal_user`, `webcal_config` (system settings), `webcal_entry_log` (audit).

## Code Standards

- PHP 8.1+ with `declare(strict_types=1)` on all new files
- PSR-12 compliant
- 2-space indentation (not tabs)
- 80-character line length
- PHPUnit 9.x for testing, target 90%+ branch coverage on core services
- PHPStan Level 8+ (no errors allowed)

## Legacy Codebase Reference (legacy/)

**Important:** The `legacy/` directory is **for reference only**. It contains the original WebCalendar v1.9.13 codebase and is excluded from git commits via `.gitignore`. Do not import, require, or use any files from this directory in the new codebase.

The legacy app's business logic lives primarily in:
- `includes/functions.php` (~6600 lines) — Core utility functions, event queries, user/preference loading
- `includes/init.php` — Bootstrap file included by most pages
- `includes/dbi4php.php` — Database abstraction supporting mysqli, PostgreSQL, SQLite3, Oracle, ODBC, etc.
- `includes/access.php` — Permission system with function-level access control
- `includes/classes/Event.php`, `RptEvent.php` — Event models
- `includes/classes/WebCalendar.php` — Main calendar class
- `ws/` — Existing web service endpoints (get_events, event_mod, login, etc.)

## iCalendar Integration

The `craigk5n/php-icalendar-core` Composer package (namespace `Icalendar\`) handles RFC 5545 parsing, generation, and recurrence expansion. **It is not used as the domain model** — webcalendar-core has its own `Event`, `User`, etc. entities for business logic and persistence.

**Hybrid approach:** Own domain entities + mapper layer + php-icalendar-core for I/O:
- `Infrastructure\ICal\EventMapper` — translates `Event` ↔ `VEvent`
- `Infrastructure\ICal\TaskMapper` — translates `Event` (task) ↔ `VTodo`
- `Infrastructure\ICal\JournalMapper` — translates `Event` (journal) ↔ `VJournal`

**Use php-icalendar-core for:**
- Import: `Parser::parse()` → VEvent → `EventMapper::fromVEvent()` → domain entity
- Export: domain entity → `EventMapper::toVEvent()` → `Writer::write()` → ICS string
- Recurrence: `RecurrenceService` builds a VEvent internally, uses `RecurrenceExpander` for RFC 5545-correct date expansion, maps results back to domain dates
- Validation: `$component->validate()` before import

**Do NOT use php-icalendar-core for:** domain entity storage, business logic, permission checks, or anything that touches the database directly.

## PRD Reference

`PRD.md` is the requirements document for this project (webcalendar-core only; frontend and WordPress plugin are out of scope). It has 31 sections plus 7 appendices. Sections are marked:
- **CURRENT:** Legacy behavior to preserve
- **TARGET:** Modern implementation goals
- **NEW:** Features to build from scratch

Key sections for implementation:
- Section 3: Namespace structure, core services, dependency injection, contracts
- Sections 5-23: Feature-specific data models, business logic, and API contracts
- Section 27: Complete REST API endpoint definitions
- Section 29: Database support and PDO migration
- Section 31: Database schema reference
- Appendix F: RFC 5545 gap analysis
- Appendix G: 47 user stories across 9 epics (Epics 1-9), with dependency graph and implementation order

Each section includes explicit data models with field names/types. Match these exactly when implementing. User stories follow format `S-{epic}.{sequence}` with sizes S/M/L/XL and priorities P0 (MVP) / P1 (parity) / P2 (enhancement).
