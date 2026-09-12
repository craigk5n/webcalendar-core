# Findings from WCTNG

Four issues in `webcalendar-core` found while adding test coverage to the
consuming application (`wctng`, `webcalendar-api`). Each one is either
unfixable from the consumer or only half-fixable there, which is why they are
written up here rather than worked around downstream.

Verified against this repository at `7d11afa`, not only against the `v4.10.0`
tag that `webcalendar-api` currently installs. Every reproduction below was
run; nothing here is inferred from reading alone.

> **Status: all four confirmed and fixed in this repository.** Each was
> reproduced against the code at `7d11afa` before being changed; see
> [Resolution](#resolution) at the end for what landed and what
> `webcalendar-api` should now change on its side.

| # | Issue | File | Severity | Status |
|---|---|---|---|---|
| 1 | RSS feed returns the owner's PRIVATE events | `Application/Service/FeedService.php` | **High** | Fixed |
| 2 | Booking writes a status the consumer's approval queue never sees | `Application/Service/BookingService.php` | Medium | Fixed |
| 3 | Booking builds a description that skips the consumer's sanitizer | `Application/Service/BookingService.php` | Medium | Fixed |
| 4 | A booking slot touching an appointment's end reads as busy | `Domain/ValueObject/DateRange.php` | Low | Fixed |

---

## 1. `FeedService` returns private events on a public feed

`generateRss()` and `generateFreeBusy()` both fetch with:

```php
$events = $this->eventService->getEventsInDateRange($range, $user);
```

`$user` is the calendar owner, so `PdoEventRepository::findByDateRange()`
applies `AND (e.cal_access = 'P' OR e.cal_create_by = :login)` — and the second
half of that matches **everything the owner created**, whatever its access
level.

In `webcalendar-api` both feeds are served from `^/api/v2/public/`, which the
firewall declares `security: false`. They are reachable by anyone on the
internet with no credentials, subject only to the owner having switched on
`public_calendar_enabled`.

Reproduction, against `PdoEventRepository` on the shipped SQLite schema:

```
what the public RSS feed fetches -- findByDateRange(range, $owner):
  access=P status='confirmed'      Public standup
  access=R status='confirmed'      PRIVATE: divorce lawyer      <- AccessLevel::PRIVATE
  access=P status='needs_approval' Awaiting review
```

`generateRss()` then writes `$event->name()` and `$event->description()` into
each `<item>` (lines 87-88). The CDATA wrapping in `addTextChild()` makes that
safe against XSS; it does not make it non-public. So the titles and
descriptions of a user's private appointments are served to anonymous callers.

`generateFreeBusy()` is a lesser case of the same thing: it emits only periods,
and publishing busy times without detail is arguably the point of a free/busy
feed. Whether private events should contribute to it is a judgement call — but
it should be a deliberate one, and right now both methods take the same path by
accident rather than by choice.

`getEventsInDateRange()` already accepts the parameter that fixes this:

```php
public function getEventsInDateRange(
    DateRange $range,
    ?User $user = null,
    ?string $accessLevel = null,
    ?array $users = null,
): EventCollection
```

so the change is one argument:

```php
-        $events = $this->eventService->getEventsInDateRange($range, $user);
+        // A public feed shows public entries. Passing the owner as $user
+        // instead widens the query to everything they created, private
+        // entries included.
+        $events = $this->eventService->getEventsInDateRange($range, null, 'P', [$user->login()]);
```

Applied to `generateRss()` certainly; to `generateFreeBusy()` if the answer to
the judgement call above is that private time should not be published either.

This one cannot be fixed downstream. `FeedController` has no way to narrow what
`FeedService` asks for, short of reimplementing both feeds in the application.

---

## 2. `BookingService::book()` writes `'TENTATIVE'`, which no queue reads

```php
status: 'TENTATIVE' // Pending approval
```

The comment states the intent, but the consuming application's approval queue
is built on a different value. In `webcalendar-api`:

- `CreateEventController` stores `status: 'needs_approval'` when the user's
  `require_event_approval` preference is set
- `ApprovalController::listPending()` lists `findByStatus('needs_approval')`
- approving writes `'confirmed'`, rejecting writes `'rejected'`

So a public booking is never pending anything. Confirmed directly:

```
findByDateRange(access 'P') returns:
  id=1  status='needs_approval'  Awaiting review
  id=4  status='TENTATIVE'       Public booking

findByStatus('needs_approval') -- what the admin queue sees:
  id=1  Awaiting review
```

`TENTATIVE` is RFC 5545's own word and a legitimate thing for an event to be;
it simply is not an approval state. This repository has no event-status enum
(`OrderStatus` and `ParticipantStatus` are the only two), so `needs_approval`
is purely the consumer's vocabulary — which is the root of the mismatch.

Two ways out, and the choice is yours:

- **Take the status as a parameter.** `book(User $user, string $name, string
  $email, DateTimeImmutable $start, int $duration, ?string $status = null)`,
  and let the caller say what a pending booking means in its own vocabulary.
  This keeps core out of the business of naming approval states.
- **Write `'needs_approval'`.** Simpler, and it makes the comment true for
  this consumer, but it hard-codes an application-level concept into a library
  that otherwise has no opinion about approval.

`webcalendar-api` now filters `needs_approval`, `rejected` and `cancelled` out
of every public read path it owns, so once bookings land in a pending state
they stay unpublished until somebody approves them. Today they are published
the moment they are created.

---

## 3. `BookingService::book()` skips the description sanitizer

```php
name: 'Booking: ' . $name,
description: 'Booked by ' . $name . ' (' . $email . ')',
```

Both strings come from an anonymous HTTP request in the consumer, and this is
the one write path that does not pass a description through
`App\Service\DescriptionSanitizer`. Every controller write path in
`webcalendar-api` does — `CreateEventController`, `UpdateEventController`,
`TaskController`, `JournalController` — and the React client relies on that
invariant explicitly:

```tsx
// RichTextDisplay.tsx
// HTML is sanitized server-side by DescriptionSanitizer before storage.
<div dangerouslySetInnerHTML={{ __html: html }} />
```

So a booking name containing markup is stored unsanitized and then rendered
into the calendar owner's authenticated session.

`webcalendar-api` now refuses `<` and `>` in a booking name, which closes this
for that consumer. It is a guard at the wrong layer, though: any other caller
of `book()` inherits the original behaviour.

The clean fix is a sanitizer seam, in the same shape as `RateLimiterInterface`
and `WebhookProviderInterface` already in `Application/Contract/`:

```php
interface HtmlSanitizerInterface
{
    public function sanitize(string $html): string;
}
```

injected into `BookingService` and applied to the description it builds. The
consumer would wire its existing `DescriptionSanitizer` to it.

---

## 4. A slot that begins when an appointment ends reads as busy

`DateRange::overlaps()` is closed at both ends:

```php
return $this->startDate <= $other->endDate && $this->endDate >= $other->startDate;
```

`BookingService::getAvailability()` uses it to decide which half-hour slots are
free, so a 09:00-10:00 appointment marks three slots busy rather than two: the
10:00-10:30 slot "overlaps" an event that finished at 10:00. Every appointment
costs the slot immediately after it.

Observed in the consumer's tests — a single 09:00-10:00 event leaves 13 free
slots in a 9-to-5 day, where 14 are actually free.

The fix in the interval arithmetic is one character each side:

```php
return $this->startDate < $other->endDate && $this->endDate > $other->startDate;
```

but `overlaps()` is shared with conflict detection, where a half-open reading
is also the correct one — an event ending at 10:00 does not conflict with one
starting at 10:00. So this looks like a single fix that improves both, rather
than a trade-off. It is filed as low severity only because the current
behaviour is conservative: it hides free time rather than double-booking
anybody. It is worth checking the other callers of `overlaps()` before
changing it.

Related, and mentioned only because it turned up alongside: `getAvailability()`
hardcodes 09:00-17:00 in 30-minute slots with no timezone attached to `$date`,
and its own comment acknowledges the first half ("could be loaded from user
preferences"). That produces plausible-looking but wrong availability for
anybody outside those hours or that timezone. Not a security issue, and not
something the consumer can correct from outside.

---

## Not issues

Recorded so nobody re-investigates them:

- **`PdoEventRepository::save()` auto-creating the participant row** at status
  `'A'` is correct; the delete-and-reinsert around it is guarded and commented.
- **`AbstractEntry` rejecting a negative duration** is right. The consumer was
  letting one through; that has been fixed there.
- **`ActivityLogType`** covers every code the consumer maps, exactly. A row
  carrying anything else would make `ActivityLogType::from()` throw, but
  nothing in either codebase writes such a row — only a legacy import could.

---

## Resolution

All four were reproduced, then fixed. 479 tests pass; PHPStan level 9, Psalm
and Psalm taint analysis are clean; shipped `src/` parses on PHP 8.1 and 8.4.

### 1. Feed scoping — `FeedService`

`generateRss()` now asks for public entries belonging to the owner only:

```php
$this->eventService->getEventsInDateRange(
    $range, null, AccessLevel::PUBLIC->value, [$user->login()]
);
```

`generateFreeBusy()` gained a third parameter,
`bool $includePrivate = true`. The judgement call in the report is answered
this way: VFREEBUSY publishes periods with no detail, and a free/busy feed
that hid private appointments would report the owner as free and invite
double-booking — so private time stays in by default, and a deployment that
would rather publish nothing about it passes `false`.

Both methods also pin `[$user->login()]`. The old call returned *other
people's* public events as the owner's busy time, which was wrong
independently of the privacy leak.

Covered by `FeedServiceTest` (argument-shape assertions) and, because the fix
is really about what the SQL does, by two new integration tests in
`PdoEventRepositoryTest` that pin both argument shapes against the shipped
SQLite schema:

```
findByDateRange($range, $owner)            -> CONFIDENTIAL: salary review
                                              PRIVATE: divorce lawyer
                                              Public standup
                                              Someone else public
findByDateRange($range, null, 'P', ['jdoe']) -> Public standup
```

### 2. Booking status — parameterized

The first of the two options in the report. `book()` takes an optional
`?string $status = null`, falling back to a documented
`BookingService::DEFAULT_STATUS` (`'TENTATIVE'`). Core keeps no opinion about
approval-state vocabulary; the caller names the state its own queue reads.

### 3. Sanitizer seam — added

`Application/Contract/HtmlSanitizerInterface`, in the shape the report
proposed, plus a default `Infrastructure\Security\PlainTextHtmlSanitizer`
so the behaviour is safe without wiring. `BookingService` takes it as an
optional constructor dependency and applies it to both strings it builds.

The default reduces input to plain text rather than escaping it: a stored
value is rendered as HTML in a web client and as plain text in an iCalendar or
RSS export, and escaped entities are only correct in one of those. It
decodes-then-strips until the string stops changing (bounded at five passes),
so `&lt;script&gt;` cannot survive to become live markup downstream, and drops
control characters that would corrupt a serialization.

### 4. `DateRange::overlaps()` — half-open

Changed to `$this->startDate < $other->endDate && $this->endDate > $other->startDate`.

The report noted this is shared with conflict detection; in fact
`ConflictDetector` never called it — it open-codes the *correct* half-open
form (`StartA < EndB && EndA > StartB`). So `overlaps()` was the only place in
the repository reading intervals as closed, which settles the question: the
change makes the value object agree with the domain service and with RFC 5545,
where DTEND is exclusive. `BookingService` was its only caller in `src/`.

**This is a behaviour change to a shipped public method.** A range that begins
exactly when another ends no longer overlaps it, and a zero-length range now
overlaps nothing. `DateRangeTest` previously asserted the opposite for
adjacent ranges, under a comment that was openly unsure about it
(`// Adjacent ranges (should they overlap?)`); that assertion is now inverted
and explained.

Also from the "related" note: `getAvailability()` no longer hardcodes office
hours. It takes `startHour`, `endHour` and `slotMinutes` (defaults 9, 17, 30),
validates them, and documents that slots are built in `$date`'s own timezone —
the honest fix at this layer, since core has no access to a user's timezone
preference (`User` carries none).

### What `webcalendar-api` should change

1. **Pass the booking status.** `book(..., status: 'needs_approval')`, so
   bookings land in the queue `ApprovalController::listPending()` reads.
   Without this they are still created as `'TENTATIVE'`.
2. **Wire the sanitizer.** Pass `DescriptionSanitizer` into `BookingService`
   as `HtmlSanitizerInterface` to keep one sanitizer across every write path.
   The downstream guard rejecting `<` and `>` in a booking name can then go —
   core no longer depends on it.
3. **Decide on free/busy.** The public endpoint now returns only the owner's
   entries. If that feed should not publish private time either, pass
   `includePrivate: false`.
4. **Re-check availability expectations.** A 09:00-10:00 appointment now
   leaves 14 free slots in a 9-to-5 day, not 13. Any test pinning the old
   count needs updating.

---

## Post-release review (v4.11.1)

An independent review of the v4.11.0 diff found four defects in the fixes
themselves. All are fixed; 490 tests pass with PHPStan level 9, Psalm and
Psalm taint analysis clean.

**Semicolon-less character references bypassed the sanitizer.** A browser
following the HTML5 tokenizer decodes `&#60script` and `&ltscript` --
unterminated numeric references, and the legacy named set --
but `html_entity_decode()` returns them untouched, so they sailed through the
decode/strip loop and were stored verbatim.

The practical severity is narrower than it first looks, and worth stating
precisely: a character reference decodes to a *character token*, not markup,
so `&#60script&#62` inserted via `innerHTML` renders as the literal text
`<script>`, it does not execute. The real defect is that the multi-pass loop
exists specifically to survive one more decode downstream, and that defence
had a hole for exactly the forms PHP declines to decode. `sanitize()` now
supplies the missing semicolon before each pass. Ordinary prose is untouched
(`Tom & Jerry`, `AT&T`, `a&b=1` all survive), because only sequences that
already look like a reference are rewritten.

**`book()` logged the raw strings it had just sanitized.** The sanitized
values went to storage while the log line carried the originals, handing the
payload to any log viewer that renders HTML. Both logged fields are now
sanitized.

**`getAvailability()` could offer a slot running past closing time.** With
the hours hardcoded to 9-17 in 30-minute slots this was unreachable; once all
three became parameters, any window that is not a whole number of slots long
overhung -- `slotMinutes: 45` in a 9-17 day ended with a 16:30-17:15 slot. A
slot that does not fit entirely inside office hours is no longer emitted.

**A docblock claim was false.** `DateRange::overlaps()` said a zero-length
range overlaps nothing. It does not overlap another range it merely touches,
but it does overlap one it lies strictly inside. Corrected, and pinned with a
test.

### ReportService (fixed separately, see below)

`ReportService::generateFullReport()` took `$userLogin`, never used it
(`@psalm-suppress UnusedParam`), and called `getEventsInDateRange($range)` with
neither a user nor an access level -- the "admin path, no access filter"
branch. It returned every user's PRIVATE and CONFIDENTIAL entries regardless of
who ran the report. Same defect class as finding 1.

---

## ReportService scoping

A report covers exactly one calendar. Legacy says so in the schema --
`webcal_report.cal_user`, "user calendar to display (NULL indicates current
user)" -- and `report.php` reads events for that single `$report_user`. The
old code covered all of them at once, with no access filter.

`generateFullReport()` now takes the actor and an optional target calendar:

```php
public function generateFullReport(
    Report $report,
    DateRange $range,
    User $actor,
    ?string $targetLogin = null
): string
```

| Actor reporting on | Sees |
|---|---|
| their own calendar | every entry, whatever its access level |
| another user's, as admin | every entry of that user's |
| another user's, otherwise | public entries only |

Every branch pins the query to one calendar, so the unfiltered
every-user-every-access-level path is no longer reachable from a report.

**The third rule is deliberately conservative.** Legacy grants per-calendar
rights through `webcal_access_user`, and this library has no repository for
that table yet -- it appears in `PdoUserRepository` only in the delete-cascade
list. So core cannot currently tell an authorized viewer of someone else's
calendar from any other user, and it under-reports rather than over-shares.
When those grants land, `findReportableEvents()` is the one place that should
consult them.

**This is a breaking signature change**: the third parameter went from
`?string $userLogin` to `User $actor`. Nothing outside this repository's own
tests called it, and the parameter it replaces was documented as unimplemented,
but a consumer passing a login string positionally will now get a TypeError --
which is the right failure, since that call was silently reading every user's
private entries.

Two repository integration tests pin the boundary this depends on: an
unfiltered query constrained only by the users list returns just that user's
entries, and a fully unconstrained query returns everybody's private ones.

