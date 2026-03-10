# AI-Generated Code Audit: webcalendar-core

Scan performed against signals cataloged in `AI-SIGNALS.md`.
Scope: `src/`, `tests/`, and top-level `.md` files. Excludes `legacy/` and `vendor/`.

---

## Findings and Resolutions

### 1. Comment Signals

**Instructional/hedging phrase** (1 match) -- FIXED
- `PdoSiteExtraRepository.php:91` -- "Note: Does NOT unserialize..." rewritten to imperative style.

**Over-explaining comments** (2 matches) -- FIXED
- `PdoSiteExtraRepository.php:110` -- "Check if this looks like..." shortened.
- `PdoEventRepository.php:675` -- "Check if RDATEs exist (PRD says...)" shortened.

**"This" sentence comment** (1 match) -- FIXED
- `PdoReportRepository.php:82` -- "This is simplified..." rewritten.

**Bookend section markers** (5 matches) -- FIXED
- `EventServiceTest.php` -- removed all `// --- section name ---` dividers.

### 2. PHPDoc Over-Annotation

**Trivial getter docblocks** (5) -- FIXED
- Removed docblocks from `DateRange::startDate()`, `DateRange::endDate()`, `EventId::value()`, `RecurrenceRule::toString()`, `AbstractEntry::end()`.

**Redundant @return annotations** (6) -- FIXED
- `SecurityService.php` -- 4 methods: removed `@param`/`@return` noise, kept meaningful summary.
- `EventMapper.php` -- 2 methods: removed `@param`/`@return` that duplicated the type signature.

**Constructor over-annotation** (3 files) -- FIXED
- `AbstractEntry.php` -- removed 14 trivial `@param` lines, kept only `@throws`.
- `Task.php` -- removed 14 trivial `@param` lines, kept only `@throws`.
- `User.php` -- removed 6 trivial `@param` lines, kept only `@throws`.

**Repetitive interface @param** (2 files) -- FIXED
- `RateLimiterInterface.php` -- replaced per-method `@param` blocks with concise summaries.
- `TokenRepositoryInterface.php` -- replaced per-method `@param` blocks with concise summaries.

**Other @param cleanup** -- FIXED
- `ExportService.php`, `RecurrenceService.php`, `PermissionService.php`, `EventRepositoryInterface.php` -- removed param descriptions that restated parameter names.

### 3. Structural Signals

**Catch-log-rethrow antipattern** (1 match) -- FIXED
- `ImportService.php:65-70` -- removed pointless try/catch around `$this->parser->parse()`. The caller already handles the exception.

**Redundant test assertions** (2 matches) -- FIXED
- `EventTest.php:43` -- removed `assertInstanceOf(Recurrence::class, ...)` before behavior assertion.
- `EventServiceTest.php:73` -- removed `assertInstanceOf(EventCollection::class, ...)` before value assertion.

### 4. Documentation Buzzwords

**"comprehensive"** (8 matches) -- FIXED
- `PRD.md` -- 2 instances replaced with "Full" / removed.
- `STATUS.md` -- 2 instances replaced with specific descriptions.
- `GEMINI.md` -- 1 instance removed.
- `OPEN_ISSUES.md` -- 3 instances replaced with specific descriptions.

**"robust"** (3 matches) -- FIXED
- `PRD.md` -- 2 instances (duplicate sections) replaced with "well-tested".
- `STATUS.md` -- 1 instance removed.

### 5. Items Not Changed (intentional)

**Silent catch in PdoSiteExtraRepository.php:79-81** -- `\JsonException` on `json_encode()` of user data. Returning empty string is acceptable fallback for a non-critical encoding path.

**Fire-and-forget catches in NotificationService.php** -- Intentional design: notification failures must not block the caller. Errors are logged.

**Defensive checks in FeedService.php** -- Marked `@codeCoverageIgnore`; these guard against edge cases in PHP's XML API where `ownerDocument` and `addChild()` can theoretically return null.

**Em-dash usage in CLAUDE.md, AGENTS.md, PRD.md** -- These are configuration/requirements docs where em-dash separators are used as a structural convention (label -- definition). Changing them would harm readability without meaningful benefit.

---

## Summary

| Category | Found | Fixed |
|----------|-------|-------|
| Comment signals | 9 | 9 |
| PHPDoc over-annotation | 22+ | 22+ |
| Structural antipatterns | 3 | 3 |
| Documentation buzzwords | 11 | 11 |
| **Total** | **45+** | **45+** |

All tests pass (200/200). PHPStan clean (0 errors).
