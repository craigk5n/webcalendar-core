# STATUS.md

## Task States
- **TODO** - Not started
- **IN_PROGRESS** - Currently being worked on
- **BLOCKED** - Cannot proceed due to external dependencies
- **REVIEW** - Ready for code review
- **DONE** - Completed and merged

## Epics & Tasks

### Epic 1: Foundation & Core Infrastructure
**Status: IN_PROGRESS**  
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
**Status: TODO**  
**Task:** Create core domain entities and value objects

**As a developer,**  
I want a solid domain foundation with Event, User, and core value objects  
so that I can build business logic on a strong type-safe foundation.

**One-sentence goal:** Create immutable domain entities and value objects with proper validation.

**Current situation:** No domain entities exist yet

**Desired outcome:** Event, User, EventId, DateRange, and basic value objects implemented

**Out of scope:** Repository implementations, services, and API endpoints

**Technical constraints:**
- PHP 8.2+, strict_types=1
- Immutable objects with proper validation
- Value objects must be final and immutable
- Domain logic must be framework/DB agnostic

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
**Status: TODO**  
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
**Status: TODO**  
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

### Epic 2: Calendar Views & Event Management
**Status: TODO**  
**Goal:** Implement calendar views and core event management functionality

### Epic 3: Repeating Events & RFC 5545 Compliance
**Status: TODO**  
**Goal:** Implement full RFC 5545 recurrence support with php-icalendar-core

### Epic 4: Tasks, Journals & Advanced Features
**Status: TODO**  
**Goal:** Implement tasks, journals, and advanced calendar features

### Epic 5: User Management & Authentication
**Status: TODO**  
**Goal:** Implement user management and pluggable authentication

### Epic 6: Access Control & Security
**Status: TODO**  
**Goal:** Implement comprehensive access control system

### Epic 7: Import & Export Functionality
**Status: TODO**  
**Goal:** Implement iCal import/export and other data exchange formats

### Epic 8: Advanced Calendar Features
**Status: TODO**  
**Goal:** Implement groups, categories, layers, and custom views

### Epic 9: Quality & Infrastructure
**Status: TODO**  
**Goal:** Set up comprehensive testing, CI/CD, and documentation

## Release Plan

### MVP Release (v1.0.0)
- Foundation & Core Infrastructure (Epic 1)
- Calendar Views & Event Management (Epic 2)
- Basic User Management (Epic 5)

### Feature Complete Release (v2.0.0)
- All remaining epics implemented
- Full RFC 5545 compliance
- Complete access control system
- Import/export functionality

## Dependencies & Blockers

- **Blocker:** Repository implementations needed for service testing
- **Dependency:** Domain layer must be complete before service layer
- **Dependency:** Repository interfaces needed before service implementations

## Risk Assessment

- **High Risk:** RFC 5545 recurrence implementation complexity
- **Medium Risk:** Access control system complexity
- **Low Risk:** Basic CRUD operations