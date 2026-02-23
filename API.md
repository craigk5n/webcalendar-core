# WebCalendar Core API Documentation

> **Note:** The REST API implementation (controllers, middleware, routing, OpenAPI spec) belongs in the **webcalendar-api** project. This document is retained here as a reference for the API contract that `webcalendar-api` will implement on top of `webcalendar-core` services. When `webcalendar-api` is created, this file should be migrated there.

This document defines the REST API contract for the WebCalendar ecosystem. It covers all functionality required to replace the legacy WebCalendar v1.9.13 application, supporting modern frontends and integrations.

## 1. General Conventions

- **Base URL:** `/api/v2`
- **Content-Type:** `application/json`
- **Date Format:** `YYYYMMDD` (strings) or ISO 8601 `YYYY-MM-DD` (where specified)
- **Time Format:** `HHMMSS` (strings) or ISO 8601 `HH:mm:ss`
- **Booleans:** JSON `true`/`false`

### 1.1 Authentication

All endpoints (except public feeds/booking) require authentication.

- **Header:** `Authorization: Bearer <token>`
- **API Key:** `X-API-Key: <key>` (for MCP/System integration)

### 1.2 Standard Response Envelope

```json
{
  "data": { ... },       // The requested resource(s)
  "meta": {              // Pagination, totals, etc.
    "total": 100,
    "page": 1,
    "limit": 20
  },
  "error": null          // Error object if failed
}
```

### 1.3 Error Response

```json
{
  "data": null,
  "meta": null,
  "error": {
    "code": 404,
    "message": "Event not found",
    "details": []
  }
}
```

### 1.4 Versioning

The API follows a URL-based versioning strategy:

- **Current Version:** `/api/v2`
- **Breaking Changes:** Will result in a major version bump (e.g., `/api/v3`).
- **Deprecation:** Deprecated endpoints will include `Deprecation: true` and `Sunset: <date>` headers.
- **Compatibility:** At least one previous major version will be supported during transition periods.

### 1.5 Rate Limiting

To prevent abuse and ensure fair usage, the API enforces rate limits.

- **Headers:**
    - `X-RateLimit-Limit`: Maximum requests allowed in the window.
    - `X-RateLimit-Remaining`: Remaining requests in the current window.
    - `X-RateLimit-Reset`: Unix timestamp when the limit resets.
- **Limits:**
    - **Read Operations:** 1000 requests/minute.
    - **Write Operations:** 100 requests/minute.
    - **Authentication:** 10 requests/minute.
- **Exceeded:** Returns `429 Too Many Requests` with a `Retry-After` header.

### 1.6 Caching

HTTP caching is implemented to improve performance.

- **ETag:** All `GET` endpoints return an `ETag` header. Clients should use `If-None-Match` to conditionally request resources (returns `304 Not Modified`).
- **Cache-Control:**
    - **Events:** `private, max-age=60` (1 minute)
    - **Users:** `private, max-age=300` (5 minutes)
    - **Categories:** `public, max-age=3600` (1 hour)
    - **Reports:** `no-cache`

---

## 2. Authentication

### 2.1 Session Authentication

#### `POST /auth/login`
Authenticate a user and receive a session token.

**Request Body:**
```json
{
  "username": "john_doe",
  "password": "secret123"
}
```

**Response:**
```json
{
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIs...",
    "user": {
      "login": "john_doe",
      "firstname": "John",
      "lastname": "Doe",
      "email": "john@example.com",
      "is_admin": false
    },
    "expires_at": "2026-02-10T12:00:00Z"
  }
}
```

#### `POST /auth/logout`
Invalidate the current session token.

#### `POST /auth/refresh`
Refresh an expiring session token.

---

## 3. Resources

### 3.1 Events (`/events`)

Manage calendar events. Supports standard CRUD, recurrence, and participation.

**Event Types:** `E`=Event, `M`=Repeating Event, `T`=Task, `J`=Journal, `N`=Repeating Task, `O`=Repeating Journal  
**Access Levels:** `P`=Public, `C`=Confidential, `R`=Private

#### `GET /events`
List events, optionally filtered by date range, user, or category. Returns **expanded instances** for repeating events.

