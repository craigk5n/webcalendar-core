# STATUS.md

## Task States
- **TODO** - Not started
- **IN_PROGRESS** - Currently being worked on
- **BLOCKED** - Cannot proceed due to external dependencies
- **REVIEW** - Ready for code review
- **DONE** - Completed and merged

## Epics & Tasks

### Epic 1: Foundation & Core Infrastructure
**Status: DONE**  
**Goal:** Set up project foundation with core business logic layer

#### Task 1.1: Project Structure & Dependencies
**Status: DONE**  
**Task:** Set up Composer project with proper dependencies and autoloading

**Acceptance Criteria:**
- [x] Composer.json created with MIT license
- [x] Dependencies configured (php-icalendar-core, psr/log)
- [x] PSR-4 autoloading configured for src/ and tests/
- [x] Development dependencies configured (PHPUnit, PHPStan, PHP-CS-Fixer)

#### Task 1.2: Domain Layer Foundation
**Status: DONE**  
**Task:** Create core domain entities and value objects

**As a developer,**  
I want a solid domain foundation with Event, User, and core value objects  
so that I can build business logic on a strong type-safe foundation.

**One-sentence goal:** Create immutable domain entities and value objects with proper validation.

**Current situation:** No domain entities exist yet

**Desired outcome:** Event, User, EventId, DateRange, and basic value objects implemented

**Out of scope:** Repository implementations, services, and API endpoints

**Technical constraints:**
- PHP 8.1+, strict_types=1
- Immutable objects with proper validation
- Value objects must be final and immutable
- Domain logic must be framework/DB agnostic
- **TDD Required:** Write failing tests first, then implement.

**Testing Requirements:**
- Unit tests in `tests/Unit/Domain/`
- 100% coverage for Value Objects and Entities
- Test boundary conditions (invalid dates, negative IDs, etc.)

**Key files to change:**
- src/Domain/Entity/Event.php
- src/Domain/Entity/User.php
- src/Domain/ValueObject/EventId.php
- src/Domain/ValueObject/DateRange.php
- tests/Unit/Domain/

**Acceptance Criteria:**
**Scenario: Event creation with valid data**  
Given valid event data  
When Event is created  
Then all properties are set correctly  
And Event is immutable

**Scenario: Invalid date range**  
Given start date after end date  
When DateRange is created  
Then InvalidArgumentException is thrown

**Definition of Done:**
- [ ] All domain entities and value objects implemented
- [ ] 95%+ test coverage on domain layer
- [ ] PHPStan level 9 passes
- [ ] All public APIs have strict types and PHPDoc
- [ ] No framework dependencies in domain layer

#### Task 1.3: Repository Interfaces
**Status: DONE**  
**Task:** Define repository interfaces for data access

**As a developer,**  
I want repository interfaces for Event and User entities  
so that I can implement persistence without coupling to specific databases.

**One-sentence goal:** Create clean repository interfaces for data access operations.

**Current situation:** No repository interfaces exist

**Desired outcome:** EventRepositoryInterface and UserRepositoryInterface defined

**Out of scope:** Repository implementations, database-specific code

**Technical constraints:**
- Interface-only definitions in Domain layer
- Method signatures must be expressive and type-safe
- Support for common CRUD operations
- Support for queries needed by services

**Key files to change:**
- src/Domain/Repository/EventRepositoryInterface.php
- src/Domain/Repository/UserRepositoryInterface.php
- tests/Unit/Domain/Repository/

**Acceptance Criteria:**
**Scenario: Find events by date range**  
Given a date range  
When findEventsByDateRange is called  
Then matching events are returned

**Scenario: Save event**  
Given an Event entity  
When save is called  
Then event is persisted

**Definition of Done:**
- [ ] Repository interfaces defined
- [ ] Method signatures are complete and type-safe
- [ ] Interfaces are framework agnostic
- [ ] Tests mock interfaces successfully

#### Task 1.4: Service Layer Foundation
**Status: DONE**  
**Task:** Create core service layer with EventService and UserService

