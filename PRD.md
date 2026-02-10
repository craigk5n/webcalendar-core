# WebCalendar-Core Product Requirements Document (PRD)

**Version:** 4.0
**Date:** 2026-02-09
**Status:** Planning / Refactoring
**Purpose:** Feature catalog and architectural blueprint for `webcalendar-core`, the PHP 8.1+ business logic library (Composer package) that provides domain models, services, repository interfaces, and REST API contracts for the WebCalendar ecosystem.

---

## How to Read This Document

This PRD focuses on the `webcalendar-core` library. It covers both what **exists today** (legacy PHP app in the `legacy/` directory) and the **target architecture** (modern core library). Each section uses these markers:

- **CURRENT:** Describes the existing legacy implementation. Use this to understand what code exists and what behavior to preserve.
- **TARGET:** Describes the desired modern implementation. Use this for new development work.
- **NEW:** Feature that does not exist in the legacy codebase and must be built from scratch.

> **For AI Agents:** Each section includes a **Data Model** subsection with explicit field names and types. When implementing, match these field names exactly. Acceptance criteria are listed as checkboxes. Database table names use the `webcal_` prefix. Frontend rendering and WordPress plugin integration are handled by separate projects (`webcalendar-web` and `webcalendar-wp`) and are out of scope for this library.

> **Important:** The `legacy/` directory contains the original WebCalendar v1.9.13 codebase and is **for reference only**. It is excluded from git commits and must not be imported or used in the new codebase. This project uses Composer for dependency management.

---

## Table of Contents

