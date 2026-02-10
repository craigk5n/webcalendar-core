# WebCalendar Core API Documentation

This document defines the REST API for the `webcalendar-core` library. It covers all functionality required to replace the legacy WebCalendar v1.9.13 application, supporting modern frontends and integrations.

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

---

## 2. Resources

### 2.1 Events (`/events`)

Manage calendar events. Supports standard CRUD, recurrence, and participation.

#### `GET /events`
List events, optionally filtered by date range, user, or category. Returns **expanded instances** for repeating events.

**Query Parameters:**
- `start`: Start date (`YYYYMMDD`).
- `end`: End date (`YYYYMMDD`).
- `user`: User login to filter by (default: current user).
- `category`: Category ID to filter by.
- `q`: Search keyword.

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
  "access": "P",           // P=Public, C=Confidential, R=Private
  "priority": 5,           // 1-9
  "participants": ["user1", "user2"],
  "external_participants": [
    {"name": "John Doe", "email": "john@example.com"}
  ],
  "recurrence": {          // Optional
    "freq": "WEEKLY",
    "byday": "MO,WE",
    "until": "20261231T235959Z"
  },
  "reminders": [           // Optional
    {"action": "EMAIL", "offset": 15, "related": "START"}
  ]
}
```

#### `GET /events/{id}`
Get a single event's details. Returns the **master definition** (with RRULE) for repeating events.

#### `PUT /events/{id}`
Update an event. For repeating events, this updates the **master** series.

#### `DELETE /events/{id}`
Delete an event.

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
  "participants": ["user1"]
}
```
**Response:** List of conflicting event objects.

---

### 2.2 Tasks (`/tasks`)

Manage to-do items (VTODO).

#### `GET /tasks`
List tasks.

**Query Parameters:**
- `due_before`: Filter by due date (`YYYYMMDD`).
- `status`: `pending`, `completed`, or `all`.

#### `POST /tasks`
Create a task. Similar fields to Event but with `due_date`, `due_time`, `percent_complete`.

#### `PUT /tasks/{id}`
Update a task (e.g., mark complete).

---

### 2.3 Journals (`/journals`)

Manage journal entries (VJOURNAL).

#### `GET /journals`
List journal entries.

#### `POST /journals`
Create a journal entry. Fields: `date`, `title`, `text`.

---

### 2.4 Users (`/users`)

Manage user accounts and preferences.

#### `GET /users`
List users (Admin or "View Others" permission required).

#### `GET /users/{login}`
Get public profile of a user.

#### `POST /users`
Create a new user (Admin only).

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

---

### 2.5 Groups (`/groups`)

Manage user groups.

#### `GET /groups`
List groups visible to the current user.

#### `POST /groups`
Create a new group.

#### `GET /groups/{id}/members`
Get members of a group.

---

### 2.6 Categories (`/categories`)

Manage event categories.

#### `GET /categories`
List categories (Global + Personal).

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

---

### 2.7 Layers (`/layers`)

Manage calendar overlays.

#### `GET /layers`
List configured layers for the current user.

#### `POST /layers`
Add a new layer (overlay another user's calendar).

---

### 2.8 Custom Views (`/views`)

Manage custom combined views.

#### `GET /views`
List custom views.

#### `POST /views`
Create a custom view definition.

---

### 2.9 Search (`/search`)

Global search across events.

#### `GET /search`

**Query Parameters:**
- `q`: Keyword (title, description).
- `start`: Start date.
- `end`: End date.
- `cat_id`: Category filter.
- `user`: Target user.

---

### 2.10 Import/Export

#### `POST /import`
Import data from external formats.

**Content-Type:** `multipart/form-data`
**Parameters:**
- `file`: The file (ICS, CSV, etc.)
- `format`: `ical`, `csv`, `vcal`

#### `GET /export`
Export calendar data.

**Query Parameters:**
- `format`: `ics`, `csv`.
- `start`: Start date.
- `end`: End date.

---

### 2.11 System (`/admin`)

System-level configuration and logs.

#### `GET /admin/settings`
Get system configuration (Admin only).

#### `PUT /admin/settings`
Update system configuration (Admin only).

#### `GET /admin/activity-log`
Search the system activity log.

---

### 2.12 Feeds (Public/Unauthenticated)

#### `GET /feeds/ical/{user}.ics`
iCalendar subscription feed.

#### `GET /feeds/freebusy/{user}.ifb`
Free/Busy availability feed.

#### `GET /feeds/rss/{user}.xml`
RSS feed of public events.

---

## 3. Data Models (Reference)

See `PRD.md` for detailed field definitions of `Event`, `User`, `Task`, etc.