**As a developer,**  
I want service classes that orchestrate business logic  
so that I can implement features without duplicating business rules.

**One-sentence goal:** Implement stateless service classes with proper dependency injection.

**Current situation:** No service layer exists

**Desired outcome:** EventService and UserService with core operations

**Out of scope:** Repository implementations, API controllers, UI code

**Technical constraints:**
- Constructor injection for all dependencies
- Services must be stateless and final
- No global state or static access
- Single responsibility principle

**Key files to change:**
- src/Application/Service/EventService.php
- src/Application/Service/UserService.php
- tests/Unit/Application/Service/

**Acceptance Criteria:**
**Scenario: Create event**  
Given valid event data  
When createEvent is called  
Then event is created and saved

**Scenario: Authenticate user**  
Given valid credentials  
When authenticate is called  
Then user is authenticated

**Definition of Done:**
- [ ] Services implemented with proper DI
- [ ] Business logic is centralized
- [ ] Services are testable with mocks
- [ ] No framework dependencies

#### Task 1.5: Infrastructure Setup
**Status: DONE**  
**Task:** Establish SQLite schema for integration testing

**Acceptance Criteria:**
- [x] SQLite schema created in `src/Infrastructure/Persistence/sqlite-schema.sql`
- [x] Schema includes PRD 4.0 target columns
- [x] Documentation added to GEMINI.md

### Epic 2: Calendar Views & Event Management
**Status: DONE**  
**Goal:** Implement calendar views and core event management functionality

#### Task 2.1: Event Retrieval & Date Range Querying
**Status: DONE**  
**Task:** Implement querying logic for standard calendar views (Month, Week, Day)

**As a frontend developer,**  
I want to retrieve events within a specific date range  
so that I can populate the Month, Week, and Day views.

**One-sentence goal:** Implement robust event retrieval by date range, handling single events.

**Current situation:** Logic trapped in `month.php`, `week.php`, `day.php`.

**Desired outcome:** `EventService::getEvents(DateRange $range, User $user)` returns correct events.

**Out of scope:** Recurring event expansion (handled in Epic 3).

**Technical constraints:**
- Must use `DateRange` value object
- Must return typed `EventCollection` or array of `Event` entities
- Performance optimized for month-long ranges

**Key files to change:**
- src/Application/Service/EventService.php
- src/Infrastructure/Persistence/InMemoryEventRepository.php (for testing)

**Acceptance Criteria:**
**Scenario: Fetch month events**  
Given a date range of "2023-10-01" to "2023-10-31"  
When `getEvents` is called  
Then all single events falling within that range are returned

**Definition of Done:**
- [ ] `getEvents` method implemented
- [ ] Unit tests for boundary conditions (starts before/ends after)
- [ ] Mock repository implementation available

#### Task 2.2: Event CRUD Operations
**Status: DONE**  
**Task:** Implement Create, Update, and Delete operations for Events

**As a user,**  
I want to create, edit, and delete calendar events  
so that I can manage my schedule.

**One-sentence goal:** Implement full CRUD capabilities for the Event entity with validation.

**Current situation:** Logic in `edit_entry_handler.php`, `del_entry.php`.

**Desired outcome:** `createEvent`, `updateEvent`, `deleteEvent` methods in `EventService`.

**Out of scope:** Complex conflict detection (separate task).

**Technical constraints:**
- Validate all inputs via Domain Entities
- Throw specific Domain Exceptions (e.g., `EventNotFoundException`)

**Key files to change:**
- src/Application/Service/EventService.php
- src/Domain/Exception/EventNotFoundException.php

**Acceptance Criteria:**
**Scenario: Delete non-existent event**  
Given an ID that does not exist  
When `deleteEvent` is called  
Then `EventNotFoundException` is thrown

**Definition of Done:**
- [ ] CRUD methods implemented
- [ ] Domain events dispatched (e.g., `EventCreated`)
- [ ] Full test coverage for success and failure paths