1. [Product Overview](#1-product-overview)
2. [Architecture Overview](#2-architecture-overview)
3. [WebCalendar-Core (Business Logic)](#3-webcalendar-core-business-logic)
4. [Calendar Views (API Contracts)](#4-calendar-views-api-contracts)
5. [Event Management](#5-event-management)
6. [Repeating Events (RFC 5545)](#6-repeating-events-rfc-5545)
7. [Tasks & Journals](#7-tasks--journals)
8. [User Management & Authentication](#8-user-management--authentication)
9. [Access Control (UAC)](#9-access-control-uac)
10. [Public Scheduling (Booking)](#10-public-scheduling-booking)
11. [Groups](#11-groups)
12. [Categories](#12-categories)
13. [Layers (Calendar Overlaying)](#13-layers-calendar-overlaying)
14. [Custom Views](#14-custom-views)
15. [Attachments & Comments](#15-attachments--comments)
16. [Import & Export](#16-import--export)
17. [Search](#17-search)
18. [Reports](#18-reports)
19. [Notifications & Reminders](#19-notifications--reminders)
20. [Feeds & Publishing](#20-feeds--publishing)
21. [Non-User Calendars (Resources)](#21-non-user-calendars-resources)
22. [Assistant / Delegate Support](#22-assistant--delegate-support)
23. [Activity Log & Audit Trail](#23-activity-log--audit-trail)
24. [Admin Settings](#24-admin-settings)
25. [Security & Sessions](#25-security--sessions)
26. [Internationalization (i18n)](#26-internationalization-i18n)
27. [REST API Architecture](#27-rest-api-architecture)
28. [MCP Server (AI Integration)](#28-mcp-server-ai-integration)
29. [Database Support](#29-database-support)
30. [Packaging & Configuration](#30-packaging--configuration)
31. [Database Schema](#31-database-schema)

**Appendices:**
- [A: Site Extras](#appendix-a-site-extras-custom-event-fields)
- [B: User Templates](#appendix-b-user-templates)
- [C: Timezone Support](#appendix-c-timezone-support)
- [D: Testing & Quality](#appendix-d-testing--quality)
- [E: Migration Path](#appendix-e-migration-path)
- [F: RFC 5545 Gap Analysis](#appendix-f-rfc-5545--php-icalendar-core-gap-analysis)
- [G: Epics & User Stories](#appendix-g-epics--user-stories)

---

## 1. Product Overview

WebCalendar is a multi-user PHP web-based calendar application (current version 1.9.13) originating from PHP 3. The `webcalendar-core` library extracts all business logic into a modern, stateless PHP 8.1+ Composer package that can be consumed by any frontend or deployment target.

**CURRENT:** Monolithic PHP application with server-rendered HTML, `dbi4php` database abstraction, and global-state-based session management. All business logic, presentation, and data access are intermixed in `includes/functions.php` (~6600 lines) and root-level PHP files. The legacy code is in the `legacy/` directory for reference only — it is excluded from git and not used as a dependency in this Composer-based package.

**TARGET:** `webcalendar-core` is the single source of truth for all business logic, consumed by separate frontend projects via Composer and REST API.

**Core Library Characteristics:**
- **Clean Architecture:** Business logic decoupled from presentation via well-defined API contracts.
- **REST-First:** Defines REST API contracts (OpenAPI spec) that all frontends consume.
- **Interoperability:** Full RFC 5545/5546 compliance for modern device sync via `php-icalendar-core`.
- **Deployment-Agnostic:** Usable as a Composer dependency by standalone web apps, WordPress plugins, or headless API servers.
- **Zero UI:** Contains no HTML, CSS, JavaScript, or rendering logic.

---

## 2. Architecture Overview

**TARGET:** The WebCalendar ecosystem is split into **three distinct projects** with clear boundaries and contracts. This PRD covers `webcalendar-core` only.

### 2.1 Project Separation

```
┌─────────────────────────────────────────────────────────────┐
│                    WebCalendar Ecosystem                     │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌──────────────────┐      ┌──────────────────────────┐    │
│  │ webcalendar-core │      │   Frontend Implementations│    │
│  │   (Composer      │◄────►│                          │    │
│  │    Package)      │ REST │  ┌────────────────────┐  │    │
│  │                  │ API  │  │ webcalendar-web    │  │    │
│  │ - Business Logic │      │  │ (React SPA)        │  │    │
│  │ - Domain Models  │      │  └────────────────────┘  │    │
│  │ - Services       │      │                          │    │
│  │ - Repositories   │      │  ┌────────────────────┐  │    │
│  │ - Validation     │      │  │ webcalendar-classic│  │    │
│  └──────────────────┘      │  │ (Bootstrap/PHP)    │  │    │
│         ▲                  │  └────────────────────┘  │    │
│         │ Composer         │                          │    │
│         │ Dependency       │  ┌────────────────────┐  │    │
│  ┌──────┴───────────┐      │  │ WordPress Plugin   │  │    │
│  │  webcalendar-wp  │      │  │ (WP Block Editor)  │  │    │
│  │  (WordPress      │      │  └────────────────────┘  │    │
│  │   Plugin)        │      │                          │    │
│  └──────────────────┘      └──────────────────────────┘    │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Project: webcalendar-core

**Type:** PHP Composer Package
**Distribution:** Packagist

**Responsibilities:**
- Domain models (Event, User, Category, etc.)
- Business logic and validation rules
- Repository interfaces (database-agnostic)
- Service layer (EventService, UserService, etc.)
- RFC 5545 iCal parsing and generation
- Authentication abstractions (interface-based)
- REST API contract definitions (OpenAPI spec)

**Key Principle:** Contains **zero** UI code, HTML, CSS, or JavaScript. Pure PHP business logic only.

### 2.3 External Projects (Out of Scope)

**webcalendar-web** — Standalone full-stack PHP application that provides HTTP routing, REST API controllers, session management, and frontend asset delivery. Consumes `webcalendar-core` via Composer.

**webcalendar-wp** — WordPress plugin that bridges `webcalendar-core` into WordPress via `WpAuthenticationProvider`, `WpDatabaseConnection`, and `WpLogger` implementations of core's contract interfaces. Consumes `webcalendar-core` via Composer (bundled).

---

## 3. WebCalendar-Core (Business Logic)

**TARGET:** Pure PHP 8.1+ library with strict architectural boundaries.

### 3.1 Namespace Structure

```
WebCalendar\Core\
├── Domain\
│   ├── Entity\
│   │   ├── Event.php
│   │   ├── User.php
│   │   ├── Category.php
│   │   ├── Group.php
│   │   ├── Layer.php
│   │   ├── Reminder.php
│   │   └── Resource.php
│   ├── ValueObject\
│   │   ├── EventId.php
│   │   ├── DateRange.php
│   │   ├── RecurrenceRule.php
│   │   ├── AccessLevel.php    (Public|Confidential|Private)
│   │   └── EventType.php      (Event|Task|Journal)
│   └── Repository\
│       └── (Interfaces only)
├── Application\
│   ├── Service\
│   │   ├── EventService.php
│   │   ├── UserService.php
│   │   ├── PermissionService.php
│   │   ├── RecurrenceService.php
│   │   ├── NotificationService.php
│   │   ├── ImportExportService.php
│   │   ├── CategoryService.php
│   │   ├── GroupService.php
│   │   └── SearchService.php
│   ├── DTO\
│   │   └── (Request/Response objects)
│   └── Contract\
│       └── (Interfaces for external dependencies)
├── Infrastructure\
│   ├── Persistence\
│   │   └── (Repository implementations)
│   └── ICal\
│       ├── EventMapper.php    (Event ↔ VEvent)
│       ├── TaskMapper.php     (Event[Task] ↔ VTodo)
│       └── JournalMapper.php  (Event[Journal] ↔ VJournal)
└── Contract\
    └── (API contracts, OpenAPI specs)
```

### 3.2 Core Services

All business operations flow through stateless service classes:

| Service | Responsibility | Key Methods |
|---------|---------------|-------------|
| **EventService** | CRUD, conflict detection, recurrence expansion | `create()`, `update()`, `delete()`, `findByDateRange()`, `checkConflicts()` |
| **UserService** | User management, preferences | `authenticate()`, `getPreferences()`, `updatePreferences()` |
| **PermissionService** | UAC checks, visibility rules | `canView()`, `canEdit()`, `canApprove()`, `getAccessLevel()` |
| **RecurrenceService** | RRULE parsing and expansion | `expand()`, `parseRRule()`, `toRRule()` |
| **NotificationService** | Email/webhook dispatching | `sendReminder()`, `notifyParticipants()` |
| **ImportExportService** | iCal/CSV handling | `importICal()`, `exportICal()`, `exportCSV()` |
| **CategoryService** | Category CRUD | `create()`, `getForUser()`, `assignToEvent()` |
| **GroupService** | Group management | `create()`, `addMember()`, `getMembers()` |
| **SearchService** | Event search | `search()`, `advancedSearch()` |

### 3.3 Dependency Injection

Services use constructor injection for all dependencies:
- Repository interfaces (database layer)
- Logger interfaces (PSR-3)
- Configuration objects (no global state)
- Authentication provider interface

### 3.4 Contract Interfaces

Core defines interfaces for external dependencies, allowing different implementations per deployment:

```php
interface AuthenticationProvider {
    public function getCurrentUser(): ?User;
    public function hasPermission(string $permission): bool;
}

interface DatabaseConnection {
    public function query(string $sql, array $params): ResultSet;
}

interface Logger {
    public function log(string $level, string $message, array $context = []): void;
}
```

### 3.5 iCalendar Library: php-icalendar-core

**TARGET:** The `craigk5n/php-icalendar-core` Composer package provides all RFC 5545 iCalendar parsing, generation, validation, and recurrence expansion. It replaces the legacy custom parsing in `includes/import.php` and `includes/export.php`.

**Package:** `composer require craigk5n/php-icalendar-core`
**Namespace:** `Icalendar\`
**PHP:** 8.1+ with `declare(strict_types=1)`

#### Design Decision: Own Domain Entities + iCal Mapper Layer

The php-icalendar-core component classes (`VEvent`, `VTodo`, `VJournal`) are **iCalendar serialization objects** — they model the RFC 5545 wire format with string-typed properties and no persistence concerns. They are **not** used as domain entities directly because WebCalendar requires:

- Database primary keys (`cal_id`) and ownership (`cal_create_by`)
- Participant relationships with approval status (`webcal_entry_user`)
- WebCalendar-specific type codes (E/M/T/J/N/O) and access levels (P/C/R)
- Per-user category assignments
- Custom fields (site extras)
- Approval workflows

Instead, webcalendar-core uses its **own domain entities** (`Event`, `User`, etc.) for all business logic and persistence, with **mapper classes** in `Infrastructure\ICal\` to translate between the two models at the import/export boundary.

#### Mapper Classes

```php
// Infrastructure\ICal\EventMapper.php
class EventMapper {
    public function toVEvent(Event $event): VEvent;
    public function fromVEvent(VEvent $vevent): Event;
}

// Infrastructure\ICal\TaskMapper.php
class TaskMapper {
    public function toVTodo(Event $task): VTodo;
    public function fromVTodo(VTodo $vtodo): Event;
}

// Infrastructure\ICal\JournalMapper.php
class JournalMapper {
    public function toVJournal(Event $journal): VJournal;
    public function fromVJournal(VJournal $vjournal): Event;
}
```

#### php-icalendar-core Usage

The library is used in three specific areas:

**1. Import** (`ImportExportService` → Mapper):
```
ICS string → Parser::parse() → VCalendar → Mapper::fromVEvent() → Event entity → Repository::save()
```
- Use `Parser::LENIENT` mode to recover from malformed external .ics files
- Call `$component->validate()` to check RFC 5545 compliance before mapping

**2. Export** (Mapper → `Writer`):
```
Repository::find() → Event entity → Mapper::toVEvent() → VCalendar → Writer::write() → ICS string
```

**3. Recurrence Expansion** (`RecurrenceService`):
```
Event entity → Mapper::toVEvent() → RecurrenceExpander::expand() → Occurrence[] → domain dates
```
- `RecurrenceExpander` handles RRULE, RDATE, EXDATE, and multi-rule merge
- Replaces legacy `get_all_dates()` function
- Returns `Occurrence` objects with `getStart()`, `getEnd()`, `isRdate()`

#### Component Classes (Reference)

| Class | iCal Component | Key Typed Properties |
|-------|---------------|---------------------|
| `Icalendar\Component\VEvent` | VEVENT | DTSTART, DTEND, DURATION, RRULE, SUMMARY, DESCRIPTION, LOCATION, STATUS, CATEGORIES, URL, GEO, COLOR, CONFERENCE |
| `Icalendar\Component\VTodo` | VTODO | DTSTART, DUE, COMPLETED, DURATION, RRULE, PERCENT-COMPLETE, PRIORITY, SUMMARY, DESCRIPTION, STATUS, CATEGORIES |
| `Icalendar\Component\VJournal` | VJOURNAL | DTSTART, SUMMARY, DESCRIPTION (multiple), CATEGORIES, CLASS, STATUS, RRULE |
| `Icalendar\Component\VAlarm` | VALARM | ACTION, TRIGGER, DURATION, REPEAT, DESCRIPTION, SUMMARY |
| `Icalendar\Component\VCalendar` | VCALENDAR | PRODID, VERSION, CALSCALE, METHOD |

Additional RFC 5545 properties (ATTENDEE, ORGANIZER, SEQUENCE, RECURRENCE-ID, etc.) are accessible via `AbstractComponent::addProperty()` / `getProperty()` / `getAllProperties()`.

#### Recurrence Classes (Reference)

| Class | Purpose |
|-------|---------|
| `Icalendar\Recurrence\RRule` | Value object for all 14 RRULE parts |
| `Icalendar\Recurrence\RecurrenceExpander` | Full expansion with EXDATE, RDATE, multi-RRULE merge |
| `Icalendar\Recurrence\Occurrence` | Result object with `getStart()`, `getEnd()`, `isRdate()` |

---

## 4. Calendar Views (API Contracts)

The core library provides services that return structured event data for calendar views. Frontends (React SPA, Bootstrap, WordPress blocks) are responsible for rendering. The core defines the API contracts below.

**CURRENT (Legacy):** Server-rendered views in `day.php`, `week.php`, `month.php`, `year.php` with rendering functions in `includes/functions.php`. Key legacy functions: `get_preferred_view($login)`, `query_events()`.

**User Preference:** `STARTVIEW` setting controls which view loads by default.

### 4.1 TARGET API Endpoints

- `GET /api/v2/views/day?date=YYYY-MM-DD` — Events for a single day
- `GET /api/v2/views/week?date=YYYY-MM-DD` — Events for the week containing date
- `GET /api/v2/views/month?year=YYYY&month=MM` — Events for entire month
- `GET /api/v2/views/year?year=YYYY` — Event counts per day for year overview

### 4.2 Acceptance Criteria (Core)

- [ ] View endpoints return correct events for the requested date range
- [ ] Repeating event instances are expanded to correct dates via RecurrenceService
- [ ] Confidential events return as "busy" (no details) for non-participants
- [ ] Private events are excluded from results for non-participants
- [ ] User's preferred view preference is available via UserService
- [ ] Category colors are included in event response data

---

## 5. Event Management

### 5.1 Event Data Model

**CURRENT Table:** `webcal_entry`

| Column | Type | Description |
|--------|------|-------------|
| `cal_id` | INT (PK) | Unique event ID |
| `cal_group_id` | INT | Group ID (for exception events replacing a recurring instance) |
| `cal_ext_for_id` | INT | If non-zero, this is an "extension" of another event (additions to repeating series) |
| `cal_create_by` | VARCHAR(25) | Login of creator |
| `cal_date` | INT | Start date as YYYYMMDD |
| `cal_time` | INT | Start time as HHMMSS (-1 = untimed/all-day) |
| `cal_mod_date` | INT | Last modified date YYYYMMDD |
| `cal_mod_time` | INT | Last modified time HHMMSS |
| `cal_duration` | INT | Duration in minutes (1440 = all day) |
| `cal_due_date` | INT | Due date for tasks YYYYMMDD |
| `cal_due_time` | INT | Due time for tasks HHMMSS |
| `cal_priority` | INT | Priority 1-9 (1=highest, 5=medium, 9=lowest) |
| `cal_type` | CHAR(1) | **E**=Event, **M**=Repeating, **T**=Task, **J**=Journal, **N**=Repeating Task, **O**=Repeating Journal |
| `cal_access` | CHAR(1) | **P**=Public, **C**=Confidential, **R**=Private |
| `cal_name` | VARCHAR(80) | Event title |
| `cal_description` | TEXT | Event description (may contain HTML if `ALLOW_HTML_DESCRIPTION` enabled) |
| `cal_location` | VARCHAR(100) | Location text |
| `cal_url` | VARCHAR(100) | Associated URL |
| `cal_completed` | INT | Date task was completed YYYYMMDD |

**TARGET: New columns for RFC 5545 compliance** (add to `webcal_entry`):

| Column | Type | iCal Property | Rationale |
|--------|------|---------------|-----------|
| `cal_uid` | VARCHAR(255) | UID | **Required.** Globally unique identifier for iCal interop. Currently UID is only in `webcal_import_data.cal_external_id` tied to import batches — not usable for export/sync. |
| `cal_sequence` | INT DEFAULT 0 | SEQUENCE | Change counter for multi-client sync. Incremented on each update. |
| `cal_transp` | VARCHAR(11) DEFAULT 'OPAQUE' | TRANSP | `OPAQUE` (blocks free/busy) or `TRANSPARENT` (does not block). Required for correct free/busy calculation. |
| `cal_status` | VARCHAR(20) DEFAULT NULL | STATUS | Event-level status: `TENTATIVE`, `CONFIRMED`, `CANCELLED` (events); `NEEDS-ACTION`, `IN-PROCESS`, `COMPLETED`, `CANCELLED` (tasks); `DRAFT`, `FINAL`, `CANCELLED` (journals). Distinct from per-participant `cal_status` in `webcal_entry_user`. |
| `cal_geo_lat` | DECIMAL(10,7) DEFAULT NULL | GEO (latitude) | Geographic coordinates for location mapping. |
| `cal_geo_lon` | DECIMAL(10,7) DEFAULT NULL | GEO (longitude) | Geographic coordinates for location mapping. |
| `cal_color` | VARCHAR(16) DEFAULT NULL | COLOR | Per-event CSS color override (e.g., `#FF5733`). Supplements category color. Supported by `php-icalendar-core` VEvent. |
| `cal_conference` | VARCHAR(255) DEFAULT NULL | CONFERENCE | Video conferencing URL (Zoom/Meet/Teams). Supported by `php-icalendar-core` VEvent. |
| `cal_organizer` | VARCHAR(255) DEFAULT NULL | ORGANIZER | RFC 5545 `mailto:` URI of organizer. Supplements `cal_create_by` (which stores login only). |
| `cal_created` | INT DEFAULT NULL | CREATED | Creation date YYYYMMDD. Separate from `cal_mod_date` (LAST-MODIFIED). |
| `cal_created_time` | INT DEFAULT NULL | CREATED | Creation time HHMMSS. |

> **For AI Agents:** When implementing the import service, map php-icalendar-core `VEvent` properties to these columns. When implementing the export service, populate `VEvent` objects from these columns. Properties without dedicated columns (CONTACT, RELATED-TO, REQUEST-STATUS, RESOURCES) can use the `webcal_site_extras` table or a new `webcal_entry_properties` table for generic storage.

**CURRENT Table:** `webcal_entry_user` (participants)

| Column | Type | Description |
|--------|------|-------------|
| `cal_id` | INT | Event ID (FK) |
| `cal_login` | VARCHAR(25) | User login |
| `cal_status` | CHAR(1) | **A**=Accepted, **C**=Completed, **D**=Deleted, **P**=In-Progress, **R**=Rejected, **W**=Waiting |
| `cal_category` | INT | Category ID for this user's view |
| `cal_percent` | INT | Percentage completion (tasks only) |

**CURRENT Table:** `webcal_entry_ext_user` (external/non-user participants)

| Column | Type | Description |
|--------|------|-------------|
| `cal_id` | INT | Event ID (FK) |
| `cal_fullname` | VARCHAR(50) | External participant name |
| `cal_email` | VARCHAR(75) | External participant email |

### 5.2 Key Functions (CURRENT)

- `query_events($user, $need_unapproved, $startdate, $enddate)` — Query events for a date range, including repeating event instances
- `check_for_conflicts($dates, $duration, $hour, $minute, ...)` — Detect overlapping events. Respects `LIMIT_APPTS` setting and participant status
- `combine_and_sort_events($events, $rpt_events)` — Merge single and repeating events, sort by time
- `build_entry_label($event, $popupid, ...)` — Build display string with access control applied
- `build_entry_popup(...)` — Create event tooltip/popup HTML

### 5.3 Event CRUD Files (CURRENT)

| File | Purpose |
|------|---------|
| `edit_entry.php` | Create/edit form (participants, categories, repeating, reminders) |
| `edit_entry_handler.php` | Process create/edit form submission |
| `view_entry.php` | View event details |
| `del_entry.php` | Delete an event |
| `approve_entry.php` | Approve a pending event |
| `reject_entry.php` | Reject a pending event |

### 5.4 TARGET Enhancements (NEW)

- **Quick Add (Natural Language):** Users type *"Meeting with Team at 2pm next Tuesday"* to create events
- **Video Link:** Dedicated field for Zoom/Google Meet/Teams URLs
- **Location Mapping:** OpenStreetMap integration for address validation
- **Color Coding:** Per-event color override (in addition to category colors)

### 5.5 TARGET API Endpoints

```
GET    /api/v2/events?start=YYYYMMDD&end=YYYYMMDD&user={login}
POST   /api/v2/events                    # Create event
GET    /api/v2/events/{id}               # Get event details
PUT    /api/v2/events/{id}               # Full update
PATCH  /api/v2/events/{id}               # Partial update (drag-and-drop)
DELETE /api/v2/events/{id}               # Delete event
POST   /api/v2/events/{id}/approve       # Approve pending event
POST   /api/v2/events/{id}/reject        # Reject pending event
```

### 5.6 Acceptance Criteria

- [ ] Events can be created with all fields (title, date, time, duration, location, URL, description, priority, access level)
- [ ] Participants can be invited and their status tracked (A/W/R/D)
- [ ] Conflict detection warns when events overlap
- [ ] Access levels (Public/Confidential/Private) are enforced in all views
- [ ] Events can be approved or rejected when approval workflow is enabled
- [ ] External (non-user) participants can be added with name and email

---

## 6. Repeating Events (RFC 5545)

### 6.1 Recurrence Data Model

**CURRENT Table:** `webcal_entry_repeats`

| Column | Type | Description |
|--------|------|-------------|
| `cal_id` | INT (PK/FK) | Event ID from `webcal_entry` |
| `cal_type` | VARCHAR(20) | `daily`, `weekly`, `monthlyByDate`, `monthlyByDay`, `monthlyBySetPos`, `yearly` |
| `cal_end` | INT | End date YYYYMMDD (NULL = no end) |
| `cal_endtime` | INT | End time HHMMSS |
| `cal_frequency` | INT | Interval (every N occurrences, default 1) |
| `cal_days` | VARCHAR(7) | For weekly: bitmask of days (e.g., `ynynyny` = MWF) |
| `cal_bymonth` | VARCHAR(50) | Comma-separated month numbers |
| `cal_bymonthday` | VARCHAR(100) | Comma-separated days of month |
| `cal_byday` | VARCHAR(100) | Comma-separated weekday specs (MO, TU, 2MO = second Monday) |
| `cal_bysetpos` | VARCHAR(50) | Position within month |
| `cal_byweekno` | VARCHAR(50) | Week numbers |
| `cal_byyearday` | VARCHAR(50) | Day of year |
| `cal_wkst` | VARCHAR(2) | Week start day (default `MO`) |
| `cal_count` | INT | Total number of occurrences |

**TARGET: New columns for full RRULE coverage** (add to `webcal_entry_repeats`):

| Column | Type | RRULE Part | Rationale |
|--------|------|------------|-----------|
| `cal_byhour` | VARCHAR(50) DEFAULT NULL | BYHOUR | Hours filter (0-23). Supported by `php-icalendar-core` RRule. Rare but needed for sub-daily recurrence patterns. |
| `cal_byminute` | VARCHAR(50) DEFAULT NULL | BYMINUTE | Minutes filter (0-59). Supported by `php-icalendar-core` RRule. |
| `cal_bysecond` | VARCHAR(50) DEFAULT NULL | BYSECOND | Seconds filter (0-60). Supported by `php-icalendar-core` RRule. Extremely rare but needed for complete RRULE round-trip. |

> **For AI Agents:** The `php-icalendar-core` `RRule` class supports all 14 RRULE parts. The current schema covers 11 of 14. After adding BYHOUR, BYMINUTE, and BYSECOND, the schema will have complete coverage. The `RecurrenceService` must convert between the column-based storage and the `RRule` value object bidirectionally.

**CURRENT Table:** `webcal_entry_repeats_not` (exceptions)

| Column | Type | Description |
|--------|------|-------------|
| `cal_id` | INT (FK) | Event ID |
| `cal_date` | INT | Exception date YYYYMMDD |
| `cal_exdate` | INT | 1=excluded (EXDATE), 0=included (RDATE) |
| `cal_group_id` | INT | If non-zero, points to replacement event ID |

### 6.2 Key Functions (CURRENT)

- `get_repeating_entries($user, $date)` — Get instances of repeating events for a specific date
- `read_repeated_events($user, $startdate, $enddate)` — Read repeating event definitions
- `get_all_dates($date, $rpt_type, $end, $days, $frequency, ...)` — Calculate all occurrence dates for a rule

### 6.3 TARGET: RRULE Standard

The `RecurrenceService` generates and parses standard RFC 5545 **RRULE** strings for interoperability:

```
RRULE:FREQ=WEEKLY;BYDAY=MO,WE,FR;UNTIL=20261231T235959Z
RRULE:FREQ=MONTHLY;BYSETPOS=2;BYDAY=MO;COUNT=12
```

Internal storage maintains existing columns for backward compatibility, but the service provides bidirectional conversion between column-based storage and RRULE strings.

### 6.4 Acceptance Criteria

- [ ] All recurrence types expand correctly: daily, weekly, monthlyByDate, monthlyByDay, monthlyBySetPos, yearly
- [ ] EXDATE exclusions skip specific dates
- [ ] RDATE additions include extra dates
- [ ] Exception events (cal_group_id) replace specific instances with modified versions
- [ ] Recurrence count and end date are both respected as termination conditions
- [ ] RRULE strings round-trip correctly (parse → store → regenerate) with zero data loss
- [ ] Weekly recurrence with specific days (e.g., MWF) works correctly
- [ ] All 14 RRULE parts are stored and restored (including BYHOUR, BYMINUTE, BYSECOND)
- [ ] `php-icalendar-core` `RecurrenceExpander` is used for all occurrence generation
- [ ] Multi-RRULE events are supported (multiple RRULE properties merged)
- [ ] EXDATE with VALUE=DATE excludes all occurrences on that calendar date

---

## 7. Tasks & Journals

### 7.1 Overview

**CURRENT:** Tasks (VTODO) and Journals (VJOURNAL) are stored in the same `webcal_entry` table as events, differentiated by `cal_type`.

### 7.2 Type Codes

| `cal_type` | Description | iCal Component |
|-----------|-------------|----------------|
| `E` | Single event | VEVENT |
| `M` | Repeating event | VEVENT + RRULE |
| `T` | Task | VTODO |
| `J` | Journal | VJOURNAL |
| `N` | Repeating task (legacy) | VTODO + RRULE |
| `O` | Repeating journal (legacy) | VJOURNAL + RRULE |

### 7.3 Task-Specific Fields

From `webcal_entry`:
- `cal_due_date` (INT) — Due date YYYYMMDD
- `cal_due_time` (INT) — Due time HHMMSS
- `cal_completed` (INT) — Completion date YYYYMMDD

From `webcal_entry_user`:
- `cal_percent` (INT) — Percentage completion per user (0-100)
- `cal_status` = `C` for completed, `P` for in-progress

### 7.4 Key Functions (CURRENT)

- `get_tasks($user, $date)` — Retrieve tasks, optionally filtered by due date
- `read_tasks($user, $startdate, $enddate)` — Read tasks within date range
- `display_small_tasks($tasks)` — Render task list in sidebar, grouped by category

### 7.5 Access Control for Tasks/Journals

Separate access bits from events:

| Type | Public | Confidential | Private |
|------|--------|--------------|---------|
| Events | Bit 1 (value 1) | Bit 8 | Bit 64 |
| Tasks | Bit 2 (value 2) | Bit 16 | Bit 128 |
| Journals | Bit 4 (value 4) | Bit 32 | Bit 256 |

These bits combine into a single access value in `webcal_access_user`.

### 7.6 TARGET API Endpoints

```
GET    /api/v2/tasks?due_before=YYYYMMDD&status=pending
POST   /api/v2/tasks
PATCH  /api/v2/tasks/{id}              # Update status, percent complete
GET    /api/v2/journals?start=YYYYMMDD&end=YYYYMMDD
POST   /api/v2/journals
```

### 7.7 Acceptance Criteria

- [ ] Tasks display with due date, priority, and completion percentage
- [ ] Task status transitions work: pending → in-progress → completed
- [ ] Completed tasks record completion date
- [ ] Journals store dated text entries without time/duration
- [ ] iCal export emits VTODO for tasks and VJOURNAL for journals
- [ ] iCal import correctly creates tasks/journals from VTODO/VJOURNAL components

---

## 8. User Management & Authentication

### 8.1 User Data Model

**CURRENT Table:** `webcal_user`

| Column | Type | Description |
|--------|------|-------------|
| `cal_login` | VARCHAR(25) PK | Unique login name |
| `cal_passwd` | VARCHAR(255) | Hashed password (bcrypt; legacy MD5 auto-upgraded) |
| `cal_lastname` | VARCHAR(25) | Last name |
| `cal_firstname` | VARCHAR(25) | First name |
| `cal_is_admin` | CHAR(1) | `Y` = admin, `N` = regular user |
| `cal_email` | VARCHAR(75) | Email address |
| `cal_enabled` | CHAR(1) | `Y` = active, `N` = disabled |
| `cal_telephone` | VARCHAR(50) | Phone number |
| `cal_address` | VARCHAR(75) | Address |
| `cal_title` | VARCHAR(75) | Title/position |
| `cal_birthday` | INT | Birthday YYYYMMDD |
| `cal_last_login` | INT | Last login date YYYYMMDD |
| `cal_api_token` | VARCHAR(64) | API token for MCP/REST access |

### 8.2 Authentication Methods (CURRENT)

Pluggable authentication via `user_inc` setting in `includes/settings.php`:

| File | Method | Description |
|------|--------|-------------|
| `user.php` | Database | Default. Passwords in `webcal_user` table. Bcrypt with auto MD5 upgrade. |
| `user-ldap.php` | LDAP/AD | Authenticate against LDAP or Active Directory server |
| `user-imap.php` | IMAP | Authenticate against IMAP mail server |
| `user-nis.php` | NIS | Authenticate against NIS/YP directory |
| `user-app-joomla.php` | Joomla | Bridge to Joomla CMS user system |

**Each auth module implements:**
- `user_valid_login($login, $password)` — Validate credentials
- `user_valid_crypt($login, $crypt)` — Validate pre-hashed password
- `user_load_variables($login, $prefix)` — Load user profile into global variables
- `user_update_user($user, $firstname, $lastname, $email, ...)` — Update user profile
- `user_delete_user($user)` — Delete a user account
- `user_get_users()` — List all users

### 8.3 Password Security (CURRENT)

- New passwords hashed with `password_hash()` (bcrypt, cost 10)
- Legacy MD5 passwords auto-upgraded to bcrypt on successful login
- `password_verify()` used for all authentication checks

### 8.4 User Preferences

**CURRENT Table:** `webcal_user_pref`

| Column | Type | Description |
|--------|------|-------------|
| `cal_login` | VARCHAR(25) | User login (FK) |
| `cal_setting` | VARCHAR(25) | Setting name |
| `cal_value` | VARCHAR(100) | Setting value |

**Common Preference Keys:** `STARTVIEW`, `DATE_FORMAT`, `TIME_FORMAT`, `LANGUAGE`, `TIMEZONE`, `WEEK_START`, `WORK_DAY_START_HOUR`, `WORK_DAY_END_HOUR`, `DISPLAY_UNAPPROVED`, `PUBLISH_ENABLED`, `FREEBUSY_ENABLED`

### 8.5 Key Functions (CURRENT)

- `user_load_variables($login, $prefix)` — Load user data into `${prefix}login`, `${prefix}firstname`, etc.
- `get_my_users($user, $reason)` — Get list of users the current user can see (respects groups and admin settings)
- `load_user_preferences($user)` — Load user prefs from `webcal_user_pref`

### 8.6 TARGET Enhancements

- **PASSWORD:** Argon2id via `password_hash()` (PHP 8.1+)
- **WordPress Bridge:** `WpAuthenticationProvider` wraps `wp_get_current_user()`
- **API Auth:** JWT or scoped Bearer tokens for stateless REST authentication

### 8.7 Acceptance Criteria

- [ ] Users can register, login, and logout
- [ ] Password hashing uses bcrypt (current) or Argon2id (target)
- [ ] Legacy MD5 passwords auto-upgrade on login
- [ ] Admin can enable/disable user accounts
- [ ] User preferences persist across sessions
- [ ] All pluggable auth methods (LDAP, IMAP, NIS) continue to work
- [ ] API token generation works for MCP/REST access

---

## 9. Access Control (UAC)

### 9.1 Overview

**CURRENT:** Three-tier access control system: function-level, calendar-level, and event-level. Controlled by admin setting `UAC_ENABLED`.

### 9.2 Function-Level Access (28 Functions)

**CURRENT Table:** `webcal_access_function`

| Column | Type | Description |
|--------|------|-------------|
| `cal_login` | VARCHAR(25) | User login |
| `cal_permissions` | VARCHAR(64) | Bitmask string: position = function ID, value = `Y`/`N` |

**Function IDs:**

| ID | Constant | Controls Access To |
|----|----------|-------------------|
| 0 | `ACCESS_EVENT_VIEW` | View event details |
| 1 | `ACCESS_EVENT_EDIT` | Edit/create events |
| 2 | `ACCESS_DAY` | Day view |
| 3 | `ACCESS_WEEK` | Week view |
| 4 | `ACCESS_MONTH` | Month view |
| 5 | `ACCESS_YEAR` | Year view |
| 6 | `ACCESS_ADMIN_HOME` | Admin settings page |
| 7 | `ACCESS_REPORT` | Reports |
| 8 | `ACCESS_VIEW` | Custom views |
| 9 | `ACCESS_VIEW_MANAGEMENT` | Create/edit views |
| 10 | `ACCESS_CATEGORY_MANAGEMENT` | Manage categories |
| 11 | `ACCESS_LAYERS` | Layer management |
| 12 | `ACCESS_SEARCH` | Basic search |
| 13 | `ACCESS_ADVANCED_SEARCH` | Advanced search |
| 14 | `ACCESS_ACTIVITY_LOG` | Activity log |
| 15 | `ACCESS_USER_MANAGEMENT` | User management |
| 16 | `ACCESS_ACCOUNT_INFO` | View own account info |
| 17 | `ACCESS_ACCESS_MANAGEMENT` | Manage access controls |
| 18 | `ACCESS_PREFERENCES` | User preferences |
| 19 | `ACCESS_SYSTEM_SETTINGS` | System settings |
| 20 | `ACCESS_IMPORT` | Import calendars |
| 21 | `ACCESS_EXPORT` | Export calendars |
| 22 | `ACCESS_PUBLISH` | Publish calendars |
| 23 | `ACCESS_ASSISTANTS` | Assistant management |
| 24 | `ACCESS_TRAILER` | Footer/trailer customization |
| 25 | `ACCESS_HELP` | Help pages |
| 26 | `ACCESS_ANOTHER_CALENDAR` | View other users' calendars |
| 27 | `ACCESS_SECURITY_AUDIT` | Security audit log |

### 9.3 Calendar-Level Access

**CURRENT Table:** `webcal_access_user`

| Column | Type | Description |
|--------|------|-------------|
| `cal_login` | VARCHAR(25) | The user being granted access |
| `cal_other_user` | VARCHAR(25) | The calendar owner |
| `cal_can_view` | INT | Bitfield for view permissions (see 9.5 for bit values) |
| `cal_can_edit` | INT | 1=can edit events on this calendar |
| `cal_can_approve` | INT | 1=can approve events on this calendar |

### 9.4 Event-Level Access

Each event has `cal_access` in `webcal_entry`:
- **P (Public):** Visible to anyone who can see the calendar
- **C (Confidential):** Time slot visible but title/details hidden to non-participants
- **R (Private):** Completely invisible to non-participants

### 9.5 Key Functions (CURRENT)

- `access_is_enabled()` — Check if UAC is globally enabled
- `access_can_access_function($function_id, $user)` — Check function-level permission
- `access_user_calendar($type, $other_user, $current_user)` — Check calendar-level access (type: view/edit/approve)

### 9.6 Acceptance Criteria

- [ ] When UAC is disabled, all users have full access to all functions
- [ ] When UAC is enabled, function-level permissions are enforced per user
- [ ] Calendar-level view/edit/approve permissions work between user pairs
- [ ] Event access levels (Public/Confidential/Private) are enforced in all views and API responses
- [ ] Admin users bypass all access controls
- [ ] Separate access bits for events, tasks, and journals are respected

---

## 10. Public Scheduling (Booking)

### 10.1 Current State

**CURRENT:** Free/busy publishing exists via `freebusy.php` (RFC 5545 VFREEBUSY). Users can enable `FREEBUSY_ENABLED` in their preferences. No interactive booking UI exists.

### 10.2 TARGET / NEW: Booking Service

The core library provides a `BookingService` responsible for:
- Calculating available time slots based on user's existing events and configured office hours
- Enforcing buffer times between appointments to prevent back-to-back conflicts
- Creating pending events from booking requests, triggering the approval workflow
- Managing booking configuration (office hours, buffer time, enabled/disabled) per user

> **Note:** The public-facing booking page UI is a frontend concern (`webcalendar-web`).

### 10.3 TARGET API Endpoints

```
GET  /api/v2/booking/{user}/availability?date=YYYY-MM-DD
POST /api/v2/booking/{user}/book         # Book a slot (no auth required)
GET  /api/v2/booking/{user}/config       # Get booking page config
PUT  /api/v2/booking/{user}/config       # Update booking config (owner only)
```

### 10.4 Acceptance Criteria

- [ ] Availability endpoint returns free slots without revealing event details
- [ ] Booking endpoint accepts name, email, and purpose to create a pending event
- [ ] Booking creates a pending event requiring owner approval
- [ ] Buffer time between appointments is respected
- [ ] Office hours restrict available slots to defined windows

---

## 11. Groups

### 11.1 Data Model

**CURRENT Table:** `webcal_group`

| Column | Type | Description |
|--------|------|-------------|
| `cal_group_id` | INT (PK) | Group ID |
| `cal_owner` | VARCHAR(25) | Group owner login |
| `cal_name` | VARCHAR(50) | Group name |
| `cal_last_update` | INT | Last update timestamp YYYYMMDD |

**CURRENT Table:** `webcal_group_user`

| Column | Type | Description |
|--------|------|-------------|
| `cal_group_id` | INT (FK) | Group ID |
| `cal_login` | VARCHAR(25) | Member login |

### 11.2 Key Files (CURRENT)

- `groups.php` — Group management UI
- `group_edit.php` / `group_edit_handler.php` — Create/edit groups

### 11.3 Key Behaviors

- Groups are used for bulk event invitations and access control
- `USER_SEES_ONLY_HIS_GROUPS` setting restricts visibility to group members only
- `get_groups($user, $include_user_list)` — Get all groups accessible to user
- Admin can manage all groups; users manage groups they own

### 11.4 TARGET API Endpoints

```
GET    /api/v2/groups
POST   /api/v2/groups
GET    /api/v2/groups/{id}
PUT    /api/v2/groups/{id}
DELETE /api/v2/groups/{id}
GET    /api/v2/groups/{id}/members
POST   /api/v2/groups/{id}/members
DELETE /api/v2/groups/{id}/members/{login}
```

### 11.5 Acceptance Criteria

- [ ] Groups can be created with a name and member list
- [ ] Events can be assigned to groups (all members invited)
- [ ] `USER_SEES_ONLY_HIS_GROUPS` restricts user visibility to group members
- [ ] Group owners can add/remove members

---

## 12. Categories

### 12.1 Data Model

**CURRENT Table:** `webcal_categories`

| Column | Type | Description |
|--------|------|-------------|
| `cat_id` | INT (PK) | Category ID |
| `cat_owner` | VARCHAR(25) | Owner login (NULL = global category, admin-created) |
| `cat_name` | VARCHAR(80) | Category name |
| `cat_color` | VARCHAR(16) | Hex color code (e.g., `#FF0000`) |
| `cat_status` | CHAR(1) | `A`=Active, `D`=Disabled |
| `cat_icon_mime` | VARCHAR(32) | Icon MIME type |
| `cat_icon_blob` | LONGBLOB | Icon image data |

**CURRENT Table:** `webcal_entry_categories` (multi-category support)

| Column | Type | Description |
|--------|------|-------------|
| `cal_id` | INT (FK) | Event ID |
| `cat_id` | INT (FK) | Category ID |
| `cat_order` | INT | Display order |
| `cat_owner` | VARCHAR(25) | User login (categories are per-user per-event) |

### 12.2 Key Functions (CURRENT)

- `load_user_categories($user)` — Load categories accessible to user (global + user-owned)
- `get_categories_by_id($event_id, $user)` — Get categories assigned to an event for a user
- `get_category_icon_url($cat_id)` — Get URL for category icon image

### 12.3 Behaviors

- **Global categories:** Created by admins, visible to all users (`cat_owner` IS NULL)
- **User categories:** Created by individual users, visible only to owner
- **Multi-category:** Events can belong to multiple categories via `webcal_entry_categories`
- **Per-user assignment:** Each user can assign their own categories to the same event
- **Color coding:** Categories render with their assigned color in calendar views
- **Icon support:** Optional icon image stored as BLOB

### 12.4 TARGET API Endpoints

```
GET    /api/v2/categories                # List user's categories (global + owned)
POST   /api/v2/categories                # Create category
PUT    /api/v2/categories/{id}           # Update category
DELETE /api/v2/categories/{id}           # Delete category
```

### 12.5 Acceptance Criteria

- [ ] Admin can create global categories visible to all users
- [ ] Users can create personal categories
- [ ] Events can be assigned to multiple categories
- [ ] Category color appears in all calendar views
- [ ] Category icons display correctly
- [ ] Categories can be used as filters in search, export, and reports

---

## 13. Layers (Calendar Overlaying)

### 13.1 Data Model

**CURRENT Table:** `webcal_user_layers`

| Column | Type | Description |
|--------|------|-------------|
| `cal_layerid` | INT (PK) | Layer ID |
| `cal_login` | VARCHAR(25) | User who owns this layer |
| `cal_layeruser` | VARCHAR(25) | User whose calendar is being overlaid |
| `cal_color` | VARCHAR(25) | Display color for this layer |
| `cal_dups` | CHAR(1) | `Y`=show duplicates, `N`=hide duplicates |

### 13.2 Key Behaviors (CURRENT)

- **Purpose:** Overlay other users' calendars onto your own view
- **Color coding:** Each layer displays in a distinct color
- **Duplicate handling:** Option to show or hide events that appear in both your calendar and the layer
- **Global control:** Admin setting `LAYERS_STATUS` enables/disables layers feature
- **AJAX editing:** `layers_ajax.php` handles add/edit/delete via JavaScript
- **Public layers:** Admin can manage layers for the public access account

### 13.3 Key Functions (CURRENT)

- `load_user_layers($user)` — Load all layer definitions for a user
- Events from layers are merged into the user's calendar views at render time

### 13.4 Acceptance Criteria

- [ ] Users can add layers to overlay other users' calendars
- [ ] Each layer renders in its configured color
- [ ] Duplicate events can be shown or hidden per layer
- [ ] Layers respect calendar-level access permissions
- [ ] Layers can be enabled/disabled globally by admin

---

## 14. Custom Views

### 14.1 Data Model

**CURRENT Table:** `webcal_view`

| Column | Type | Description |
|--------|------|-------------|
| `cal_view_id` | INT (PK) | View ID |
| `cal_owner` | VARCHAR(25) | View creator login |
| `cal_name` | VARCHAR(50) | View name |
| `cal_view_type` | CHAR(1) | **D**=Day, **W**=Week, **M**=Month |
| `cal_is_global` | CHAR(1) | `Y`=visible to all, `N`=private |

**CURRENT Table:** `webcal_view_user`

| Column | Type | Description |
|--------|------|-------------|
| `cal_view_id` | INT (FK) | View ID |
| `cal_login` | VARCHAR(25) | User to include in view. Special value `__all__` = all visible users. |

### 14.2 Key Behaviors (CURRENT)

- Custom views show multiple users' calendars on a single page
- View types: Day, Week, or Month
- `__all__` wildcard includes all users the owner can see
- Global views are accessible to all users
- Views respect `USER_SEES_ONLY_HIS_GROUPS` setting
- Files: `views.php` (list), `views_edit.php` (create/edit)

### 14.3 Key Functions (CURRENT)

- `view_init($view_id)` — Initialize view and verify permissions
- `view_get_user_list($view_id)` — Get filtered list of users in the view

### 14.4 Acceptance Criteria

- [ ] Users can create custom views combining multiple users' calendars
- [ ] Views support Day, Week, and Month display types
- [ ] `__all__` wildcard correctly expands to all visible users
- [ ] Global views appear in all users' view list
- [ ] Private views are only visible to their creator

---

## 15. Attachments & Comments

### 15.1 Data Model

**CURRENT Table:** `webcal_blob`

| Column | Type | Description |
|--------|------|-------------|
| `cal_blob_id` | INT (PK) | Blob ID |
| `cal_id` | INT (FK) | Event ID |
| `cal_login` | VARCHAR(25) | Creator login |
| `cal_name` | VARCHAR(30) | Filename (attachments) or empty (comments) |
| `cal_description` | VARCHAR(128) | Description or comment subject line |
| `cal_size` | INT | File size in bytes |
| `cal_mime_type` | VARCHAR(50) | MIME type (e.g., `application/pdf`) |
| `cal_type` | CHAR(1) | **A**=Attachment, **C**=Comment |
| `cal_mod_date` | INT | Upload/creation date YYYYMMDD |
| `cal_mod_time` | INT | Upload/creation time HHMMSS |
| `cal_blob` | LONGBLOB | File binary data (attachments only) |

### 15.2 Key Behaviors (CURRENT)

- **Attachments:** Files uploaded to events, stored as BLOBs in the database
- **Comments:** Text notes on events with subject lines, stored in the same table
- **Tracking:** Creator and timestamp recorded for all entries
- **Activity logging:** Attachment additions are tracked via `LOG_ATTACHMENT`

### 15.3 Acceptance Criteria

- [ ] Files can be uploaded and attached to events
- [ ] Attachments can be downloaded by authorized users
- [ ] Comments can be added to events with subject lines
- [ ] Attachment/comment creator and timestamp are displayed
- [ ] Only event participants and users with calendar access can view attachments

---

## 16. Import & Export

### 16.1 Import

**CURRENT:** File: `import.php` / `import_handler.php`

**Supported Import Formats:**

| Format | Description |
|--------|-------------|
| iCalendar (ICS) | Full RFC 5545 support |
| vCalendar (VCS) | Legacy 1.0 format |
| Palm Desktop (PDB) | Palm PDB format with privacy filter |
| Outlook CSV | Microsoft Outlook CSV export |
| Git Log | Convert git commit log to calendar events |

**Import Data Model:**

**Table:** `webcal_import`

| Column | Type | Description |
|--------|------|-------------|
| `cal_import_id` | INT (PK) | Import batch ID |
| `cal_name` | VARCHAR(50) | Import name/description |
| `cal_date` | INT | Import date YYYYMMDD |
| `cal_type` | VARCHAR(10) | Format: `ical`, `vcal`, `palm`, `outlookcsv` |
| `cal_login` | VARCHAR(25) | Importing user |
| `cal_check_date` | INT | Last remote check date (for remote calendars) |
| `cal_md5` | VARCHAR(32) | MD5 hash of remote content (change detection) |

**Table:** `webcal_import_data`

| Column | Type | Description |
|--------|------|-------------|
| `cal_import_id` | INT (FK) | Import batch ID |
| `cal_id` | INT (FK) | WebCalendar event ID created |
| `cal_external_id` | VARCHAR(200) | External UID from source file |

**Key Behaviors:**
- Admin can import events for any user (bulk import)
- Regular users import to their own calendar only
- External UID tracking via `webcal_import_data` enables update detection on re-import
- Remote calendar URLs can be subscribed to and periodically checked

**Key Functions:**
- `load_remote_calendar($url, $login)` — Fetch and import remote iCal feed
- `update_import_check_date($import_id)` — Update last-checked timestamp
- `get_remote_calendar_last_update($import_id)` — Get last remote check time

### 16.2 Export

**CURRENT:** File: `export.php` / `export_handler.php`

**Supported Export Formats:**

| Format | Output |
|--------|--------|
| iCalendar (ICS) | RFC 5545 with VEVENT, VTODO, VJOURNAL, VTIMEZONE |
| HTML | Formatted web page |
| CSV | Spreadsheet-compatible |

**Export Options:**
- Date range (all dates or custom range)
- Category filter
- Include/exclude deleted entries
- Include layer events
- Modified-since date filter

### 16.3 TARGET API Endpoints

```
POST /api/v2/import           # Upload file for import (multipart/form-data)
GET  /api/v2/export?format=ics&start=YYYYMMDD&end=YYYYMMDD&category={id}
```

### 16.4 Acceptance Criteria

- [ ] iCalendar import creates correct events with all properties
- [ ] Repeating events import with full RRULE support
- [ ] VTODO and VJOURNAL components import as tasks and journals
- [ ] Re-import of same file updates existing events (via external ID tracking)
- [ ] iCalendar export produces valid RFC 5545 output
- [ ] Export respects access levels (no private events in export)
- [ ] Remote calendar subscription detects changes via MD5 hash

---

## 17. Search

### 17.1 Overview

**CURRENT:** File: `search.php` / `search_handler.php`

### 17.2 Basic Search

- Keyword search against `webcal_entry.cal_name` (event title)
- Results include both single and repeating event instances
- Autocomplete support for keywords

### 17.3 Advanced Search

Additional filters beyond basic keyword:

| Filter | Options |
|--------|---------|
| Date Range | All, Past only, Upcoming only, Custom range |
| Category | Filter by specific category |
| Site Extras | Filter by custom event field values |
| Users | Search across multiple users' calendars (if allowed) |

### 17.4 Access Control

- Respects `ALLOW_VIEW_OTHER` setting
- Respects `PUBLIC_ACCESS_OTHERS` for public access
- Advanced search restricted by `ACCESS_ADVANCED_SEARCH` when UAC enabled

### 17.5 TARGET API Endpoints

```
GET /api/v2/search?q={keyword}&start=YYYYMMDD&end=YYYYMMDD&category={id}&user={login}
```

### 17.6 Acceptance Criteria

- [ ] Basic search finds events by title keyword
- [ ] Advanced search filters by date range, category, and user
- [ ] Search includes repeating event instances
- [ ] Results respect event access levels (no private events from other users)
- [ ] Search respects UAC function permissions when enabled

---

## 18. Reports

### 18.1 Data Model

**CURRENT Table:** `webcal_report`

| Column | Type | Description |
|--------|------|-------------|
| `cal_report_id` | INT (PK) | Report ID |
| `cal_login` | VARCHAR(25) | Report owner |
| `cal_report_type` | VARCHAR(20) | Time range type |
| `cal_report_name` | VARCHAR(50) | Report name |
| `cal_time_range` | INT | Time range selector |
| `cal_user` | VARCHAR(25) | User to report on |
| `cal_allow_nav` | CHAR(1) | `Y`=allow next/previous navigation |
| `cal_cat_id` | INT | Category filter (0 = all) |
| `cal_include_empty` | CHAR(1) | `Y`=include empty dates |
| `cal_show_in_trailer` | CHAR(1) | `Y`=show link in page footer |
| `cal_is_global` | CHAR(1) | `Y`=visible to all users |
| `cal_update_date` | INT | Last update date YYYYMMDD |

**CURRENT Table:** `webcal_report_template`

| Column | Type | Description |
|--------|------|-------------|
| `cal_report_id` | INT (FK) | Report ID |
| `cal_template_type` | CHAR(1) | **P**=Page, **D**=Date, **E**=Event |
| `cal_template_text` | TEXT | Template content with variable substitution |

### 18.2 Template Variables

**Page Template:** `${days}`

**Date Template:** `${events}`, `${date}`, `${fulldate}`

**Event Template:** `${name}`, `${description}`, `${date}`, `${fulldate}`, `${time}`, `${starttime}`, `${endtime}`, `${duration}`, `${priority}`, `${href}`, `${extra:FieldName}` (site extras)

### 18.3 Report Formats

- HTML (default)
- Plain text
- CSV

### 18.4 Acceptance Criteria

- [ ] Reports can be created with custom templates
- [ ] Template variable substitution works for all documented variables
- [ ] Reports filter by time range, category, and user
- [ ] Global reports are visible to all users
- [ ] Reports support next/previous navigation
- [ ] `${extra:FieldName}` substitution works for site extra fields

---

## 19. Notifications & Reminders

### 19.1 Data Model

**CURRENT Table:** `webcal_reminders`

| Column | Type | Description |
|--------|------|-------------|
| `cal_id` | INT (FK) | Event ID |
| `cal_date` | INT | Absolute reminder date YYYYMMDD (0 = use offset) |
| `cal_time` | INT | Absolute reminder time HHMMSS |
| `cal_duration` | INT | Repeat duration (ISO 8601 minutes) |
| `cal_repeats` | INT | Number of reminder repeats |
| `cal_offset` | INT | Offset in minutes from event edge |
| `cal_related` | CHAR(1) | **S**=relative to Start, **E**=relative to End |
| `cal_before` | CHAR(1) | **Y**=before event, **N**=after event |
| `cal_action` | VARCHAR(12) | Action type: `EMAIL` (extensible) |
| `cal_last_sent` | INT | Last sent timestamp YYYYMMDD |
| `cal_times_sent` | INT | Number of times sent |

**TARGET: New columns for VAlarm compliance** (add to `webcal_reminders`):

| Column | Type | VAlarm Property | Rationale |
|--------|------|----------------|-----------|
| `cal_description` | TEXT DEFAULT NULL | DESCRIPTION | Alarm description text. Required for DISPLAY and EMAIL actions per RFC 5545. |
| `cal_summary` | VARCHAR(255) DEFAULT NULL | SUMMARY | Alarm summary/subject. Required for EMAIL action. |
| `cal_attendee` | VARCHAR(255) DEFAULT NULL | ATTENDEE | Email recipient for EMAIL action. Currently the system uses event participants instead. |
| `cal_attach` | VARCHAR(255) DEFAULT NULL | ATTACH | Attachment URI for EMAIL action. |
| `cal_time` | INT DEFAULT NULL | TRIGGER (absolute) | Absolute trigger time HHMMSS. Current schema has `cal_date` for date but no time field. |

> **For AI Agents:** Map `php-icalendar-core` `VAlarm` properties to these columns. The current schema stores offset-based triggers (cal_offset + cal_related + cal_before) and absolute triggers (cal_date). Adding cal_time completes the absolute trigger. The new text fields complete the DISPLAY and EMAIL alarm property support.

### 19.2 Reminder Types

1. **Offset-based:** Fire N minutes before/after event start or end
2. **Absolute:** Fire at a specific date/time
3. **Repeating:** Re-fire at intervals (e.g., every 15 minutes until acknowledged)

### 19.3 Key Functions (CURRENT)

- `getReminders($event_id, $want_display)` — Get reminders for an event, optionally formatted for display
- Activity log records `LOG_REMINDER` when reminders are sent

### 19.4 Email Notifications (CURRENT)

- PHPMailer library in `includes/classes/phpmailer/`
- Admin email settings: SMTP host, port, auth, from address
- Notifications sent for: event creation, updates, invitations, reminders

### 19.5 TARGET Enhancements (NEW)

- Webhook notifications (POST to external URL on event changes)
- Integration with Zapier/Make via outbound webhooks
- Push notifications for PWA

### 19.6 Acceptance Criteria

- [ ] Reminders fire at the correct time (offset-based and absolute)
- [ ] Email reminders are sent via configured SMTP
- [ ] Repeating reminders re-fire at the configured interval
- [ ] Reminder sent count is tracked to prevent infinite loops
- [ ] Multiple reminders can be set per event

---

## 20. Feeds & Publishing

The core library provides services for generating iCalendar, Free/Busy, and RSS feed content. URL routing and HTTP delivery are frontend concerns.

### 20.1 Calendar Publishing (CURRENT)

**File:** `publish.php`

- Publishes user's calendar as iCalendar (RFC 5545) feed
- Subscribable URL for external calendar clients (Apple Calendar, Google Calendar, Outlook)
- Per-user control via `USER_PUBLISH_ENABLED` preference
- Global control via `PUBLISH_ENABLED` admin setting

### 20.2 Free/Busy Publishing (CURRENT)

**File:** `freebusy.php`

- Publishes RFC 5545 VFREEBUSY data
- Shows busy time blocks without event details (1-year range)
- Per-user control via `FREEBUSY_ENABLED` preference
- Used by other calendar systems for scheduling

### 20.3 RSS Feeds (CURRENT)

| File | Feed Content |
|------|-------------|
| `rss.php` | Upcoming events |
| `rss_unapproved.php` | Pending event approvals |
| `rss_activity_log.php` | Activity log entries |

### 20.4 iCalendar Client (CURRENT)

**File:** `icalclient.php`

- Acts as endpoint for iCal subscription clients
- Serves user's calendar in iCalendar format

### 20.5 TARGET API Endpoints

```
GET /api/v2/feeds/ical/{user}.ics       # iCal subscription feed
GET /api/v2/feeds/freebusy/{user}.ifb   # Free/Busy feed
GET /api/v2/feeds/rss/{user}.xml        # RSS feed
```

### 20.6 Acceptance Criteria

- [ ] iCalendar feed URL is subscribable in Apple Calendar, Google Calendar, and Outlook
- [ ] Free/Busy feed shows only busy times, not event details
- [ ] RSS feeds produce valid RSS 2.0 XML
- [ ] Publishing can be enabled/disabled per user and globally
- [ ] Published feeds respect event access levels

---

## 21. Non-User Calendars (Resources)

### 21.1 Data Model

**CURRENT Table:** `webcal_nonuser_cals`

| Column | Type | Description |
|--------|------|-------------|
| `cal_login` | VARCHAR(25) PK | Resource "login" identifier |
| `cal_lastname` | VARCHAR(25) | Resource display name |
| `cal_firstname` | VARCHAR(25) | Optional first name field |
| `cal_admin` | VARCHAR(25) | Admin user login (who manages this resource) |
| `cal_is_public` | CHAR(1) | `Y`=publicly visible, `N`=restricted |
| `cal_url` | VARCHAR(255) | Remote iCal URL (for remote calendar feeds) |

### 21.2 Key Behaviors (CURRENT)

- **Purpose:** Represent rooms, equipment, shared resources, or remote calendar feeds as "calendars" without real user accounts
- **Admin assignment:** Each resource has a designated admin user
- **Public option:** Resources can be publicly visible without login
- **Remote feeds:** Resources can point to external iCal URLs for read-only display
- **Integration:** Resources appear in layers, custom views, and event participant lists
- **Global control:** Admin setting `NONUSER_ENABLED` enables/disables feature

### 21.3 Key Functions (CURRENT)

- `get_nonuser_cals($user)` — Get resource calendars accessible to user
- `get_my_nonusers($user)` — Get non-user calendars user can see
- `nonuser_load_variables($login, $prefix)` — Load resource calendar info
- `user_is_nonuser_admin($login, $user)` — Check if user is admin for a resource

### 21.4 Files (CURRENT)

- `nonusers.php` — Resource calendar management UI
- `remotecal_mgmt.php` — Remote calendar URL management

### 21.5 Acceptance Criteria

- [ ] Resource calendars can be created for rooms, equipment, etc.
- [ ] Resources can be included as event participants
- [ ] Resource calendars appear in layer and custom view options
- [ ] Remote iCal URLs are fetched and displayed as resource calendars
- [ ] Only designated admin users can manage each resource
- [ ] Public resources are visible without authentication

---

## 22. Assistant / Delegate Support

### 22.1 Data Model

**CURRENT Table:** `webcal_asst`

| Column | Type | Description |
|--------|------|-------------|
| `cal_boss` | VARCHAR(25) | Boss user login |
| `cal_assistant` | VARCHAR(25) | Assistant user login |

### 22.2 Key Behaviors (CURRENT)

- **Boss/Assistant relationship:** One or more assistants can be assigned to a boss
- **Calendar access:** Assistants can view and manage their boss's calendar
- **Approval workflow:** Optional — boss may require approval for events created by assistant (`boss_must_approve_event()`)
- **Notification:** Optional — boss can be notified of assistant-created events (`boss_must_be_notified()`)
- **Confidential access:** Assistants can see confidential events on boss's calendar

### 22.3 Key Functions (CURRENT)

- `user_is_assistant($login, $boss)` — Check if user is assistant to a specific boss
- `user_get_boss_list($assistant)` — Get list of bosses for an assistant
- `boss_must_approve_event($boss)` — Check if boss requires event approval
- `boss_must_be_notified($boss)` — Check if boss requires notification

### 22.4 Files (CURRENT)

- `assistant_edit.php` / `assistant_edit_handler.php` — Manage assistant relationships

### 22.5 Acceptance Criteria

- [ ] Assistants can be assigned to bosses
- [ ] Assistants can view and create events on boss's calendar
- [ ] Approval workflow fires when configured
- [ ] Boss receives notification of assistant-created events when configured
- [ ] Assistants can see confidential events on boss's calendar

---

## 23. Activity Log & Audit Trail

### 23.1 Data Model

**CURRENT Table:** `webcal_entry_log`

| Column | Type | Description |
|--------|------|-------------|
| `cal_log_id` | INT (PK) | Log entry ID |
| `cal_entry_id` | INT | Event ID (0 for system-level events) |
| `cal_login` | VARCHAR(25) | User who performed the action |
| `cal_user_cal` | VARCHAR(25) | Calendar owner affected |
| `cal_type` | CHAR(1) | Log type code (see below) |
| `cal_date` | INT | Log date YYYYMMDD |
| `cal_time` | INT | Log time HHMMSS |
| `cal_text` | TEXT | Optional description text |

### 23.2 Log Type Codes

| Code | Constant | Description |
|------|----------|-------------|
| `C` | `LOG_CREATE` | Event created |
| `A` | `LOG_APPROVE` | Event approved/confirmed |
| `R` | `LOG_REJECT` | Event rejected/declined |
| `U` | `LOG_UPDATE` | Event updated |
| `M` | `LOG_NOTIFICATION` | Mail notification sent |
| `E` | `LOG_REMINDER` | Reminder sent |
| `X` | `LOG_EXTRA` | Site extra field changed |
| — | `LOG_LOGIN_FAILURE` | Failed login attempt |
| — | `LOG_USER_ADD` | User account created |
| — | `LOG_USER_DELETE` | User account deleted |
| — | `LOG_USER_UPDATE` | User account updated |
| — | `LOG_SYSTEM` | System-level event |
| — | `LOG_ATTACHMENT` | File attachment added |
| — | `SECURITY_VIOLATION` | Security violation detected |

### 23.3 Key Functions (CURRENT)

- `activity_log($event_id, $user, $cal_user, $type, $text)` — Record an activity
- `generate_activity_log($startdate, $enddate, $user)` — Retrieve log entries
- `display_activity_log($logs)` — Format log for HTML display

### 23.4 Files (CURRENT)

- `activity_log.php` — Activity log viewer
- Access controlled by `ACCESS_ACTIVITY_LOG` (function ID 14) when UAC enabled

### 23.5 Acceptance Criteria

- [ ] All event CRUD operations are logged with correct type codes
- [ ] Login failures are logged
- [ ] User management actions are logged
- [ ] Activity log can be filtered by date range and user
- [ ] Security violations are logged and flagged
- [ ] Activity log is accessible only to authorized users (admin or UAC-permitted)

---

## 24. Admin Settings

The core library provides a `ConfigService` for reading and writing system settings. The admin UI (tabs, forms) is a frontend concern handled by `webcalendar-web`.

### 24.1 System Settings

| Category | Key Settings |
|----------|-------------|
| **General** | `APPLICATION_NAME`, `LANGUAGE`, `DATE_FORMAT`, `WEEK_START`, `WEEKEND_START`, `WORK_DAY_START_HOUR`, `WORK_DAY_END_HOUR`, `TIME_SLOTS`, `ALLOW_HTML_DESCRIPTION`, `LIMIT_APPTS` |
| **Public Access** | `PUBLIC_ACCESS`, `PUBLIC_ACCESS_FULLNAME`, `PUBLIC_ACCESS_OTHERS`, `PUBLIC_ACCESS_DEFAULT_VISIBLE` |
| **User Access Control** | `UAC_ENABLED`, function access matrix per user, calendar access per user pair |
| **Groups** | `USER_SEES_ONLY_HIS_GROUPS`, group display settings |
| **Resource Calendars** | `NONUSER_ENABLED`, resource calendar settings |
| **Other** | Import format settings, `ALLOW_VIEW_OTHER`, external access settings |
| **MCP Server** | `MCP_SERVER_ENABLED`, `MCP_RATE_LIMIT`, `MCP_CORS_ORIGINS` |
| **Email** | SMTP host/port/auth, notification settings, from address |
| **Colors** | Full color scheme customization (backgrounds, text, borders, etc.) |

### 24.2 System Settings Storage

**CURRENT Table:** `webcal_config`

| Column | Type | Description |
|--------|------|-------------|
| `cal_setting` | VARCHAR(50) PK | Setting name |
| `cal_value` | VARCHAR(100) | Setting value |

### 24.3 Acceptance Criteria

- [ ] ConfigService reads and writes settings to `webcal_config`
- [ ] Settings changes take effect immediately (no cache staleness)
- [ ] Only admin users can modify settings (enforced by PermissionService)
- [ ] Settings validation rejects invalid values
- [ ] API endpoints expose settings read/write for admin users

---

## 25. Security & Sessions

### 25.1 Current Security Features

**CURRENT:**
- **CSRF Protection:** Configurable via `CSRF_PROTECTION` setting
- **CSP Headers:** Content Security Policy (`none`/`same`/`any`) via admin settings
- **SQL Injection:** Parameterized queries via `dbi_execute()` with prepared statements
- **Password Hashing:** bcrypt via `password_hash()`, legacy MD5 auto-upgraded
- **Input Sanitization:** `clean_html()`, `clean_int()`, `clean_word()` functions
- **Account Lockout:** Accounts can be disabled via `cal_enabled = 'N'`
- **Login Failure Logging:** Failed attempts logged to activity log

### 25.2 TARGET Session Management

- **Security Defaults:** HttpOnly, Secure, and SameSite=Lax/Strict flags
- **Hybrid Auth:**
  - **Standalone:** Native PHP sessions with modernized storage
  - **API/MCP:** JWT or scoped Bearer tokens for stateless authentication
  - **WordPress:** Bridge to `wp_get_current_user()`

### 25.3 TARGET Data Protection

- Encryption-at-rest for sensitive event descriptions
- `password_hash()` with Argon2id for standalone deployments

### 25.4 Acceptance Criteria

- [ ] CSRF tokens are validated on all state-changing requests
- [ ] CSP headers are sent on all responses
- [ ] All database queries use parameterized prepared statements
- [ ] Passwords are never stored in plaintext
- [ ] Disabled accounts cannot authenticate
- [ ] Failed login attempts are logged

---

## 26. Internationalization (i18n)

### 26.1 Overview

**CURRENT:** Custom translation system (not gettext). Translation files in `translations/` directory.

### 26.2 Translation Files

**Location:** `translations/{Language}.txt`

**Format:** One translation per line: `abbreviation: translated text`
- `abbreviation: =` means the translation is the same as the abbreviation
- Lines starting with `#` are comments

**Supported Languages:** English, French, German, Spanish, Italian, Portuguese, Dutch, Russian, Japanese, Chinese, Korean, and 10+ others.

### 26.3 Key Functions (CURRENT)

- `translate($text)` — Get translated string (returns original if no translation found)
- `etranslate($text)` — Echo translated string
- `read_trans_file($lang)` — Load and cache translation file
- `reset_language($lang)` — Switch active language

### 26.4 Translation Caching

- Translations are serialized to disk after first load
- Cache is invalidated when translation file modification time changes

### 26.5 Configuration

- **Global default:** `LANGUAGE` setting in admin
- **Per-user override:** `LANGUAGE` preference in user settings
- **Browser detection:** Falls back to `Accept-Language` header

### 26.6 Acceptance Criteria

- [ ] All user-facing strings pass through `translate()` / `etranslate()`
- [ ] Language switching works without page reload artifacts
- [ ] Translation cache invalidates correctly when files change
- [ ] Date and time formatting respects locale settings
- [ ] Right-to-left (RTL) languages display correctly

---

## 27. REST API Architecture

### 27.1 Overview

**TARGET:** All frontends consume JSON REST API. No direct database access from UI layer.

### 27.2 API Endpoints (Complete)

**Events:**
```
GET    /api/v2/events?start=YYYYMMDD&end=YYYYMMDD&user={login}&category={id}
POST   /api/v2/events
GET    /api/v2/events/{id}
PUT    /api/v2/events/{id}
PATCH  /api/v2/events/{id}
DELETE /api/v2/events/{id}
POST   /api/v2/events/{id}/approve
POST   /api/v2/events/{id}/reject
```

**Tasks:**
```
GET    /api/v2/tasks?due_before=YYYYMMDD&status={status}
POST   /api/v2/tasks
PATCH  /api/v2/tasks/{id}
```

**Users:**
```
GET    /api/v2/users
GET    /api/v2/users/{login}
GET    /api/v2/users/{login}/preferences
PUT    /api/v2/users/{login}/preferences
```

**Categories:**
```
GET    /api/v2/categories
POST   /api/v2/categories
PUT    /api/v2/categories/{id}
DELETE /api/v2/categories/{id}
```

**Groups:**
```
GET    /api/v2/groups
POST   /api/v2/groups
GET    /api/v2/groups/{id}
PUT    /api/v2/groups/{id}
DELETE /api/v2/groups/{id}
GET    /api/v2/groups/{id}/members
POST   /api/v2/groups/{id}/members
DELETE /api/v2/groups/{id}/members/{login}
```

**Search:**
```
GET    /api/v2/search?q={keyword}&start=YYYYMMDD&end=YYYYMMDD&category={id}
```

**Import/Export:**
```
POST   /api/v2/import
GET    /api/v2/export?format={ics|csv|html}&start=YYYYMMDD&end=YYYYMMDD
```

**Feeds:**
```
GET    /api/v2/feeds/ical/{user}.ics
GET    /api/v2/feeds/freebusy/{user}.ifb
GET    /api/v2/feeds/rss/{user}.xml
```

**Booking (NEW):**
```
GET    /api/v2/booking/{user}/availability?date=YYYY-MM-DD
POST   /api/v2/booking/{user}/book
```

**Admin:**
```
GET    /api/v2/admin/settings
PUT    /api/v2/admin/settings
GET    /api/v2/admin/activity-log?start=YYYYMMDD&end=YYYYMMDD&user={login}
```

### 27.3 Authentication

- **Session-based:** Cookie + CSRF token for browser clients
- **Token-based:** `Authorization: Bearer {token}` header for API clients
- **API Key:** `X-API-Key: {key}` header for MCP and integrations

### 27.4 OpenAPI Specification

- Complete OpenAPI 3.0 spec in `webcalendar-core/contract/openapi.yaml`
- Auto-generated API documentation
- Client SDK generation support

### 27.5 Webhooks (NEW)

- Outbound webhooks for event creation/update/deletion
- Configurable per user or globally
- Integration with Zapier/Make

### 27.6 Recurrence Handling in API

**TARGET:** To ensure compatibility with modern frontend libraries (e.g., Toast UI Calendar, FullCalendar) and simplify client-side logic, the API employs a hybrid expansion strategy.

#### 27.6.1 Range Queries (Expanded Instances)
For endpoints returning multiple events over a date range (e.g., `/api/v2/views/month`), the API returns **expanded instances**.
- Each occurrence of a repeating series is returned as a distinct object.
- **ID Strategy:** Instances use a composite ID (`{MasterID}_{YYYYMMDD}`) to allow clients to track individual occurrences while maintaining a link to the master.
- **Exceptions:** Modified instances (exceptions) replace the generated occurrence in the result set, ensuring the UI sees the "truth" without complex client-side merging.

#### 27.6.2 Single Event (Master Definition)
For detail endpoints (e.g., `/api/v2/events/{id}`), the API returns the **master definition**.
- Includes the full RFC 5545 `RRULE` string.
- Provides metadata about the series (e.g., total count, exclusion list).

#### 27.6.3 UI Category Mapping
To support modern UI rendering, the API DTOs map the internal `cal_type` and `cal_duration` to standard UI categories:
- **milestone:** Untimed events with 0 duration.
- **task:** Entry where `cal_type = 'T'`.
- **allday:** Entry where `cal_time = -1` or `cal_duration = 1440`.
- **time:** Standard timed events.

### 27.7 Acceptance Criteria

- [ ] All endpoints return JSON with consistent envelope format
- [ ] Authentication is required for all endpoints except public feeds and booking
- [ ] Error responses use standard HTTP status codes with descriptive messages
- [ ] Pagination is supported for list endpoints
- [ ] Rate limiting is enforced per API key

---

## 28. MCP Server (AI Integration)

### 28.1 Overview

**CURRENT:** Implemented in `mcp.php`. Provides Model Context Protocol (JSON-RPC 2.0) access to calendar data.

### 28.2 Transport Modes

Transport configuration (STDIO vs HTTP) is a deployment concern. The core library provides the MCP tool implementations and authentication logic.

| Mode | Usage | Auth Method |
|------|-------|-------------|
| **STDIO** | Local: `php mcp.php` | `MCP_TOKEN` environment variable |
| **HTTP** | Remote: POST to `mcp.php` | `Authorization: Bearer {token}` or `X-MCP-Token` header |

### 28.3 Available Tools

| Tool | Parameters | Description |
|------|-----------|-------------|
| `list_events` | `start_date` (YYYYMMDD), `end_date` (YYYYMMDD) | List events in date range |
| `get_user_info` | (none) | Get authenticated user's profile |
| `search_events` | `keyword`, `limit` (optional) | Search events by keyword |
| `add_event` | `name`, `date` (YYYYMMDD), `description`, `location`, `duration` | Create a new event |

### 28.4 Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| `MCP_SERVER_ENABLED` | `N` | Enable/disable MCP server |
| `MCP_RATE_LIMIT` | `60` | Max requests per user per minute |
| `MCP_CORS_ORIGINS` | (empty) | Allowed CORS origins |

### 28.5 Authentication

- API tokens stored in `webcal_user.cal_api_token` (VARCHAR 64)
- Tokens generated per-user in preferences
- `validate_mcp_token($token)` checks token and returns user login
- `check_mcp_rate_limit($user)` enforces per-user rate limiting

### 28.6 TARGET Enhancements

- Additional tools: `update_event`, `delete_event`, `list_tasks`, `get_availability`
- Resource/prompt support for AI assistant context
- Webhook notifications to AI systems

### 28.7 Acceptance Criteria

- [ ] MCP server responds to valid JSON-RPC 2.0 requests
- [ ] All four tools work correctly (list_events, get_user_info, search_events, add_event)
- [ ] Invalid or missing tokens return authentication errors
- [ ] Rate limiting blocks excessive requests
- [ ] STDIO mode works for local AI assistant integration
- [ ] HTTP mode works for remote API access

---

## 29. Database Support

### 29.1 Current: dbi4php Abstraction Layer

**CURRENT:** File: `includes/dbi4php.php`

**Supported Databases:**

| Database | Status |
|----------|--------|
| MySQL | Primary, well-tested |
| PostgreSQL | Supported |
| SQLite3 | Supported |
| Oracle | Legacy support |
| DB2 | Legacy support |
| ODBC | Legacy support |

**Key Functions:**
- `dbi_execute($sql, $params)` — Execute prepared statement
- `dbi_fetch_row($result)` — Fetch next row
- `dbi_free_result($result)` — Free result set
- `dbi_get_cached_rows($sql, $params)` — Cached query
- `dbi_error()` — Get last error message

### 29.2 TARGET: PDO Migration

- **PDO-Only:** MySQL, PostgreSQL, and SQLite3 via standard PDO drivers
- **Drop:** Oracle, DB2, ODBC (low usage, unmaintained)
- **Prepared Statements:** 100% parameterized queries
- **Repository Pattern:** Database-agnostic interfaces

```php
interface EventRepository {
    public function findById(EventId $id): ?Event;
    public function findByDateRange(DateRange $range, ?string $user = null): array;
    public function save(Event $event): void;
    public function delete(EventId $id): void;
}
```

WordPress uses `$wpdb`, standalone uses PDO.

### 29.3 Acceptance Criteria

- [ ] All queries work on MySQL, PostgreSQL, and SQLite3
- [ ] No raw SQL string concatenation (all parameterized)
- [ ] Repository interfaces are fully implemented for all entities
- [ ] WordPress bridge correctly maps to `$wpdb` methods

---

## 30. Packaging & Configuration

### 30.1 Composer Package

`webcalendar-core` is distributed as a Composer package consumed by frontend projects (`webcalendar-web`, `webcalendar-wp`).

### 30.2 Repository Structure

```
webcalendar-core/
├── src/
│   ├── Domain/           # Entities, Value Objects, Repository interfaces
│   ├── Application/      # Services, DTOs, Contracts
│   └── Infrastructure/   # Repository implementations, iCal integration
├── tests/
├── legacy/               # Legacy WebCalendar code (reference only)
├── composer.json
├── PRD.md
└── CLAUDE.md
```

### 30.3 Configuration Sources

The core library accepts configuration via dependency injection. Consuming projects are responsible for loading configuration from their environment. Supported config sources in the legacy system (for reference):

1. `includes/settings.php` — Created by installer (file-based)
2. Environment variables — For containers (`WEBCALENDAR_USE_ENV=true`)
3. `webcal_config` table — Runtime settings
4. `webcal_user_pref` table — Per-user preferences

**Key Environment Variables (passed by consuming projects):**
- `WEBCALENDAR_DB_TYPE` — Database type
- `WEBCALENDAR_DB_HOST` — Database host
- `WEBCALENDAR_DB_DATABASE` — Database name
- `WEBCALENDAR_DB_LOGIN` — Database user
- `WEBCALENDAR_DB_PASSWORD` — Database password

### 30.4 Legacy Installation Reference

The legacy web wizard (`wizard/index.php`) and headless CLI (`wizard/headless.php`) are in the `legacy/` directory. Schema files in `legacy/wizard/shared/` provide the SQL for table creation and upgrades across all supported databases.

### 30.5 Acceptance Criteria

- [ ] Package installable via `composer require webcalendar/webcalendar-core`
- [ ] All services accept configuration via constructor injection (no global state)
- [ ] Schema migration SQL available for MySQL, PostgreSQL, and SQLite3

---

## 31. Database Schema

### 31.1 Table Prefixes

- **Standalone:** `webcal_` prefix (e.g., `webcal_user`, `webcal_entry`)
- **WordPress:** `{wp_prefix}webcal_` prefix (e.g., `wp_webcal_user`)

### 31.2 Complete Table Reference

| Table | Purpose | Primary Key |
|-------|---------|-------------|
| `webcal_user` | User accounts | `cal_login` |
| `webcal_entry` | Events, tasks, journals | `cal_id` (auto-increment) |
| `webcal_entry_user` | Event participants/status | `(cal_id, cal_login)` |
| `webcal_entry_ext_user` | External participants | `(cal_id, cal_fullname)` |
| `webcal_entry_repeats` | Recurrence rules | `cal_id` (FK) |
| `webcal_entry_repeats_not` | Recurrence exceptions | `(cal_id, cal_date)` |
| `webcal_entry_categories` | Event-category mapping | `(cal_id, cat_id, cat_owner)` |
| `webcal_entry_log` | Activity/audit log | `cal_log_id` |
| `webcal_categories` | Category definitions | `cat_id` |
| `webcal_config` | System settings | `cal_setting` |
| `webcal_user_pref` | User preferences | `(cal_login, cal_setting)` |
| `webcal_user_layers` | Layer definitions | `cal_layerid` |
| `webcal_user_template` | Custom headers/footers | `(cal_login, cal_type)` |
| `webcal_group` | Group definitions | `cal_group_id` |
| `webcal_group_user` | Group memberships | `(cal_group_id, cal_login)` |
| `webcal_view` | Custom view definitions | `cal_view_id` |
| `webcal_view_user` | Users in custom views | `(cal_view_id, cal_login)` |
| `webcal_access_function` | Function-level UAC | `cal_login` |
| `webcal_access_user` | Calendar-level UAC | `(cal_login, cal_other_user)` |
| `webcal_nonuser_cals` | Resource calendars | `cal_login` |
| `webcal_reminders` | Event reminders | `(cal_id)` |
| `webcal_blob` | Attachments & comments | `cal_blob_id` |
| `webcal_import` | Import batch tracking | `cal_import_id` |
| `webcal_import_data` | Imported event ID mapping | `(cal_import_id, cal_id)` |
| `webcal_report` | Report definitions | `cal_report_id` |
| `webcal_report_template` | Report templates | `(cal_report_id, cal_template_type)` |
| `webcal_asst` | Assistant relationships | `(cal_boss, cal_assistant)` |
| `webcal_site_extras` | Custom event fields | `(cal_id, cal_name)` |
| `webcal_timezones` | Timezone definitions | `(tzid)` |

### 31.3 Field Details

All field-level details are documented inline in their respective feature sections (Sections 5-25). Each section includes a **Data Model** subsection with column names, types, and descriptions.

See also: `docs/WebCalendar-Database.md` for additional schema documentation.

---

## Appendix A: Site Extras (Custom Event Fields)

**CURRENT Table:** `webcal_site_extras`

| Column | Type | Description |
|--------|------|-------------|
| `cal_id` | INT (FK) | Event ID |
| `cal_name` | VARCHAR(25) | Field name |
| `cal_type` | INT | Field type (URL, Date, etc.) |
| `cal_date` | INT | Date value YYYYMMDD |
| `cal_remind` | INT | Reminder flag |
| `cal_data` | TEXT | Text value |

**Key Functions:**
- `get_site_extra_fields()` — Get defined custom field names
- `format_site_extras($extras)` — Format for display
- `site_extras_for_popup($event_id)` — Get extras for event tooltip
- Reports support `${extra:FieldName}` template variable substitution

---

## Appendix B: User Templates

**CURRENT Table:** `webcal_user_template`

| Column | Type | Description |
|--------|------|-------------|
| `cal_login` | VARCHAR(25) | User login |
| `cal_type` | CHAR(1) | **H**=Header, **T**=Trailer, **S**=Stylesheet |
| `cal_template_text` | TEXT | Custom HTML/CSS content |

Allows per-user customization of page header, footer, and CSS. The core library stores and retrieves template data; rendering is a frontend concern.

---

## Appendix C: Timezone Support

**CURRENT Table:** `webcal_timezones`

| Column | Type | Description |
|--------|------|-------------|
| `tzid` | VARCHAR(100) PK | Olson timezone ID (e.g., `America/New_York`) |
| `dtstart` | VARCHAR(25) | DST transition start |
| `dtend` | VARCHAR(25) | DST transition end |
| `vtimezone` | TEXT | Full VTIMEZONE component text |

**Key Functions:**
- `add_dstfree_time($date, $minutes)` — Add time accounting for DST transitions

---

## Appendix D: Testing & Quality

- **Unit Testing:** PHPUnit tests in `tests/` directory (`vendor/bin/phpunit -c tests/phpunit.xml`)
- **Compile Check:** `tests/compile_test.sh` verifies all PHP files parse without errors
- **TARGET:** 90%+ coverage for `webcalendar-core` business logic
- **TARGET:** PHPStan Level 8+ for core library
- **TARGET:** API integration tests with PHPUnit
- **TARGET:** Frontend tests with React Testing Library

---

## Appendix E: Migration Path

### Phase 1: Core Extraction
1. Extract business logic from `legacy/includes/functions.php` into service classes
2. Define repository interfaces matching existing `webcal_*` table structure
3. Implement core services with 100% unit test coverage
4. Publish webcalendar-core as Composer package

### Phase 2: REST API Contracts
1. Define OpenAPI specification for all endpoints
2. Implement request/response DTO classes
3. Define authentication contract interfaces
4. Token-based and session-based auth abstractions

### Phase 3: Database Modernization
1. Replace `dbi4php` with PDO-based repository implementations
2. Add RFC 5545 columns to schema (see Appendix F)
3. Provide migration SQL for MySQL, PostgreSQL, SQLite3

### Subsequent Phases (External Projects)

The following phases are handled by separate projects and are listed here for context only:
- **webcalendar-web:** REST API controllers, React SPA, Bootstrap classic mode
- **webcalendar-wp:** WordPress bridge implementations, Gutenberg blocks
- **Legacy deprecation:** Migration tools, archive of legacy repository

---

## Appendix F: RFC 5545 / php-icalendar-core Gap Analysis

This appendix documents the complete gap analysis between the WebCalendar database schema and the `craigk5n/php-icalendar-core` library's property support.

### F.1 VEVENT Property Coverage

| iCal Property | php-icalendar-core | Current DB Column | Status |
|---------------|-------------------|-------------------|--------|
| UID | `VEvent::setUid()` | `webcal_import_data.cal_external_id` only | **GAP** — Add `webcal_entry.cal_uid` |
| DTSTAMP | `VEvent::setDtStamp()` | `cal_mod_date` + `cal_mod_time` | OK |
| DTSTART | `VEvent::setDtStart()` | `cal_date` + `cal_time` | OK |
| DTEND | `VEvent::setDtEnd()` | Derived from `cal_date`+`cal_time`+`cal_duration` | OK |
| DURATION | `VEvent::setDuration()` | `cal_duration` (minutes) | OK |
| SUMMARY | `VEvent::setSummary()` | `cal_name` | OK |
| DESCRIPTION | `VEvent::setDescription()` | `cal_description` | OK |
| LOCATION | `VEvent::setLocation()` | `cal_location` | OK |
| URL | `VEvent::setUrl()` | `cal_url` | OK |
| CATEGORIES | `VEvent::setCategories()` | `webcal_entry_categories` | OK |
| RRULE | `VEvent::setRrule()` | `webcal_entry_repeats` (decomposed) | OK |
| EXDATE | `RecurrenceTrait::addExdate()` | `webcal_entry_repeats_not` (cal_exdate=1) | OK |
| RDATE | `RecurrenceTrait::addRdate()` | `webcal_entry_repeats_not` (cal_exdate=0) | OK |
| PRIORITY | generic `addProperty()` | `cal_priority` | OK |
| CLASS | generic `addProperty()` | `cal_access` (P→PUBLIC, C→CONFIDENTIAL, R→PRIVATE) | OK |
| LAST-MODIFIED | generic `addProperty()` | `cal_mod_date` + `cal_mod_time` | OK |
| STATUS | `VEvent::setStatus()` | **Not in `webcal_entry`** (only in `webcal_entry_user`) | **GAP** — Add `webcal_entry.cal_status` |
| SEQUENCE | generic `addProperty()` | None | **GAP** — Add `webcal_entry.cal_sequence` |
| CREATED | generic `addProperty()` | None | **GAP** — Add `webcal_entry.cal_created` + `cal_created_time` |
| ORGANIZER | generic `addProperty()` | `cal_create_by` (login only, no mailto:) | **GAP** — Add `webcal_entry.cal_organizer` |
| ATTENDEE | generic `addProperty()` | `webcal_entry_user` + `webcal_entry_ext_user` | PARTIAL — no ROLE, CUTYPE, RSVP params |
| GEO | `VEvent::setGeo()` | None | **GAP** — Add `cal_geo_lat` + `cal_geo_lon` |
| TRANSP | generic `addProperty()` | None | **GAP** — Add `webcal_entry.cal_transp` |
| COLOR | `VEvent::setColor()` | None (category has color, not event) | **GAP** — Add `webcal_entry.cal_color` |
| CONFERENCE | `VEvent::setConference()` | None | **GAP** — Add `webcal_entry.cal_conference` |
| IMAGE | `VEvent::setImage()` | None | Low priority, use generic storage |
| COMMENT | generic `addProperty()` | `webcal_blob` (type=C) | OK (different storage model) |
| ATTACH | generic `addProperty()` | `webcal_blob` (type=A) | PARTIAL (BLOBs, not URIs) |
| CONTACT | generic `addProperty()` | None | Low priority, use generic storage |
| RELATED-TO | generic `addProperty()` | None | Low priority, use generic storage |
| REQUEST-STATUS | generic `addProperty()` | None | Low priority, use generic storage |
| RESOURCES | generic `addProperty()` | None | Low priority, use generic storage |
| RECURRENCE-ID | generic `addProperty()` | `cal_group_id` + `cal_ext_for_id` | PARTIAL |

### F.2 VTODO Property Coverage

| iCal Property | php-icalendar-core | Current DB Column | Status |
|---------------|-------------------|-------------------|--------|
| DUE | `VTodo::setDue()` | `cal_due_date` + `cal_due_time` | OK |
| COMPLETED | `VTodo::setCompleted()` | `cal_completed` (date only) | PARTIAL — no time |
| PERCENT-COMPLETE | `VTodo::setPercentComplete()` | `webcal_entry_user.cal_percent` | OK (per-user) |
| PRIORITY | `VTodo::setPriority()` | `cal_priority` | OK |

### F.3 VALARM Property Coverage

| iCal Property | php-icalendar-core | Current DB Column | Status |
|---------------|-------------------|-------------------|--------|
| ACTION | `VAlarm::setAction()` | `cal_action` | OK |
| TRIGGER | `VAlarm::setTrigger()` | `cal_offset` + `cal_related` + `cal_before` OR `cal_date` | OK |
| DURATION | `VAlarm::setDuration()` | `cal_duration` | OK |
| REPEAT | `VAlarm::setRepeat()` | `cal_repeats` | OK |
| DESCRIPTION | `VAlarm::setDescription()` | None | **GAP** — Add `webcal_reminders.cal_description` |
| SUMMARY | `VAlarm::setSummary()` | None | **GAP** — Add `webcal_reminders.cal_summary` |
| ATTENDEE | `VAlarm::setAttendee()` | None | **GAP** — Add `webcal_reminders.cal_attendee` |
| ATTACH | `VAlarm::setAttach()` | None | **GAP** — Add `webcal_reminders.cal_attach` |

### F.4 RRULE Part Coverage

| RRULE Part | php-icalendar-core `RRule` | Current DB Column | Status |
|-----------|---------------------------|-------------------|--------|
| FREQ | `$freq` | `webcal_entry_repeats.cal_type` | OK (different format) |
| INTERVAL | `$interval` | `cal_frequency` | OK |
| COUNT | `$count` | `cal_count` | OK |
| UNTIL | `$until` | `cal_end` + `cal_endtime` | OK |
| BYDAY | `$byDay` | `cal_byday` + `cal_days` (legacy) | OK |
| BYMONTH | `$byMonth` | `cal_bymonth` | OK |
| BYMONTHDAY | `$byMonthDay` | `cal_bymonthday` | OK |
| BYYEARDAY | `$byYearDay` | `cal_byyearday` | OK |
| BYWEEKNO | `$byWeekNo` | `cal_byweekno` | OK |
| BYSETPOS | `$bySetPos` | `cal_bysetpos` | OK |
| WKST | `$wkst` | `cal_wkst` | OK |
| **BYHOUR** | `$byHour` | None | **GAP** — Add `cal_byhour` |
| **BYMINUTE** | `$byMinute` | None | **GAP** — Add `cal_byminute` |
| **BYSECOND** | `$bySecond` | None | **GAP** — Add `cal_bysecond` |

### F.5 Migration SQL

> **For AI Agents:** Execute these ALTER TABLE statements in a database migration. All new columns are nullable with sensible defaults so they are backward-compatible with existing data.

**MySQL:**

```sql
-- webcal_entry: RFC 5545 VEVENT properties
ALTER TABLE webcal_entry ADD COLUMN cal_uid VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_sequence INT DEFAULT 0;
ALTER TABLE webcal_entry ADD COLUMN cal_transp VARCHAR(11) DEFAULT 'OPAQUE';
ALTER TABLE webcal_entry ADD COLUMN cal_status VARCHAR(20) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_geo_lat DECIMAL(10,7) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_geo_lon DECIMAL(10,7) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_color VARCHAR(16) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_conference VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_organizer VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_created INT DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_created_time INT DEFAULT NULL;
CREATE INDEX idx_webcal_entry_uid ON webcal_entry(cal_uid);

-- webcal_entry_repeats: Complete RRULE support
ALTER TABLE webcal_entry_repeats ADD COLUMN cal_byhour VARCHAR(50) DEFAULT NULL;
ALTER TABLE webcal_entry_repeats ADD COLUMN cal_byminute VARCHAR(50) DEFAULT NULL;
ALTER TABLE webcal_entry_repeats ADD COLUMN cal_bysecond VARCHAR(50) DEFAULT NULL;

-- webcal_reminders: Complete VALARM support
ALTER TABLE webcal_reminders ADD COLUMN cal_description TEXT DEFAULT NULL;
ALTER TABLE webcal_reminders ADD COLUMN cal_summary VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_reminders ADD COLUMN cal_attendee VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_reminders ADD COLUMN cal_attach VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_reminders ADD COLUMN cal_time INT DEFAULT NULL;

-- Backfill UIDs for existing events (generate UUID-style UIDs)
UPDATE webcal_entry SET cal_uid = CONCAT(cal_id, '-', UNIX_TIMESTAMP(), '@webcalendar')
  WHERE cal_uid IS NULL;
```

**PostgreSQL:**

```sql
ALTER TABLE webcal_entry ADD COLUMN cal_uid VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_sequence INT DEFAULT 0;
ALTER TABLE webcal_entry ADD COLUMN cal_transp VARCHAR(11) DEFAULT 'OPAQUE';
ALTER TABLE webcal_entry ADD COLUMN cal_status VARCHAR(20) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_geo_lat DECIMAL(10,7) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_geo_lon DECIMAL(10,7) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_color VARCHAR(16) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_conference VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_organizer VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_created INT DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_created_time INT DEFAULT NULL;
CREATE INDEX idx_webcal_entry_uid ON webcal_entry(cal_uid);

ALTER TABLE webcal_entry_repeats ADD COLUMN cal_byhour VARCHAR(50) DEFAULT NULL;
ALTER TABLE webcal_entry_repeats ADD COLUMN cal_byminute VARCHAR(50) DEFAULT NULL;
ALTER TABLE webcal_entry_repeats ADD COLUMN cal_bysecond VARCHAR(50) DEFAULT NULL;

ALTER TABLE webcal_reminders ADD COLUMN cal_description TEXT DEFAULT NULL;
ALTER TABLE webcal_reminders ADD COLUMN cal_summary VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_reminders ADD COLUMN cal_attendee VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_reminders ADD COLUMN cal_attach VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_reminders ADD COLUMN cal_time INT DEFAULT NULL;

UPDATE webcal_entry SET cal_uid = cal_id || '-' || EXTRACT(EPOCH FROM NOW())::INT || '@webcalendar'
  WHERE cal_uid IS NULL;
```

**SQLite3:**

```sql
ALTER TABLE webcal_entry ADD COLUMN cal_uid VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_sequence INT DEFAULT 0;
ALTER TABLE webcal_entry ADD COLUMN cal_transp VARCHAR(11) DEFAULT 'OPAQUE';
ALTER TABLE webcal_entry ADD COLUMN cal_status VARCHAR(20) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_geo_lat DECIMAL(10,7) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_geo_lon DECIMAL(10,7) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_color VARCHAR(16) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_conference VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_organizer VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_created INT DEFAULT NULL;
ALTER TABLE webcal_entry ADD COLUMN cal_created_time INT DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_webcal_entry_uid ON webcal_entry(cal_uid);

ALTER TABLE webcal_entry_repeats ADD COLUMN cal_byhour VARCHAR(50) DEFAULT NULL;
ALTER TABLE webcal_entry_repeats ADD COLUMN cal_byminute VARCHAR(50) DEFAULT NULL;
ALTER TABLE webcal_entry_repeats ADD COLUMN cal_bysecond VARCHAR(50) DEFAULT NULL;

ALTER TABLE webcal_reminders ADD COLUMN cal_description TEXT DEFAULT NULL;
ALTER TABLE webcal_reminders ADD COLUMN cal_summary VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_reminders ADD COLUMN cal_attendee VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_reminders ADD COLUMN cal_attach VARCHAR(255) DEFAULT NULL;
ALTER TABLE webcal_reminders ADD COLUMN cal_time INT DEFAULT NULL;

UPDATE webcal_entry SET cal_uid = cal_id || '-' || strftime('%s','now') || '@webcalendar'
  WHERE cal_uid IS NULL;
```

### F.6 Properties Deferred to Generic Storage

These RFC 5545 properties are low-priority and do not warrant dedicated columns. They can be stored using the existing `webcal_site_extras` table or a new `webcal_entry_properties` table if needed:

- CONTACT, RELATED-TO, REQUEST-STATUS, RESOURCES, IMAGE
- ATTENDEE parameters: ROLE, CUTYPE, RSVP (could extend `webcal_entry_user` later)
- X-properties (vendor extensions like X-APPLE-STRUCTURED-LOCATION)

### F.7 Summary

| Area | Current Coverage | After Migration |
|------|-----------------|-----------------|
| VEVENT properties | 13 of 24 common properties | 23 of 24 (IMAGE deferred) |
| VTODO properties | All covered | All covered |
| VJOURNAL properties | All covered | All covered |
| VALARM properties | 4 of 8 | 8 of 8 |
| RRULE parts | 11 of 14 | 14 of 14 (complete) |

**Total new columns:** 19 (11 on `webcal_entry`, 3 on `webcal_entry_repeats`, 5 on `webcal_reminders`)

---

## Appendix G: Epics & User Stories

This appendix provides a complete, prioritized backlog of epics and user stories for the WebCalendar modern rewrite. Each story is self-contained with acceptance criteria, sizing, and dependency information.

### G.0 Conventions

**Roles:**
- **Developer** — backend/full-stack engineer implementing the system
- **User** — authenticated calendar user
- **Admin** — system administrator
- **External Visitor** — unauthenticated person (booking pages, public feeds)
- **API Consumer** — external system or AI agent calling the REST/MCP API
- **WP Admin** — WordPress site administrator

**Priority:**
- **P0** — Must have for MVP. Blocks other work.
- **P1** — Should have. Required for feature parity with legacy app.
- **P2** — Nice to have. New features or enhancements.

**Size** (estimated developer-days):
- **S** — 1–3 days
- **M** — 3–5 days
- **L** — 5–10 days
- **XL** — 10+ days

**Global Definition of Done:**
1. Code follows PSR-12 and project style (2-space indent, 80-char lines)
2. PHPUnit tests cover all new public methods (≥90% branch coverage for core)
3. No PHPStan Level 6+ errors introduced
4. All existing tests still pass
5. Database changes include migration SQL for MySQL, PostgreSQL, and SQLite3
6. API changes include updated OpenAPI spec
7. `> For AI Agents:` notes in related PRD sections are satisfied

**Story ID format:** `S-{epic}.{sequence}` (e.g., S-1.3 = Epic 1, Story 3)

---

### Epic 1: Core Library Foundation

**Goal:** Extract business logic from `includes/functions.php` into a standalone `webcalendar-core` Composer package with clean architecture.
**Phase:** 1 (Months 1–3) | **PRD Refs:** Sections 3, 3.5

#### S-1.1: Initialize webcalendar-core Composer package

- **As a** developer, **I want** a new `webcalendar-core` Composer package with namespace `WebCalendar\Core`, **so that** business logic is decoupled from the legacy app.
- **Priority:** P0 | **Size:** S
- **Acceptance Criteria:**
  - [ ] `composer.json` with `craigk5n/webcalendar-core` package name, PHP 8.1+ requirement, PSR-4 autoload for `WebCalendar\Core\`
  - [ ] Directory structure matches Section 3.1 (`Domain/`, `Application/`, `Infrastructure/`, `Contract/`)
  - [ ] `declare(strict_types=1)` on all files
  - [ ] `craigk5n/php-icalendar-core` listed as a dependency

#### S-1.2: Define domain entities

- **As a** developer, **I want** immutable domain entity classes (Event, User, Category, Group, Layer, Reminder, Resource), **so that** business objects have explicit types and validation.
- **Priority:** P0 | **Size:** M | **Depends on:** S-1.1
- **PRD Ref:** Sections 5.1, 8.1, 11.1, 12.1, 13.1, 19.1, 21.1
- **Acceptance Criteria:**
  - [ ] Each entity maps to its `webcal_*` table with typed properties matching documented column names
  - [ ] `Event` entity includes all current + new RFC 5545 columns (Section 5.1)
  - [ ] Value objects created for `EventId`, `DateRange`, `RecurrenceRule`, `AccessLevel`, `EventType`
  - [ ] All entities are unit-tested with factory methods

#### S-1.3: Define repository interfaces

- **As a** developer, **I want** repository interfaces in `Domain\Repository\`, **so that** persistence is abstracted and multiple backends (PDO, WPDB) can be supported.
- **Priority:** P0 | **Size:** M | **Depends on:** S-1.2
- **PRD Ref:** Section 3.1, 31.2
- **Acceptance Criteria:**
  - [ ] Interfaces defined: `EventRepository`, `UserRepository`, `CategoryRepository`, `GroupRepository`, `ReminderRepository`, `ResourceRepository`, `ConfigRepository`
  - [ ] Each interface has `findById()`, `findBy*()`, `save()`, `delete()` methods with typed signatures
  - [ ] `EventRepository::findByDateRange()` accepts `DateRange` value object
  - [ ] No implementation code in `Domain\` — interfaces only

#### S-1.4: Define contract interfaces for external dependencies

- **As a** developer, **I want** `AuthenticationProvider`, `DatabaseConnection`, and `Logger` interfaces, **so that** the core library has zero coupling to specific frameworks.
- **Priority:** P0 | **Size:** S | **Depends on:** S-1.1
- **PRD Ref:** Section 3.4
- **Acceptance Criteria:**
  - [ ] Interfaces match Section 3.4 signatures exactly
  - [ ] PSR-3 `LoggerInterface` used (or thin wrapper)
  - [ ] No `use` statements referencing WordPress, PHP sessions, or specific DB drivers

#### S-1.5: Implement core service classes (stubs)

- **As a** developer, **I want** service class skeletons with constructor DI, **so that** the service layer architecture is established before implementation.
- **Priority:** P0 | **Size:** M | **Depends on:** S-1.3, S-1.4
- **PRD Ref:** Section 3.2
- **Acceptance Criteria:**
  - [ ] All 9 services from Section 3.2 created: `EventService`, `UserService`, `PermissionService`, `RecurrenceService`, `NotificationService`, `ImportExportService`, `CategoryService`, `GroupService`, `SearchService`
  - [ ] Each service accepts repository interfaces and contract interfaces via constructor
  - [ ] Public method signatures match Section 3.2 table
  - [ ] Services are stateless (no instance-level mutable state)

#### S-1.6: Integrate php-icalendar-core for recurrence

- **As a** developer, **I want** `RecurrenceService` to use `php-icalendar-core`'s `RecurrenceExpander`, **so that** occurrence generation is RFC 5545 compliant and replaces the legacy `get_all_dates()`.
- **Priority:** P0 | **Size:** L | **Depends on:** S-1.5
- **PRD Ref:** Sections 3.5, 6.3
- **Acceptance Criteria:**
  - [ ] `RecurrenceService::expand()` delegates to `RecurrenceExpander::expand()`
  - [ ] Bidirectional conversion between DB columns (`webcal_entry_repeats`) and `RRule` objects
  - [ ] All 14 RRULE parts round-trip correctly (including BYHOUR, BYMINUTE, BYSECOND)
  - [ ] EXDATE/RDATE handled via `RecurrenceTrait`
  - [ ] Legacy `get_all_dates()` test cases ported and passing

#### S-1.7: Integrate php-icalendar-core for import/export

- **As a** developer, **I want** `ImportExportService` to use `php-icalendar-core` Parser/Writer, **so that** iCal import/export is RFC 5545 compliant.
- **Priority:** P0 | **Size:** L | **Depends on:** S-1.6
- **PRD Ref:** Sections 3.5, 16
- **Acceptance Criteria:**
  - [ ] `importICal()` uses `Parser::parse()` → maps `VEvent`/`VTodo`/`VJournal` to domain entities
  - [ ] `exportICal()` builds component objects from entities → `Writer::write()` → ICS string
  - [ ] All RFC 5545 properties with dedicated DB columns are mapped (per Appendix F)
  - [ ] `$component->validate()` called before import; invalid components logged and skipped
  - [ ] Round-trip test: export → re-import → compare entities

---

### Epic 2: Database Layer Modernization

**Goal:** Replace `dbi4php.php` with PDO, implement repository pattern, and run RFC 5545 schema migration.
**Phase:** 1 (Months 1–3) | **PRD Refs:** Sections 29, 31, Appendix F

#### S-2.1: PDO connection factory

- **As a** developer, **I want** a PDO connection factory implementing `DatabaseConnection`, **so that** all queries go through a single, configured connection.
- **Priority:** P0 | **Size:** M | **Depends on:** S-1.4
- **PRD Ref:** Section 29.2
- **Acceptance Criteria:**
  - [ ] Supports MySQL, PostgreSQL, SQLite3 via DSN strings
  - [ ] Reads config from `includes/settings.php` or env vars (`WEBCALENDAR_DB_*`)
  - [ ] Sets PDO error mode to `ERRMODE_EXCEPTION`
  - [ ] All queries use prepared statements (no string interpolation)

#### S-2.2: Implement EventRepository (PDO)

- **As a** developer, **I want** a PDO-backed `EventRepository`, **so that** event CRUD uses the new data layer.
- **Priority:** P0 | **Size:** L | **Depends on:** S-2.1, S-1.2
- **PRD Ref:** Sections 5.1, 6.1
- **Acceptance Criteria:**
  - [ ] `findById()` returns `Event` entity with all columns including new RFC 5545 fields
  - [ ] `findByDateRange()` queries `webcal_entry` + `webcal_entry_user` with date filtering
  - [ ] `save()` handles INSERT (new) and UPDATE (existing) based on `cal_id` presence
  - [ ] Repeating event data (`webcal_entry_repeats`) loaded/saved in the same transaction
  - [ ] Integration tests against SQLite3 in-memory DB

#### S-2.3: Implement remaining repositories (PDO)

- **As a** developer, **I want** PDO implementations for all repository interfaces, **so that** the full data layer is operational.
- **Priority:** P0 | **Size:** L | **Depends on:** S-2.2
- **PRD Ref:** Section 29.2
- **Acceptance Criteria:**
  - [ ] `UserRepository`, `CategoryRepository`, `GroupRepository`, `ReminderRepository`, `ResourceRepository`, `ConfigRepository` all implemented
  - [ ] Each passes integration tests against SQLite3
  - [ ] Query patterns match existing `dbi_execute()` queries in `includes/functions.php`

#### S-2.4: RFC 5545 schema migration

- **As a** developer, **I want** the 19 new columns from Appendix F added to the database, **so that** full iCal property storage is available.
- **Priority:** P0 | **Size:** M | **Depends on:** S-2.1
- **PRD Ref:** Appendix F.5
- **Acceptance Criteria:**
  - [ ] Migration SQL runs without errors on MySQL 8.0, PostgreSQL 14, SQLite3
  - [ ] 11 new columns on `webcal_entry` (cal_uid through cal_created_time)
  - [ ] 3 new columns on `webcal_entry_repeats` (cal_byhour, cal_byminute, cal_bysecond)
  - [ ] 5 new columns on `webcal_reminders` (cal_description, cal_summary, cal_attendee, cal_attach, cal_time)
  - [ ] UID backfill runs for existing events
  - [ ] Migration integrated into `wizard/shared/upgrade-sql.php`

#### S-2.5: WordPress database bridge (WPDB)

- **As a** developer, **I want** repository implementations using WordPress `$wpdb`, **so that** the WP plugin uses native WP database access.
- **Priority:** P1 | **Size:** L | **Depends on:** S-1.3
- **PRD Ref:** Section 5.2
- **Acceptance Criteria:**
  - [ ] `WpDatabaseConnection` wraps `$wpdb->prepare()` and `$wpdb->get_results()`
  - [ ] Table names use `{$wpdb->prefix}webcal_*` prefixing
  - [ ] All repository methods work identically to PDO versions
  - [ ] Integration tests run in WP test environment

---

### Epic 3: REST API

**Goal:** Build a complete JSON REST API consumed by all frontends.
**Phase:** 2 (Months 2–4) | **PRD Refs:** Section 27

#### S-3.1: API router and middleware framework

- **As a** developer, **I want** a lightweight PHP router with middleware support, **so that** API endpoints have consistent auth, error handling, and JSON formatting.
- **Priority:** P0 | **Size:** M
- **PRD Ref:** Section 27
- **Acceptance Criteria:**
  - [ ] Routes map `METHOD /path` → controller method
  - [ ] Middleware chain: CORS → Auth → Rate Limit → Controller → JSON Response
  - [ ] Consistent JSON envelope: `{ "data": ..., "meta": { "page", "total" }, "errors": [...] }`
  - [ ] Standard HTTP error codes (400, 401, 403, 404, 422, 429, 500) with descriptive messages
  - [ ] OpenAPI 3.0 spec file generated/maintained

#### S-3.2: Authentication middleware (session + token + API key)

- **As a** developer, **I want** three auth methods supported in the API middleware, **so that** browsers, API clients, and MCP can all authenticate.
- **Priority:** P0 | **Size:** M | **Depends on:** S-3.1
- **PRD Ref:** Sections 27.3, 27.2
- **Acceptance Criteria:**
  - [ ] Session-based: Cookie + CSRF token validation for browser clients
  - [ ] Token-based: `Authorization: Bearer {token}` header for API clients
  - [ ] API Key: `X-API-Key: {key}` header for MCP and integrations
  - [ ] Failed auth returns 401 with `WWW-Authenticate` header
  - [ ] Rate limiting enforced per API key (default 60/min)

#### S-3.3: Event CRUD endpoints

- **As an** API consumer, **I want** full CRUD endpoints for events, **so that** I can manage calendar events programmatically.
- **Priority:** P0 | **Size:** L | **Depends on:** S-3.2, S-2.2
- **PRD Ref:** Section 5.5
- **Acceptance Criteria:**
  - [ ] All 8 endpoints from Section 5.5 implemented (GET list, POST create, GET by ID, PUT, PATCH, DELETE, approve, reject)
  - [ ] `GET /events` supports query params: `start`, `end`, `user`, `category`
  - [ ] `POST /events` validates required fields and returns 201 with created entity
  - [ ] `PATCH /events/{id}` supports partial updates (drag-and-drop rescheduling)
  - [ ] Access control enforced: private events hidden, confidential events redacted, UAC respected
  - [ ] Pagination on list endpoint (`page`, `per_page` params)

#### S-3.4: User, category, group, and supporting endpoints

- **As an** API consumer, **I want** endpoints for users, categories, and groups, **so that** all supporting entities are accessible via the API.
- **Priority:** P0 | **Size:** L | **Depends on:** S-3.2, S-2.3
- **PRD Ref:** Sections 8, 12.4, 11.4
- **Acceptance Criteria:**
  - [ ] User endpoints: GET list, GET by login, GET/PUT preferences (Section 27.2)
  - [ ] Category endpoints: GET list, POST, PUT, DELETE (Section 12.4)
  - [ ] Group endpoints: GET list, POST, GET/PUT/DELETE by ID, member management (Section 11.4)
  - [ ] Task endpoints: GET list, POST, PATCH (Section 7.6)
  - [ ] Search endpoint: GET with `q`, `start`, `end`, `category`, `user` params (Section 17.5)

#### S-3.5: Import/export and feed endpoints

- **As an** API consumer, **I want** import, export, and feed endpoints, **so that** data exchange works through the API.
- **Priority:** P1 | **Size:** M | **Depends on:** S-3.2, S-1.7
- **PRD Ref:** Sections 16.3, 20.5
- **Acceptance Criteria:**
  - [ ] `POST /import` accepts `multipart/form-data` with ICS/CSV files
  - [ ] `GET /export` supports `format=ics|csv|html` with date range and category filters
  - [ ] `GET /feeds/ical/{user}.ics` serves subscribable iCal feed
  - [ ] `GET /feeds/freebusy/{user}.ifb` serves VFREEBUSY data
  - [ ] Feed endpoints require no auth (public) or token auth (private)

#### S-3.6: Admin and activity log endpoints

- **As an** admin, **I want** API endpoints for system settings and activity logs, **so that** the admin interface can be API-driven.
- **Priority:** P1 | **Size:** M | **Depends on:** S-3.2
- **PRD Ref:** Section 27.2
- **Acceptance Criteria:**
  - [ ] `GET/PUT /admin/settings` reads/writes `webcal_config` table
  - [ ] `GET /admin/activity-log` supports `start`, `end`, `user` query params
  - [ ] Admin-only endpoints return 403 for non-admin users
  - [ ] Settings changes take effect immediately (no server restart)

---

### Epic 4: Event Lifecycle

**Goal:** Full event lifecycle — CRUD, participants, conflicts, approval, repeating events, tasks, journals.
**Phase:** 1–2 | **PRD Refs:** Sections 5, 6, 7

#### S-4.1: Implement EventService.create() and update()

- **As a** user, **I want** to create and update events with all fields, **so that** my calendar entries are complete and accurate.
- **Priority:** P0 | **Size:** L | **Depends on:** S-1.5, S-2.2
- **PRD Ref:** Section 5
- **Acceptance Criteria:**
  - [ ] Create event with: title, date, time, duration, location, URL, description, priority, access level, type
  - [ ] `cal_uid` auto-generated on create (format: `{uuid}@webcalendar`)
  - [ ] `cal_sequence` incremented on every update
  - [ ] `cal_created` / `cal_created_time` set on create, never modified
  - [ ] `cal_mod_date` / `cal_mod_time` updated on every save
  - [ ] Activity log entry created for each create (LOG_CREATE) and update (LOG_UPDATE)

#### S-4.2: Participant management

- **As a** user, **I want** to invite participants to events and track their responses, **so that** I can coordinate meetings.
- **Priority:** P0 | **Size:** M | **Depends on:** S-4.1
- **PRD Ref:** Section 5.1 (webcal_entry_user, webcal_entry_ext_user)
- **Acceptance Criteria:**
  - [ ] Internal participants added via `webcal_entry_user` with initial status `W` (Waiting)
  - [ ] External participants added via `webcal_entry_ext_user` with name and email
  - [ ] Participants can accept (A), reject (R), or tentatively accept their invitation
  - [ ] Group invitations expand to all group members
  - [ ] Email notification sent to participants when invited (if notifications enabled)

#### S-4.3: Conflict detection

- **As a** user, **I want** to be warned when a new event overlaps with existing events, **so that** I avoid double-booking.
- **Priority:** P1 | **Size:** M | **Depends on:** S-4.1
- **PRD Ref:** Section 5.2
- **Acceptance Criteria:**
  - [ ] `EventService::checkConflicts()` detects overlapping events for all participants
  - [ ] Respects `LIMIT_APPTS` system setting
  - [ ] Ignores rejected/deleted participant status
  - [ ] Repeating event instances included in conflict check
  - [ ] Returns list of conflicting events (not just boolean)

#### S-4.4: Approval workflow

- **As an** admin, **I want** events to require approval before becoming visible, **so that** I can moderate calendar content.
- **Priority:** P1 | **Size:** M | **Depends on:** S-4.1
- **PRD Ref:** Section 5.3
- **Acceptance Criteria:**
  - [ ] When `REQUIRE_APPROVALS` is enabled, new events get status `W` (Waiting)
  - [ ] Users with approve permission can approve (→ `A`) or reject (→ `R`) events
  - [ ] `DISPLAY_UNAPPROVED` controls whether pending events appear in views
  - [ ] Activity log records approval/rejection with LOG_APPROVE / LOG_REJECT
  - [ ] Boss-assistant approval workflow respects `boss_must_approve_event()` setting

#### S-4.5: Repeating event expansion

- **As a** user, **I want** repeating events to appear on all correct dates, **so that** my recurring meetings show up reliably.
- **Priority:** P0 | **Size:** L | **Depends on:** S-1.6, S-4.1
- **PRD Ref:** Section 6
- **Acceptance Criteria:**
  - [ ] All 6 recurrence types expand correctly: daily, weekly, monthlyByDate, monthlyByDay, monthlyBySetPos, yearly
  - [ ] EXDATE exclusions skip specific dates
  - [ ] RDATE additions include extra dates
  - [ ] Exception events (cal_group_id → replacement event) render on the correct instance
  - [ ] COUNT and UNTIL termination conditions both work
  - [ ] Weekly recurrence with specific days (e.g., MWF via `cal_days`) works correctly

#### S-4.6: Tasks and journals

- **As a** user, **I want** to create tasks with due dates and journals with text entries, **so that** I can track to-dos and notes alongside events.
- **Priority:** P1 | **Size:** M | **Depends on:** S-4.1
- **PRD Ref:** Section 7
- **Acceptance Criteria:**
  - [ ] Tasks created with `cal_type=T`, due date/time, priority
  - [ ] Task status transitions: pending → in-progress (P) → completed (C) with percent tracking
  - [ ] `cal_completed` set when task marked complete
  - [ ] Journals created with `cal_type=J`, date, description (no time/duration)
  - [ ] iCal export emits VTODO for tasks and VJOURNAL for journals

---

### Epic 5: User & Access Management

**Goal:** User lifecycle, pluggable authentication, preferences, UAC, groups, and assistants.
**Phase:** 1–2 | **PRD Refs:** Sections 8, 9, 11, 22

#### S-5.1: Implement UserService

- **As a** developer, **I want** `UserService` with auth, preferences, and profile management, **so that** user operations are centralized.
- **Priority:** P0 | **Size:** M | **Depends on:** S-1.5, S-2.3
- **PRD Ref:** Section 8
- **Acceptance Criteria:**
  - [ ] `authenticate($login, $password)` validates credentials via `AuthenticationProvider`
  - [ ] Password hashing uses `password_hash()` with bcrypt (current) or Argon2id (target)
  - [ ] Legacy MD5 passwords auto-upgraded to bcrypt on successful login
  - [ ] `getPreferences($user)` loads from `webcal_user_pref`
  - [ ] `updatePreferences($user, $prefs)` persists changed preferences

#### S-5.2: Implement PermissionService (UAC)

- **As a** developer, **I want** `PermissionService` enforcing all three UAC tiers, **so that** access control is centralized and testable.
- **Priority:** P0 | **Size:** L | **Depends on:** S-1.5, S-2.3
- **PRD Ref:** Section 9
- **Acceptance Criteria:**
  - [ ] `canAccessFunction($functionId, $user)` checks `webcal_access_function` bitmask
  - [ ] `canViewCalendar($viewer, $owner)` checks `webcal_access_user.cal_can_view` bitfield
  - [ ] `canEditCalendar($editor, $owner)` checks `cal_can_edit`
  - [ ] `canApproveCalendar($approver, $owner)` checks `cal_can_approve`
  - [ ] Event-level: Public/Confidential/Private enforcement with correct bitfield for events vs tasks vs journals
  - [ ] Admin users bypass all checks
  - [ ] When `UAC_ENABLED=N`, all checks return true

#### S-5.3: Implement GroupService

- **As a** user, **I want** to create groups and manage members, **so that** I can invite teams to events efficiently.
- **Priority:** P1 | **Size:** M | **Depends on:** S-1.5, S-2.3
- **PRD Ref:** Section 11
- **Acceptance Criteria:**
  - [ ] Create, update, delete groups via `webcal_group` / `webcal_group_user`
  - [ ] `getMembers($groupId)` returns member list
  - [ ] `USER_SEES_ONLY_HIS_GROUPS` setting restricts user visibility
  - [ ] Group owners and admins can manage groups; regular users only own groups they created

#### S-5.4: Implement assistant/delegate relationships

- **As a** user, **I want** to designate assistants who can manage my calendar, **so that** my admin can schedule meetings on my behalf.
- **Priority:** P1 | **Size:** S | **Depends on:** S-5.2
- **PRD Ref:** Section 22
- **Acceptance Criteria:**
  - [ ] `webcal_asst` table CRUD for boss-assistant pairs
  - [ ] `user_is_assistant()` check integrated into `PermissionService`
  - [ ] Assistants can see confidential events on boss's calendar
  - [ ] `boss_must_approve_event()` and `boss_must_be_notified()` settings respected

---

### Epic 6: Calendar Features

**Goal:** Views, layers, custom views, categories, search, and reports.
**Phase:** 1–2 | **PRD Refs:** Sections 4, 12, 13, 14, 17, 18

#### S-6.1: Implement CategoryService

- **As a** user, **I want** to create personal categories and assign them to events, **so that** I can organize my calendar visually.
- **Priority:** P0 | **Size:** M | **Depends on:** S-1.5, S-2.3
- **PRD Ref:** Section 12
- **Acceptance Criteria:**
  - [ ] Global categories (admin-created, `cat_owner IS NULL`) visible to all users
  - [ ] User categories visible only to owner
  - [ ] Multi-category assignment via `webcal_entry_categories`
  - [ ] Per-user category assignment on the same event
  - [ ] Category color and icon data accessible

#### S-6.2: Calendar view data service

- **As a** user, **I want** the API to return events formatted for day/week/month/year views, **so that** the frontend can render any calendar view.
- **Priority:** P0 | **Size:** L | **Depends on:** S-4.1, S-4.5, S-5.2
- **PRD Ref:** Section 4
- **Acceptance Criteria:**
  - [ ] `GET /views/day` returns events for a single day (single + repeating instances)
  - [ ] `GET /views/week` returns events for a 7-day range
  - [ ] `GET /views/month` returns events for a full month
  - [ ] `GET /views/year` returns per-day event counts for the year
  - [ ] All views enforce access levels (private hidden, confidential redacted)
  - [ ] User's `STARTVIEW` preference determines default view

#### S-6.3: Layer support

- **As a** user, **I want** to overlay other users' calendars on my view, **so that** I can see colleagues' availability.
- **Priority:** P1 | **Size:** M | **Depends on:** S-6.2
- **PRD Ref:** Section 13
- **Acceptance Criteria:**
  - [ ] CRUD for `webcal_user_layers`
  - [ ] Layer events merged into view data with distinct color
  - [ ] Duplicate detection respects `cal_dups` setting per layer
  - [ ] Layers respect calendar-level access permissions
  - [ ] `LAYERS_STATUS` admin setting enables/disables feature globally

#### S-6.4: Custom views

- **As a** user, **I want** to create custom views showing multiple users' calendars, **so that** I can see my team's schedule on one page.
- **Priority:** P1 | **Size:** M | **Depends on:** S-6.2
- **PRD Ref:** Section 14
- **Acceptance Criteria:**
  - [ ] Create custom views with Day/Week/Month type and user list
  - [ ] `__all__` wildcard expands to all visible users
  - [ ] Global views visible to all users; private views only to creator
  - [ ] Respects `USER_SEES_ONLY_HIS_GROUPS` setting

#### S-6.5: Implement SearchService

- **As a** user, **I want** to search events by keyword, date range, category, and user, **so that** I can find specific calendar entries.
- **Priority:** P1 | **Size:** M | **Depends on:** S-4.1, S-5.2
- **PRD Ref:** Section 17
- **Acceptance Criteria:**
  - [ ] Basic search: keyword against `cal_name`
  - [ ] Advanced filters: date range, category, user, site extras
  - [ ] Results include repeating event instances
  - [ ] Access control enforced (no private events from other users)
  - [ ] UAC function permission `ACCESS_SEARCH` / `ACCESS_ADVANCED_SEARCH` respected

#### S-6.6: Report engine

- **As a** user, **I want** to create templated reports with variable substitution, **so that** I can generate formatted event listings.
- **Priority:** P2 | **Size:** M | **Depends on:** S-4.1
- **PRD Ref:** Section 18
- **Acceptance Criteria:**
  - [ ] Report definitions with Page/Date/Event templates
  - [ ] Variable substitution: `${name}`, `${date}`, `${time}`, `${duration}`, `${extra:FieldName}`, etc.
  - [ ] Output formats: HTML, plain text, CSV
  - [ ] Date range and category filtering
  - [ ] Global reports visible to all users

---

### Epic 7: Data Exchange

**Goal:** Import/export, feeds, publishing, remote calendars, and MCP server.
**Phase:** 1–2 | **PRD Refs:** Sections 16, 20, 28

#### S-7.1: iCalendar import

- **As a** user, **I want** to import ICS files into my calendar, **so that** I can bring in events from other systems.
- **Priority:** P0 | **Size:** L | **Depends on:** S-1.7
- **PRD Ref:** Section 16.1
- **Acceptance Criteria:**
  - [ ] VEVENT, VTODO, VJOURNAL components parsed and stored as events, tasks, journals
  - [ ] Repeating events import with full RRULE, EXDATE, RDATE support
  - [ ] External UID tracked in `webcal_import_data` for re-import update detection
  - [ ] Admin can import events for any user; regular users import to own calendar
  - [ ] Invalid components logged and skipped (not fatal)

#### S-7.2: iCalendar export

- **As a** user, **I want** to export my calendar as an ICS file, **so that** I can share it or import into other systems.
- **Priority:** P0 | **Size:** M | **Depends on:** S-1.7
- **PRD Ref:** Section 16.2
- **Acceptance Criteria:**
  - [ ] Valid RFC 5545 output with VCALENDAR, VEVENT, VTODO, VJOURNAL, VTIMEZONE
  - [ ] Date range and category filtering supported
  - [ ] Access levels enforced (no private events in export)
  - [ ] All RFC 5545 properties with dedicated columns are exported (per Appendix F)
  - [ ] CSV and HTML export formats also available

#### S-7.3: Calendar feed publishing

- **As a** user, **I want** to publish a subscribable iCal feed URL, **so that** Apple Calendar, Google Calendar, and Outlook can sync my events.
- **Priority:** P1 | **Size:** M | **Depends on:** S-7.2
- **PRD Ref:** Section 20
- **Acceptance Criteria:**
  - [ ] `GET /feeds/ical/{user}.ics` returns valid iCal with `text/calendar` content type
  - [ ] `GET /feeds/freebusy/{user}.ifb` returns VFREEBUSY (busy times only, no details)
  - [ ] Per-user `PUBLISH_ENABLED` / `FREEBUSY_ENABLED` preferences control availability
  - [ ] Global `PUBLISH_ENABLED` setting overrides per-user
  - [ ] RSS feeds for upcoming events, unapproved events, activity log

#### S-7.4: Remote calendar subscriptions

- **As a** user, **I want** to subscribe to external iCal URLs, **so that** remote calendars appear alongside my own.
- **Priority:** P1 | **Size:** M | **Depends on:** S-7.1
- **PRD Ref:** Sections 16.1, 23
- **Acceptance Criteria:**
  - [ ] Remote URL stored in `webcal_nonuser_cals.cal_url` or `webcal_import`
  - [ ] Periodic fetch with MD5 change detection
  - [ ] Remote events displayed as read-only in calendar views
  - [ ] `cal_check_date` updated on each fetch

#### S-7.5: MCP server enhancements

- **As an** API consumer (AI agent), **I want** additional MCP tools (update_event, delete_event, list_tasks, get_availability), **so that** AI assistants can fully manage my calendar.
- **Priority:** P2 | **Size:** M | **Depends on:** S-3.3
- **PRD Ref:** Section 28.6
- **Acceptance Criteria:**
  - [ ] `update_event` tool: modify existing event fields
  - [ ] `delete_event` tool: delete event by ID
  - [ ] `list_tasks` tool: list tasks with optional due date filter
  - [ ] `get_availability` tool: return free/busy blocks for a date range
  - [ ] All tools respect access control and rate limiting
  - [ ] MCP resource/prompt support for AI context

---

### Epic 8: Notifications & Activity

**Goal:** Reminders, email notifications, webhooks, and audit trail.
**Phase:** 1–2 | **PRD Refs:** Sections 19, 23

#### S-8.1: Implement NotificationService (email)

- **As a** user, **I want** to receive email reminders before my events, **so that** I don't miss appointments.
- **Priority:** P0 | **Size:** M | **Depends on:** S-1.5, S-2.3
- **PRD Ref:** Section 19
- **Acceptance Criteria:**
  - [ ] Offset-based reminders fire N minutes before/after event start/end
  - [ ] Absolute reminders fire at specific date/time (including new `cal_time` column)
  - [ ] Repeating reminders re-fire at configured interval
  - [ ] `cal_times_sent` tracked to prevent infinite loops
  - [ ] PHPMailer used for SMTP delivery with admin-configured settings

#### S-8.2: VALARM round-trip support

- **As a** developer, **I want** complete VALARM property storage and retrieval, **so that** imported alarms export identically.
- **Priority:** P1 | **Size:** S | **Depends on:** S-8.1, S-2.4
- **PRD Ref:** Section 19.1 (new columns), Appendix F.3
- **Acceptance Criteria:**
  - [ ] `cal_description`, `cal_summary`, `cal_attendee`, `cal_attach` columns populated on import
  - [ ] All 8 VALARM properties round-trip through import → store → export
  - [ ] DISPLAY and EMAIL alarm actions both supported

#### S-8.3: Activity log service

- **As an** admin, **I want** a complete audit trail of all calendar actions, **so that** I can monitor usage and investigate issues.
- **Priority:** P1 | **Size:** M | **Depends on:** S-1.5, S-2.3
- **PRD Ref:** Section 23
- **Acceptance Criteria:**
  - [ ] All log type codes recorded: CREATE, APPROVE, REJECT, UPDATE, NOTIFICATION, REMINDER, etc.
  - [ ] Login failures logged with source IP
  - [ ] User management actions (add, delete, update) logged
  - [ ] Filterable by date range, user, and log type
  - [ ] Access controlled by `ACCESS_ACTIVITY_LOG` when UAC enabled

#### S-8.4: Webhook notifications (NEW)

- **As a** user, **I want** outbound webhooks fired when events are created/updated/deleted, **so that** I can integrate with Zapier, Make, or custom systems.
- **Priority:** P2 | **Size:** M | **Depends on:** S-4.1
- **PRD Ref:** Section 27.5
- **Acceptance Criteria:**
  - [ ] Webhook URL configurable per user and globally
  - [ ] POST request with JSON payload on event create, update, delete
  - [ ] Payload includes event data, action type, and actor
  - [ ] Retry with exponential backoff on failure (max 3 retries)
  - [ ] Webhook delivery logged in activity log

---

### Epic 9: Admin & Security

**Goal:** Admin interface, system settings, security hardening.
**Phase:** 2 | **PRD Refs:** Sections 24, 25, 26

#### S-9.1: Admin settings API

- **As an** admin, **I want** to manage all system settings via the API, **so that** the admin interface can be decoupled from server-rendered PHP.
- **Priority:** P0 | **Size:** M | **Depends on:** S-3.6
- **PRD Ref:** Section 24
- **Acceptance Criteria:**
  - [ ] All settings from Section 24.1 readable and writable via API
  - [ ] Settings validated before save (e.g., `WORK_DAY_START_HOUR` < `WORK_DAY_END_HOUR`)
  - [ ] Color scheme settings persisted to `webcal_config`
  - [ ] Admin-only access enforced

#### S-9.2: UAC management API

- **As an** admin, **I want** to manage function-level and calendar-level permissions via the API, **so that** access control is configurable without the legacy UI.
- **Priority:** P1 | **Size:** M | **Depends on:** S-5.2
- **PRD Ref:** Section 9
- **Acceptance Criteria:**
  - [ ] Get/set function permissions for a user (28-position bitmask)
  - [ ] Get/set calendar-level access between user pairs (view/edit/approve)
  - [ ] Batch update for setting permissions on multiple users
  - [ ] Changes take effect immediately (no cache delay)

#### S-9.3: CSRF and CSP hardening

- **As a** developer, **I want** CSRF tokens on all state-changing requests and strict CSP headers, **so that** the application is protected against common web attacks.
- **Priority:** P0 | **Size:** S | **Depends on:** S-3.1
- **PRD Ref:** Section 25
- **Acceptance Criteria:**
  - [ ] CSRF token validated on all POST/PUT/PATCH/DELETE requests (session-based auth)
  - [ ] CSP headers sent on all responses per admin setting (none/same/any)
  - [ ] HttpOnly, Secure, SameSite=Lax flags on session cookies
  - [ ] Input sanitization via dedicated functions (no raw `$_GET`/`$_POST` in business logic)

#### S-9.4: i18n service

- **As a** user, **I want** the application displayed in my preferred language, **so that** I can use the calendar in my native language.
- **Priority:** P1 | **Size:** M
- **PRD Ref:** Section 26
- **Acceptance Criteria:**
  - [ ] Translation files in `translations/{Language}.txt` loaded and cached
  - [ ] Per-user `LANGUAGE` preference overrides global default
  - [ ] Browser `Accept-Language` header used as fallback
  - [ ] Date/time formatting respects locale
  - [ ] Cache invalidated when translation file changes

---

### External Project Epics (Out of Scope)

The following epics are documented in their respective project repositories:

- **Epic 10: WordPress Plugin** (5 stories: S-10.1–S-10.5) — Build `webcalendar-wp` WordPress plugin. Depends on core Epics 1-2 being complete.
- **Epic 11: React Frontend** (5 stories: S-11.1–S-11.5) — Build React SPA in `webcalendar-web`. Depends on core Epic 3 (REST API) being complete.
- **Epic 12: New Features** (4 stories: S-12.1–S-12.4) — Public booking pages, booking configuration, natural language event creation, location mapping. The booking business logic (S-12.1, S-12.2) has core components; the UI is a frontend concern.

---

### G.1 Dependency Graph (Critical Path — Core Only)

```
S-1.1 (package init)
  ├── S-1.2 (entities)
  │     └── S-1.3 (repo interfaces)
  │           ├── S-2.2 (EventRepo PDO) ── S-2.3 (other repos)
  │           └── S-2.5 (WP WPDB bridge)
  ├── S-1.4 (contracts)
  │     └── S-2.1 (PDO factory)
  │           └── S-2.4 (schema migration)
  └── S-1.5 (services)
        ├── S-1.6 (recurrence) ── S-1.7 (import/export)
        ├── S-4.1 (EventService) ── S-4.2..S-4.6
        ├── S-5.1 (UserService) ── S-5.2 (PermissionService)
        └── S-6.1 (CategoryService)

S-3.1 (API router)
  └── S-3.2 (auth middleware)
        ├── S-3.3 (event endpoints)
        ├── S-3.4 (supporting endpoints)
        ├── S-3.5 (import/export endpoints)
        └── S-3.6 (admin endpoints)
```

### G.2 Story Count Summary (Core Only)

| Epic | Name | P0 | P1 | P2 | Total |
|------|------|----|----|----|-------|
| 1 | Core Library Foundation | 7 | 0 | 0 | 7 |
| 2 | Database Layer Modernization | 4 | 1 | 0 | 5 |
| 3 | REST API | 4 | 2 | 0 | 6 |
| 4 | Event Lifecycle | 3 | 3 | 0 | 6 |
| 5 | User & Access Management | 1 | 3 | 0 | 4 |
| 6 | Calendar Features | 2 | 3 | 1 | 6 |
| 7 | Data Exchange | 2 | 2 | 1 | 5 |
| 8 | Notifications & Activity | 1 | 2 | 1 | 4 |
| 9 | Admin & Security | 2 | 2 | 0 | 4 |
| | **Totals** | **26** | **18** | **3** | **47** |

### G.3 Recommended Implementation Order (Core Only)

> **For AI Agents:** Follow this sequence for dependency-safe implementation. Stories within the same step can be parallelized.

| Step | Stories | Gate |
|------|---------|------|
| 1 | S-1.1, S-1.4 | Composer package initialized |
| 2 | S-1.2, S-2.1 | Entities and PDO factory ready |
| 3 | S-1.3, S-2.4 | Repo interfaces + schema migration |
| 4 | S-1.5, S-2.2, S-2.3 | Services + repositories |
| 5 | S-1.6, S-1.7 | iCal integration complete |
| 6 | S-4.1, S-5.1, S-5.2, S-6.1 | Core services implemented |
| 7 | S-4.2, S-4.3, S-4.4, S-4.5, S-4.6 | Full event lifecycle |
| 8 | S-3.1, S-3.2, S-9.3 | API framework + auth + security |
| 9 | S-3.3, S-3.4, S-3.5, S-3.6 | All API endpoints |
| 10 | S-5.3, S-5.4, S-6.2, S-6.3, S-6.4, S-6.5 | Supporting features |
| 11 | S-7.1, S-7.2, S-7.3, S-7.4 | Data exchange |
| 12 | S-8.1, S-8.2, S-8.3, S-9.1, S-9.2, S-9.4 | Notifications + admin |

---

**End of WebCalendar-Core PRD v4.0**