**Query Parameters:**
- `start`: Start date (`YYYYMMDD`).
- `end`: End date (`YYYYMMDD`).
- `user`: User login to filter by (default: current user).
- `category`: Category ID to filter by.
- `q`: Search keyword (searches title, description).
- `type`: Filter by event type (`E`, `M`, `T`, `J`, `N`, `O`).
- `include_repeating`: Include expanded repeating events (default: `true`).

#### `POST /events`
Create a new event.

**Request Body:**
```json
{
  "title": "Team Meeting",
  "description": "Weekly sync",
  "start_date": "20260210",
  "start_time": "100000",
  "duration": 60,
  "location": "Room A",
  "access": "P",
  "priority": 5,
  "type": "E",
  "participants": ["user1", "user2"],
  "external_participants": [
    {"name": "John Doe", "email": "john@example.com"}
  ],
  "categories": [1, 2],
  "recurrence": {
    "type": "weekly",
    "frequency": 1,
    "byday": "MO,WE",
    "end": "20261231"
  },
  "reminders": [
    {"action": "EMAIL", "offset": 15, "related": "START"}
  ],
  "site_extras": {
    "custom_field_1": "value"
  }
}
```

#### `GET /events/{id}`
Get a single event's details. Returns the **master definition** (with RRULE) for repeating events.

#### `PUT /events/{id}`
Update an event. For repeating events, this updates the **master** series.

#### `DELETE /events/{id}`
Delete an event. Supports `mode` parameter:
- `single`: Delete only this instance (creates exception)
- `future`: Delete this and future instances
- `all`: Delete entire series (default)

#### `POST /events/{id}/approve`
Approve a pending event participation request.

#### `POST /events/{id}/reject`
Reject a pending event participation request.

#### `POST /events/check-conflicts`
Check for scheduling conflicts before creating/updating.

**Request Body:**
```json
{
  "start_date": "20260210",
  "start_time": "100000",
  "duration": 60,
  "participants": ["user1"],
  "exclude_event_id": 123
}
```

---

### 3.2 Event Participants (`/events/{id}/participants`)

#### `GET /events/{id}/participants`
List all participants for an event.

#### `POST /events/{id}/participants`
Add participants to an event.

**Request Body:**
```json
{
  "participants": ["user1", "user2"],
  "external": [
    {"name": "Jane Doe", "email": "jane@example.com"}
  ]
}
```

#### `PUT /events/{id}/participants/{login}`
Update participant status or completion percentage.

**Request Body:**
```json
{
  "status": "A",
  "percent_complete": 50
}
```

#### `DELETE /events/{id}/participants/{login}`
Remove a participant from an event.

---

### 3.3 Event Recurrence (`/events/{id}/recurrence`)

#### `GET /events/{id}/recurrence`
Get recurrence pattern for a repeating event.

#### `PUT /events/{id}/recurrence`
Update recurrence pattern.

#### `DELETE /events/{id}/recurrence`
Remove recurrence (converts to single event).

---

### 3.4 Event Exceptions (`/events/{id}/exceptions`)

#### `GET /events/{id}/exceptions`
List exception dates for a repeating event.

#### `POST /events/{id}/exceptions`
Add an exception or inclusion date.

**Request Body:**
```json
{
  "date": "20260217",
  "is_exclusion": true
}
```

#### `DELETE /events/{id}/exceptions/{date}`
Remove an exception date.

---

### 3.5 Event Attachments (`/events/{id}/attachments`)

#### `GET /events/{id}/attachments`
List attachments for an event.

#### `POST /events/{id}/attachments`
Upload an attachment (multipart/form-data).

#### `GET /events/{id}/attachments/{attachment_id}`
Download an attachment.

#### `DELETE /events/{id}/attachments/{attachment_id}`
Delete an attachment.

---

### 3.6 Event Comments (`/events/{id}/comments`)

#### `GET /events/{id}/comments`
List comments for an event.

#### `POST /events/{id}/comments`
Add a comment to an event.

---

### 3.7 Tasks (`/tasks`)

Manage to-do items (VTODO). Tasks are events with `type: 'T'` or `'N'`.

#### `GET /tasks`
List tasks with filtering options.

**Query Parameters:**
- `due_before`: Filter by due date (`YYYYMMDD`).
- `status`: `pending`, `completed`, `in_progress`, or `all`.
- `user`: Filter by assigned user.

#### `POST /tasks`
Create a task.