#### Task 2.3: Conflict Detection Logic
**Status: DONE**  
**Task:** Implement conflict detection for overlapping events

**As a user,**  
I want to be warned if I schedule an event that overlaps with another  
so that I avoid double-booking.

**One-sentence goal:** Implement logic to detect temporal overlaps between events.

**Current situation:** `check_for_conflicts()` function in legacy.

**Desired outcome:** `ConflictService` or method in `EventService` that returns overlapping events.

**Out of scope:** UI warning display.

**Technical constraints:**
- Efficient overlap calculation ( StartA < EndB && EndA > StartB )
- Respect `LIMIT_APPTS` system setting if passed

**Key files to change:**
- src/Domain/Service/ConflictDetector.php

**Acceptance Criteria:**
**Scenario: Exact overlap**  
Given an event from 10:00-11:00  
When checking a new event 10:00-11:00  
Then conflict is detected

**Definition of Done:**
- [ ] Conflict detection logic isolated in domain service
- [ ] Tests covering partial, full, and enclosing overlaps

### Epic 3: Repeating Events & RFC 5545 Compliance
**Status: DONE**  
**Goal:** Implement full RFC 5545 recurrence support with php-icalendar-core

#### Task 3.1: Recurrence Domain Models
**Status: DONE**  
**Task:** Create Value Objects for Recurrence Rules (RRULE)

**As a developer,**  
I want to model RRULEs, EXDATEs, and RDATEs in the domain  
so that I can robustly handle repeating events.

**One-sentence goal:** Map RFC 5545 recurrence concepts to Domain Value Objects.

**Current situation:** Legacy uses custom columns (`cal_frequency`, etc.).

**Desired outcome:** `RecurrenceRule` value object that wraps/maps to `php-icalendar-core` structures.

**Key files to change:**
- src/Domain/ValueObject/RecurrenceRule.php

**Acceptance Criteria:**
**Scenario: Valid RRULE string**  
Given "FREQ=WEEKLY;BYDAY=MO,WE"  
When `RecurrenceRule` is instantiated  
Then it parses correctly

#### Task 3.2: Recurrence Expansion Service
**Status: DONE**  
**Task:** Implement service to expand repeating events into occurrences

**As a system,**  
I want to generate concrete occurrence dates from an RRULE  
so that they can be displayed on the calendar.

**One-sentence goal:** Use `php-icalendar-core` to expand recurrence rules into date lists.

**Current situation:** Legacy `get_all_dates()` function.

**Desired outcome:** `RecurrenceService::expand(Event $event, DateRange $range)` returns `Occurrence[]`.

**Key files to change:**
- src/Application/Service/RecurrenceService.php

**Definition of Done:**
- [ ] Service integrates with `craigk5n/php-icalendar-core`
- [ ] Handles infinite recursion limits
- [ ] Correctly processes EXDATEs (exceptions)

### Epic 4: Tasks, Journals & Advanced Features
**Status: DONE**  
**Goal:** Implement tasks, journals, and advanced calendar features

#### Task 4.1: Task & Journal Entities
**Status: DONE**  
**Task:** Implement Task (VTODO) and Journal (VJOURNAL) entities

**As a user,**  
I want to track tasks and journal entries separate from events  
so that I can manage to-dos and notes.

**One-sentence goal:** Create specialized entities for Tasks and Journals inheriting/sharing logic with Events.

**Desired outcome:** `Task` and `Journal` entities with specific fields (Due Date, Completion %).

**Key files to change:**
- src/Domain/Entity/Task.php
- src/Domain/Entity/Journal.php

### Epic 5: User Management & Authentication
**Status: DONE**  
**Goal:** Implement user management and pluggable authentication

#### Task 5.1: User Service & Repository
**Status: DONE**  
**Task:** Implement User lifecycle management

**As an admin,**  
I want to create and manage user accounts  
so that people can access the calendar.

**One-sentence goal:** Implement `UserService` for user CRUD and preference management.

**Key files to change:**
- src/Application/Service/UserService.php
- src/Domain/Repository/UserRepositoryInterface.php

