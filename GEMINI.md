# WebCalendar Core - Gemini Context

## Project Overview

**webcalendar-core** is a PHP 8.1+ library that serves as the business logic core for the WebCalendar ecosystem. It is a modern rewrite of a legacy PHP application (v1.9.13).

*   **Type:** PHP Library (Composer Package)
*   **Purpose:** Provides domain models, services, repository interfaces, and RFC 5545 iCalendar handling.
*   **Scope:** **Zero UI, Zero HTTP.** This library contains *only* business logic. REST API lives in `webcalendar-api`, frontend in `webcalendar-web`, WordPress plugin in `webcalendar-wp`.
*   **Status:** Active development / Refactoring.

## Architecture

The project follows a clean architecture pattern:

*   **Domain Layer (`src/Domain`):** Entities (`Event`, `User`), Value Objects, and Repository Interfaces. This layer is framework-agnostic.
*   **Application Layer (`src/Application`):** Stateless Services (`EventService`, `UserService`) that orchestrate business logic.
*   **Infrastructure Layer (`src/Infrastructure`):** Implementations of repositories and external integrations (e.g., iCalendar mapping).
*   **iCalendar Support:** Uses `craigk5n/php-icalendar-core` for parsing/generating RFC 5545 data, but maps this to internal Domain Entities for persistence.

## Key Files & Directories

*   **`src/`**: Source code (PSR-4 `WebCalendar\Core\`).
*   **`tests/`**: Unit and integration tests.
*   **`legacy/`**: Contains the original WebCalendar v1.9.13 codebase. **READ-ONLY REFERENCE.** Do not import or depend on code here.
*   **`PRD.md`**: The comprehensive Product Requirements Document. **Source of Truth** for features and data models.
*   **`STATUS.md`**: Tracking current tasks and epics.
*   **`composer.json`**: Dependency management and script definitions.

## Building and Running

This is a library, so "running" primarily means executing tests and static analysis.

### Prerequisites
*   PHP >= 8.1
*   Composer

### Commands

| Action | Command | Description |
| :--- | :--- | :--- |
| **Install Dependencies** | `composer install` | Installs prod and dev dependencies. |
| **Run Tests** | `composer test` | Runs PHPUnit tests. |
| **Static Analysis** | `composer phpstan` | Runs PHPStan (Level 8). |
| **Style Check** | `composer cs-check` | Checks code style (PSR-12). |
| **Style Fix** | `composer cs-fix` | Automatically fixes code style issues. |

## Integration Testing with SQLite

For infrastructure layer tests (repositories, etc.), we use an in-memory SQLite database.

*   **Schema File:** `src/Infrastructure/Persistence/sqlite-schema.sql`
*   **Target Columns:** This schema includes all legacy columns plus new columns for RFC 5545 compliance as defined in `PRD.md`.
*   **Usage:** Use `PDO('sqlite::memory:')` and execute the schema SQL in the `setUp()` method of your integration test cases.

## Development Conventions

1.  **Strict Types:** All new PHP files must start with `declare(strict_types=1);`.
2.  **No Global State:** Use constructor injection for all dependencies.
3.  **Database:**
    *   Tables use `webcal_` prefix.
    *   Dates are stored as `YYYYMMDD` integers.
    *   Times are stored as `HHMMSS` integers.
4.  **Legacy Code:** The `legacy/` folder is strictly for understanding existing behavior. **Do not modify it** unless explicitly instructed for a specific reason (e.g., fixing a reference implementation).

## Reference Documentation

*   **Data Models:** See `PRD.md` (Sections 5-31) for exact field names and types.
*   **API Contracts:** See `PRD.md` (Section 27) and `API.md` for reference. OpenAPI spec and implementation belong in `webcalendar-api`.
*   **User Stories:** See `PRD.md` (Appendix G). Note: Epic 3 (REST API) belongs in `webcalendar-api`.