**Request Body:**
```json
{
  "title": "Complete documentation",
  "description": "Write API docs",
  "due_date": "20260215",
  "due_time": "170000",
  "priority": 5,
  "assigned_to": ["user1"],
  "percent_complete": 0
}
```

#### `GET /tasks/{id}`
Get task details.

#### `PUT /tasks/{id}`
Update a task (e.g., mark complete, change due date).

#### `DELETE /tasks/{id}`
Delete a task.

---

### 3.8 Journals (`/journals`)

Manage journal entries (VJOURNAL). Journals are events with `type: 'J'` or `'O'`.

#### `GET /journals`
List journal entries.

**Query Parameters:**
- `start`: Start date.
- `end`: End date.
- `user`: Filter by author.

#### `POST /journals`
Create a journal entry.

**Request Body:**
```json
{
  "date": "20260210",
  "title": "Daily Notes",
  "text": "Today I worked on...",
  "categories": [1]
}
```

#### `GET /journals/{id}`
Get journal entry details.

#### `PUT /journals/{id}`
Update a journal entry.

#### `DELETE /journals/{id}`
Delete a journal entry.

---

### 3.9 Users (`/users`)

Manage user accounts and preferences.

#### `GET /users`
List users (Admin or "View Others" permission required).

**Query Parameters:**
- `q`: Search by name or login.
- `group_id`: Filter by group membership.
- `enabled_only`: Only return enabled users (default: `true`).

#### `GET /users/{login}`
Get public profile of a user.

#### `POST /users`
Create a new user (Admin only).

**Request Body:**
```json
{
  "login": "newuser",
  "firstname": "New",
  "lastname": "User",
  "email": "new@example.com",
  "password": "temp123",
  "is_admin": false,
  "enabled": true
}
```

#### `PUT /users/{login}`
Update user profile (Admin or self).

#### `DELETE /users/{login}`
Delete a user (Admin only). Cascades to all related data.

#### `PUT /users/{login}/password`
Change user password.

**Request Body:**
```json
{
  "current_password": "oldpass",
  "new_password": "newpass"
}
```

---

### 3.10 User Preferences (`/users/{login}/preferences`)

#### `GET /users/{login}/preferences`
Get user preferences (key-value pairs).

#### `PUT /users/{login}/preferences`
Update user preferences.

**Request Body:**
```json
{
  "LANGUAGE": "English",
  "TIMEZONE": "America/New_York",
  "STARTVIEW": "week"
}
```

#### `DELETE /users/{login}/preferences/{key}`
Remove a specific preference.

---

### 3.11 User Assistants (`/users/{login}/assistants`)

Manage assistant/boss relationships.

#### `GET /users/{login}/assistants`
List assistants for a user.

#### `POST /users/{login}/assistants`
Assign an assistant.

#### `DELETE /users/{login}/assistants/{assistant_login}`
Remove an assistant assignment.

---

### 3.12 Groups (`/groups`)

Manage user groups.

#### `GET /groups`
List groups visible to the current user.

#### `POST /groups`
Create a new group (Admin only).

**Request Body:**
```json
{
  "name": "Engineering Team",
  "description": "All engineers"
}
```

#### `GET /groups/{id}`
Get group details.

#### `PUT /groups/{id}`
Update a group (Admin only).

#### `DELETE /groups/{id}`
Delete a group (Admin only).

---

### 3.13 Group Members (`/groups/{id}/members`)

#### `GET /groups/{id}/members`
Get members of a group.

#### `POST /groups/{id}/members`
Add members to a group.

**Request Body:**
```json
{
  "users": ["user1", "user2"]
}
```

#### `DELETE /groups/{id}/members/{login}`
Remove a member from a group.

#### `PUT /groups/{id}/members/{login}`
Update member role or permissions.

**Request Body:**
```json
{
  "role": "member"
}
```

---

### 3.14 Categories (`/categories`)

Manage event categories.

#### `GET /categories`
List categories (Global + Personal).

**Query Parameters:**
- `include_global`: Include global categories (default: `true`).
- `user`: Filter by owner (for personal categories).

#### `POST /categories`
Create a category.

**Request Body:**
```json
{
  "name": "Work",
  "color": "#FF0000",
  "is_global": false
}
```

#### `GET /categories/{id}`
Get category details.

#### `PUT /categories/{id}`
Update a category.

#### `DELETE /categories/{id}`
Delete a category.