#### Task 5.2: Authentication Provider & Interface
**Status: DONE**  
**Task:** Implement interface-based authentication strategy

**As a developer,**  
I want a pluggable authentication mechanism  
so that I can support local DB, LDAP, or WordPress auth without changing core logic.

**One-sentence goal:** Define core authentication interfaces and provide a default database implementation.

**Desired outcome:** `AuthServiceInterface` and `UserRepositoryInterface` defined with a working `DatabaseAuthService`.

**Key files to change:**
- src/Application/Contract/AuthServiceInterface.php
- src/Domain/Repository/UserRepositoryInterface.php
- src/Infrastructure/Security/DatabaseAuthService.php
- src/Infrastructure/Persistence/PdoUserRepository.php

**Acceptance Criteria:**
**Scenario: WordPress integration**  
Given a WordPress plugin environment  
When `WordPressAuthService` implements `AuthServiceInterface`  
Then core services can verify users using WP's native auth.

**Definition of Done:**
- [ ] `AuthServiceInterface` defined in Application layer
- [ ] `UserRepositoryInterface` defined in Domain layer
- [ ] `DatabaseAuthService` implemented for standalone use
- [ ] Core services injected with these interfaces
- [ ] Unit tests for interface compliance

### Epic 6: Access Control & Security
**Status: DONE**  
**Goal:** Implement comprehensive access control system

#### Task 6.1: Permission Service (UAC)
**Status: DONE**  
**Task:** Implement core Permission Service matching legacy UAC

**As a system,**  
I want to check if a user has access to specific functions  
so that I can enforce security policies.

**One-sentence goal:** Replicate legacy function-level access control (UAC) in a clean service.

**Current situation:** `access_can_access_function()` in legacy.

**Desired outcome:** `PermissionService::canAccess(User $user, string $function)`

**Key files to change:**
- src/Application/Service/PermissionService.php
- src/Domain/ValueObject/Permission.php

### Epic 7: Import & Export Functionality
**Status: DONE**  
**Goal:** Implement iCal import/export and other data exchange formats

#### Task 7.1: Import Service
**Status: DONE**  
**Task:** Implement ICS import logic

**As a user,**  
I want to import events from an .ics file  
so that I can migrate data from other calendars.

**One-sentence goal:** Parse ICS data using `php-icalendar-core` and convert to Domain Entities.

**Key files to change:**
- src/Application/Service/ImportService.php
- src/Infrastructure/ICal/EventMapper.php

#### Task 7.2: Export Service
**Status: DONE**  
**Task:** Implement ICS export logic

**As a user,**  
I want to export my calendar to .ics  
so that I can use it in other applications.

**One-sentence goal:** Convert Domain Entities to ICS format using `php-icalendar-core`.

**Key files to change:**
- src/Application/Service/ExportService.php

### Epic 8: Advanced Calendar Features
**Status: DONE**  
**Goal:** Implement groups, categories, layers, and custom views

#### Task 8.1: Category Service
**Status: DONE**  
**Task:** Implement Category management

**As a user,**  
I want to color-code events with categories  
so that I can visually organize my calendar.

**One-sentence goal:** Implement `Category` entity and management service.

**Key files to change:**
- src/Domain/Entity/Category.php
- src/Application/Service/CategoryService.php

#### Task 8.2: Layer Service (Overlays)
**Status: DONE**  
**Task:** Implement Layer logic for overlaying calendars

**As a user,**  
I want to overlay another user's calendar on mine  
so that I can see combined availability.

**One-sentence goal:** Implement logic to fetch and merge events from "Layer" sources.

**Key files to change:**
- src/Domain/Entity/Layer.php
- src/Application/Service/LayerService.php

#### Task 8.3: Group Service
**Status: DONE**  
**Task:** Implement Group management

#### Task 8.4: Custom View Service
**Status: DONE**  
**Task:** Implement Custom View management

**As a user,**  
I want to create custom views that combine multiple users' calendars  
so that I can see team schedules in one place.

