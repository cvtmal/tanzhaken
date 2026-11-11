---
paths:
  - "src/CheckIn/**"
  - "tests/**/CheckIn/**"
  - "templates/checkin/**"
  - "templates/lesson/_checkin_action.html.twig"
  - "translations/checkin+intl-icu.en.yaml"
---

# Module `CheckIn`

- **Holds:** `CheckInWindow`, the token service, the public check-in flow and the teacher's QR page.
- **May depend on:** Attendance, Credits, Courses, Students, Account, Shared.
- **Owning cycles:** #3 (`CheckInWindow`), #8 (rules), #14 (public flow), #16 (teacher QR page).
- **Routes (S5):** `checkin_manage` `/lessons/{id}/check-in`, `checkin_status` `/lessons/{id}/check-in/status` (JSON) (#16), `checkin_public` `/c/{token}` (#14).
- **Tables (S3):** `check_in_window` (#3, #8 add-only).
- **Templates:** `templates/checkin/**`, `templates/lesson/_checkin_action.html.twig`.
- **Translation domain:** `checkin` (`translations/checkin+intl-icu.en.yaml`).
- **Hotspot slots:** fills `lesson/_checkin_action.html.twig`. Protocol: `docs/rpi-cycles.md` S8.

Namespace `App\CheckIn\<Layer>`. Placeholder actions in `src/CheckIn/Controller/PlaceholderController.php` are deleted by the cycle that ships the real route. Cycles append notes for this module below.

## #3 notes (domain-model)

- `Entity\CheckInWindow(LessonSession, string $tokenHash, \DateTimeImmutable $openedAt, \DateTimeImmutable $expiresAt)`; `close($now)` (throws when closed), `isActiveAt($now): bool`. `token_hash` CHAR(64), unique; index on `lesson_session_id`.
- `Repository\CheckInWindowRepository::findOwned(Teacher, Uuid)`, `findOneByTokenHash(string): ?CheckInWindow` (public path; the token is the capability). Exception: `Exception\InvalidCheckInWindow`.
- **D4:** "at most one active window per lesson" is NOT a DB constraint. #8 enforces it in its transaction (close earlier windows first). Optional add-only design: nullable `active_flag` (1 while open, NULL when closed) + unique `(lesson_session_id, active_flag)`.

## #8 notes (checkin-core)

- `Service\CheckInTokens`: `issue(): IssuedToken{plain, hash}` (32 random bytes, base64url, 43 chars), `hash()` (SHA-256 hex), `isWellFormed()`. Only the hash is stored.
- `Service\CheckInWindows`: `open(Teacher, Uuid $lessonId): OpenedCheckIn` (closes every unclosed window of the lesson as the first statement of the transaction, completes the lesson, new 60-minute window, audit `checkin.opened`); reopening = `open()` again (always rotates). `close(Teacher, Uuid): bool` (active windows only; false = nothing to close, no audit). `resolve(string $token): ValidCheckIn|CheckInRefusal`, checked in S8 order: Malformed → Unknown → Closed → Expired → LessonCancelled → CourseArchived. Refusals of open/close: `CheckInNotFound` (404), `InvalidCheckInWindow::lessonCancelled()/courseArchived()`, both before the transaction.
- `Service\PublicCheckIn`: `roster(token): PublicRoster|CheckInRefusal`, `confirm(token, Uuid $enrollmentId): CheckInConfirmation|CheckInRefusal` (outcome from #7's `CheckInOutcome`, label, this enrollment's balance from `Balances`). Left enrollments and archived students (D-035) are not on the roster and get `NotEnrolled`. Public view objects carry no student id, email, note or full last name.
- Labels (`Service\RosterLabeller`): the enrollment's `publicCheckinLabel` if set, else `First L.`; colliding generated labels grow to at most 3 last-name letters (never the whole last name), then ` (2)`, ` (3)` by `joinedAt`.
- `Service\CheckInThrottle`: `allowIp()`, `allowToken($tokenHash)` → null or retry-after; limiters `checkin_ip` / `checkin_token` in `config/packages/rate_limiter_checkin.yaml`. #14 calls `allowIp` first, `allowToken` after the format check, then `roster`/`confirm`, and maps every `CheckInRefusal` to one public message. Functional tests must reset the limiter keys (`cache.app` persists between tests).
- #16 keeps `OpenedCheckIn::$token` in the session (the only place the plain token exists) and finds the open window with `CheckInWindowRepository::findActiveForLesson()`.