---

### 3.15 Layers (`/layers`)

Manage calendar overlays.

#### `GET /layers`
List configured layers for the current user.

#### `POST /layers`
Add a new layer (overlay another user's calendar).

**Request Body:**
```json
{
  "source_user": "colleague1",
  "color": "#00FF00",
  "visible": true
}
```

#### `PUT /layers/{id}`
Update layer settings.

#### `DELETE /layers/{id}`
Remove a layer.

---

### 3.16 Custom Views (`/views`)

Manage custom combined views.

#### `GET /views`
List custom views.

#### `POST /views`
Create a custom view definition.

**Request Body:**
```json
{
  "name": "Team View",
  "description": "Shows team calendars",
  "users": ["user1", "user2", "user3"],
  "show_tasks": true,
  "show_events": true
}
```

#### `GET /views/{id}`
Get view details.

#### `PUT /views/{id}`
Update a custom view.

#### `DELETE /views/{id}`
Delete a custom view.

---

### 3.17 Reports (`/reports`)

Manage custom reports.

#### `GET /reports`
List available reports.

#### `POST /reports`
Create a custom report.

**Request Body:**
```json
{
  "name": "Monthly Summary",
  "template_id": 1,
  "date_range": {
    "start": "20260201",
    "end": "20260228"
  },
  "include_header": true,
  "include_trailer": true
}
```

#### `GET /reports/{id}`
Get report details.

#### `GET /reports/{id}/execute`
Execute a report and get results.

#### `PUT /reports/{id}`
Update a report.

#### `DELETE /reports/{id}`
Delete a report.

---

### 3.18 Report Templates (`/report-templates`)

#### `GET /report-templates`
List report templates.

#### `GET /report-templates/{id}`
Get template details.

---

### 3.19 Search (`/search`)

Global search across events.

#### `GET /search`

**Query Parameters:**
- `q`: Keyword (title, description).
- `start`: Start date.
- `end`: End date.
- `cat_id`: Category filter.
- `user`: Target user.
- `type`: Event type filter.

---

### 3.20 Non-User Calendars (`/nonuser-cals`)

Manage resource and remote calendars.

#### `GET /nonuser-cals`
List non-user calendars.

#### `POST /nonuser-cals`
Create a non-user calendar.

**Request Body:**
```json
{
  "login": "conference_room_a",
  "lastname": "Conference Room A",
  "is_public": true,
  "admin": "admin_user",
  "group_ids": [1, 2]
}
```

#### `GET /nonuser-cals/{login}`
Get non-user calendar details.

#### `PUT /nonuser-cals/{login}`
Update non-user calendar.

#### `DELETE /nonuser-cals/{login}`
Delete non-user calendar.

---

### 3.21 Reminders (`/reminders`)

Manage event reminders.

#### `GET /reminders`
List reminders for the current user.

**Query Parameters:**
- `event_id`: Filter by specific event.
- `due_before`: Get reminders due before date.

#### `POST /reminders`
Create a reminder.

**Request Body:**
```json
{
  "event_id": 123,
  "action": "EMAIL",
  "offset": 15,
  "offset_unit": "minutes",
  "related": "START"
}
```

#### `PUT /reminders/{id}`
Update a reminder.

#### `DELETE /reminders/{id}`
Delete a reminder.

---

### 3.22 Access Control (`/access`)

Manage user and function permissions.

#### `GET /access/users`
Get user-to-user access permissions.

**Query Parameters:**
- `user`: Target user to check permissions for.

#### `PUT /access/users/{login}`
Set access permissions for a user.

**Request Body:**
```json
{
  "can_view": true,
  "can_edit": false,
  "can_view_others": false,
  "can_edit_others": false
}
```

#### `GET /access/functions`
Get function-level permissions.

#### `PUT /access/functions/{function_name}`
Set function permissions.

**Request Body:**
```json
{
  "allowed_groups": [1, 2],
  "allowed_users": ["admin1"]
}
```

---

### 3.23 Import/Export

#### `POST /import`
Import data from external formats.

**Content-Type:** `multipart/form-data`  
**Parameters:**
- `file`: The file (ICS, CSV, etc.)
- `format`: `ical`, `csv`, `vcal`
- `ignore_conflicts`: Skip conflict checking (default: `false`)
- `dry_run`: Preview changes without importing (default: `false`)

#### `GET /import/{id}/status`
Get import status and results.

#### `GET /export`
Export calendar data.

**Query Parameters:**
- `format`: `ics`, `csv`, `vcal`.
- `start`: Start date.
- `end`: End date.
- `user`: Export specific user's calendar.
- `include_private`: Include private events (requires permission).

---

### 3.24 System (`/admin`)

System-level configuration and logs.

#### `GET /admin/settings`
Get system configuration (Admin only).

#### `PUT /admin/settings`
Update system configuration (Admin only).

**Request Body:**
```json
{
  "APPLICATION_NAME": "WebCalendar",
  "LANGUAGE": "English",
  "TIMEZONE": "America/New_York",
  "WORK_DAY_START": "090000",
  "WORK_DAY_END": "170000",
  "DISABLE_ACCESS_FIELD": false
}
```

#### `GET /admin/activity-log`
Search the system activity log.

**Query Parameters:**
- `user`: Filter by user.
- `action`: Filter by action type.
- `start`: Start date.
- `end`: End date.

#### `GET /admin/security-audit`
Run security audit (Admin only).

---

### 3.25 Site Extras (`/site-extras`)

Manage custom event fields.

#### `GET /site-extras`
List custom field definitions.

#### `POST /site-extras`
Create a custom field.

**Request Body:**
```json
{
  "name": "project_code",
  "label": "Project Code",
  "type": "text",
  "required": false
}
```

#### `PUT /site-extras/{id}`
Update a custom field.

#### `DELETE /site-extras/{id}`
Delete a custom field.

---

### 3.26 Feeds (Public/Unauthenticated)

#### `GET /feeds/ical/{user}.ics`
iCalendar subscription feed.

**Query Parameters:**
- `token`: Access token for private calendars.

#### `GET /feeds/freebusy/{user}.ifb`
Free/Busy availability feed.

#### `GET /feeds/rss/{user}.xml`
RSS feed of public events.

---

## 4. Database Tables Reference

Based on analysis of the legacy codebase, the following tables are used:

| Table | Purpose |
|-------|---------|
| `webcal_user` | User accounts and authentication |
| `webcal_entry` | Calendar events (main table) |
| `webcal_entry_user` | Event participants and status |
| `webcal_entry_repeats` | Repeating event patterns |
| `webcal_entry_repeats_not` | Exception dates for repeating events |
| `webcal_entry_ext_user` | External (non-registered) participants |
| `webcal_entry_categories` | Event-category associations |
| `webcal_entry_log` | Activity/audit logging |
| `webcal_user_pref` | User preferences/settings |
| `webcal_user_layers` | Calendar layers (overlay views) |
| `webcal_config` | System configuration |
| `webcal_categories` | Event categories |
| `webcal_group` | User groups |
| `webcal_group_user` | Group membership |
| `webcal_view` | Custom calendar views |
| `webcal_view_user` | View participants |
| `webcal_site_extras` | Custom event fields |
| `webcal_reminders` | Event reminders |
| `webcal_asst` | Assistant/boss relationships |
| `webcal_nonuser_cals` | Non-user calendars (remote/resources) |
| `webcal_import` | Import tracking |
| `webcal_import_data` | Import event mapping |
| `webcal_report` | Custom reports |
| `webcal_report_template` | Report templates |
| `webcal_access_user` | User-to-user access permissions |
| `webcal_access_function` | Function-level permissions |
| `webcal_user_template` | Custom headers/trailers |
| `webcal_blob` | Attachments and comments |
| `webcal_timezones` | Timezone definitions |

---

## 5. Changelog

### Version 2.0

**Added:**
- Authentication endpoints (`/auth/login`, `/auth/logout`, `/auth/refresh`)
- Event participant management endpoints
- Event recurrence and exception management
- Event attachments and comments
- User password management
- User assistants management
- Group member management (POST/DELETE)
- Views management (GET/PUT/DELETE)
- Reports and report templates
- Non-user calendars (resources)
- Reminders management
- Access control (user and function permissions)
- Import status endpoint
- Site extras (custom fields)
- Security audit endpoint
- Database tables reference

**Modified:**
- Event creation now includes `categories`, `site_extras`
- Event deletion supports `mode` parameter
- Import supports `dry_run` and `ignore_conflicts`
- Export supports `include_private`

**Removed:**
- None