**One-sentence goal:** Implement Custom View entity and management service.

**Key files to change:**
- src/Domain/Entity/View.php
- src/Application/Service/ViewService.php
- src/Domain/Repository/ViewRepositoryInterface.php

### Epic 9: Quality & Infrastructure
**Status: DONE**  
**Goal:** Set up comprehensive testing, CI/CD, and documentation

#### Task 9.1: Continuous Integration Setup
**Status: DONE**  
**Task:** Configure CI workflow for tests and static analysis

#### Task 9.2: Dockerized Integration Test Environment
**Status: DONE**  
**Task:** Create Docker Compose setup for local MySQL/Postgres testing

**As a developer,**  
I want to run integration tests against real MySQL and Postgres instances locally  
so that I can catch engine-specific bugs before pushing to CI.

**One-sentence goal:** Provide a one-command environment for multi-DB testing.

**Desired outcome:** `docker-compose -f docker-compose.test.yml up` provides all needed engines.

**Key files to change:**
- docker-compose.test.yml
- tests/Integration/RepositoryTestCase.php

**Acceptance Criteria:**
- [ ] Docker Compose file defines `mysql` and `postgres` services.
- [ ] Base test case can switch engines via environment variables.

#### Task 9.3: Multi-Engine Matrix Testing in CI
**Status: DONE**  
**Task:** Implement GitHub Actions matrix for SQLite, MySQL, and Postgres

**As a maintainer,**  
I want the CI suite to run all integration tests on every supported database  
so that we never break compatibility for a specific engine.

**Acceptance Criteria:**
- [ ] `.github/workflows/ci.yml` uses a matrix strategy for `db-type`.
- [ ] Tests pass on all three engines in the CI pipeline.

**As a developer,**  
I want automated checks on every commit  
so that quality is maintained.

**One-sentence goal:** Create GitHub Actions workflow for PHPUnit and PHPStan.

**Key files to change:**
- .github/workflows/ci.yml

### Epic 10: Concrete Persistence (Infrastructure)
**Status: DONE**
**Goal:** Implement database-backed repositories using PDO.

#### Task 10.1: SQLite Repository Implementation
**Status: DONE**  
**Task:** Implement PdoUserRepository and PdoEventRepository for SQLite.

#### Task 10.2: MySQL & PostgreSQL Repository Support
**Status: DONE**  
**Task:** Ensure PDO repositories work with MySQL and PostgreSQL.

#### Task 10.3: Recurrence Persistence
**Status: DONE**  
**Task:** Implement full RRULE and exception persistence in PdoEventRepository.

**As a developer,**
I want recurring event rules and exceptions to be saved to the database
so that I can reload and expand them correctly later.

**One-sentence goal:** Implement bidirectional mapping between Recurrence value objects and legacy database tables.

**Key files to change:**
- src/Infrastructure/Persistence/PdoEventRepository.php
- tests/Integration/Persistence/PdoEventRepositoryTest.php

### Epic 11: Public Scheduling & Notifications
**Status: DONE**  
**Goal:** Implement booking service and notification system.

### Epic 12: Search & Activity Log

**Status: DONE**  


**Goal:** Implement event searching and audit trail.

#### Task 12.1: Search Service
**Status: DONE**  
**Task:** Implement SearchService for event and task searching.

**As a user,**
I want to search for events by keyword and date range
so that I can quickly find specific appointments.

**One-sentence goal:** Implement SearchService to provide keyword and filtered search capabilities.

**Key files to change:**
- src/Application/Service/SearchService.php
- tests/Unit/Application/Service/SearchServiceTest.php

#### Task 12.2: Activity Log Service

**Status: DONE**  


**Task:** Implement ActivityLogService for audit trails.

**As an admin,**
I want to track all changes to calendar entries
so that I can audit system usage and troubleshoot issues.

**One-sentence goal:** Implement ActivityLogService to record and retrieve system activities.

**Key files to change:**
- src/Application/Service/ActivityLogService.php
- src/Domain/Entity/ActivityLogEntry.php
- src/Domain/Repository/ActivityLogRepositoryInterface.php

### Epic 13: Reports & Feeds
**Status: DONE**
**Goal:** Implement reporting engine and calendar feeds (RSS, Free/Busy).

#### Task 13.1: Report Service
**Status: DONE**  
**Task:** Implement ReportService for generating custom reports based on templates.

#### Task 13.2: Feed Service
**Status: DONE**  
**Task:** Implement FeedService for RSS and Free/Busy feeds.

### Epic 14: Non-User Calendars & Delegates
**Status: DONE**
**Goal:** Implement resource management and delegate access.

#### Task 14.1: Resource Service
**Status: DONE**  
**Task:** Implement ResourceService for managing rooms and equipment.

#### Task 14.2: Assistant Service
**Status: DONE**  
**Task:** Implement AssistantService for managing delegate access.

### Epic 15: Internationalization (i18n)

**Status: DONE**  


**Goal:** Implement custom translation system.

#### Task 15.1: Translation Service

**Status: DONE**  


**Task:** Implement TranslationService for multi-language support.

**As a user,**
I want to use the calendar in my preferred language
so that I can navigate the interface easily.

**One-sentence goal:** Implement TranslationService to handle language-specific strings and caching.

**Key files to change:**
- src/Application/Service/TranslationService.php
- tests/Unit/Application/Service/TranslationServiceTest.php

### Epic 16: Attachments & Comments

**Status: DONE**  

  


**Goal:** Implement event attachments and comments.

#### Task 16.1: Blob Service

**Status: DONE**  


**Task:** Implement BlobService for managing event attachments and comments.

**As a user,**
I want to attach files and add comments to events
so that I can share relevant information with participants.

**One-sentence goal:** Implement BlobService to handle binary data and text comments for events.

**Key files to change:**
- src/Application/Service/BlobService.php
- src/Domain/Entity/Blob.php
- src/Domain/Repository/BlobRepositoryInterface.php

### Epic 17: System Configuration & Admin Settings
**Status: DONE**
**Goal:** Implement system-wide settings management.

#### Task 17.1: Config Service
**Status: DONE**  
**Task:** Implement ConfigService for reading and writing system settings.

### Epic 18: REST API & MCP Server

**Status: DONE**  


**Goal:** Implement API DTOs and MCP Server for AI integration.

#### Task 18.1: API DTOs
**Status: DONE**  
**Task:** Implement Request/Response DTOs for the REST API.

**As a developer,**
I want well-defined data structures for the API
so that I can ensure consistent communication with frontends.

**One-sentence goal:** Implement DTO classes for all core entities.

**Key files to change:**
- src/Application/DTO/

#### Task 18.2: MCP Server implementation

**Status: DONE**  


**Task:** Implement Model Context Protocol tools.

**As an AI assistant,**
I want to interact with the calendar via MCP
so that I can help users manage their schedules.

**One-sentence goal:** Implement MCP tool handlers for listing, searching, and adding events.

## Release Plan

### MVP Release (v1.0.0)
- Foundation & Core Infrastructure (Epic 1)
- Calendar Views & Event Management (Epic 2)
- Basic User Management (Epic 5)
- Basic Access Control (Epic 6)

### Feature Complete Release (v2.0.0)
- All remaining epics implemented
- Full RFC 5545 compliance (Epic 3)
- Tasks & Journals (Epic 4)
- Import/Export (Epic 7)
- Advanced Features (Epic 8)

## Dependencies & Blockers

- **Blocker:** Repository implementations needed for service testing (Task 1.3)
- **Dependency:** Domain layer must be complete before service layer
- **Dependency:** `php-icalendar-core` integration required for Epics 3 and 7

## Risk Assessment

- **High Risk:** RFC 5545 recurrence implementation complexity (Epic 3)
- **Medium Risk:** Replicating legacy UAC logic accurately (Epic 6)
- **Low Risk:** Basic CRUD operations (Epic 2)
