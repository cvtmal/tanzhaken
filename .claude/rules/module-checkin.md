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

## #14 notes (checkin-public)

- **Routes:** `checkin_public` GET `/c/{token}` → `PublicCheckInController::roster()`; `checkin_public_confirm` POST `/c/{token}/confirm` → `confirm()`. Public (`^/c/` in `security.yaml`), no session, no CSRF token (D-041). A GET on `/confirm` gets Symfony's 405 page (P6).
- **Order of checks on the POST (D-041):** `Sec-Fetch-Site: cross-site` → 403 (before any limiter, so a forged request cannot burn the student's IP quota) → `allowIp()` → `allowToken(hash)` for a well-formed token (both 429 + `Retry-After` in whole seconds ≥ 1) → `PublicCheckIn::roster()`, any refusal → 404 → missing or non-UUID `enrollment` → 422 with the roster re-rendered → `confirm()`: refusal → 404, `Recorded` / `AlreadyPresent` → result page, `MarkedByTeacher` → message (200). The GET runs the same steps from the limiters to `roster()`. Every refusal shows the one `message.refused` text.
- **S8 headers** are set in one private helper, `publicResponse()`: `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store, private`, on every response of both routes (roster, 422, result, 403, 404, 429). The meta tags `robots` and `referrer` come from `checkin/_base.html.twig`.
- **Templates:** `templates/checkin/` — `_base`, `roster` (H1), `_name_tile`, `result` (H2), `_pass_card` (H2/H3), `message` (refused / marked / too_many, on the error-page classes). Strings in the `checkin` catalogue; lesson time = `lesson.when` from `lesson.date_pattern` + `format_time(timeFormat: 'short')` in the course zone (D-042). CSS `assets/styles/checkin/checkin.css`; JS `assets/js/checkin-select.js` (footer status, label in `<strong>` from `data-checkin-label`, text around it from `data-checkin-status-before/-after`).
- **`_base.html.twig` copies the public shell** (wordmark header, `<main id="main">`, `layout/_footer.html.twig`) minus `layout/_flashes.html.twig`: reading `app.flashes` starts a session, and Symfony's session listener then rewrites `Cache-Control` to `max-age=0, must-revalidate, no-store, private`. Any cycle that changes the shell of `layout/public.html.twig` must change this copy too.
- **Tests:** `tests/Functional/CheckIn/OpensPublicCheckIn.php` (trait): lesson + enrollments + grants, a window with the fixed `TOKEN` via `CheckInWindowFactory` (hash = `hash('sha256', $token)`), `resetCheckInLimits()` (call in `setUp`), `exhaustLimit()`, attendance/`Balances` helpers, `assertPublicResponse()` (S8 headers, both metas, no `Set-Cookie`). #16 can reuse it once `CheckInWindows::open()` is injected by a route.

## #16 notes (checkin-teacher-ui)

- **Routes** (`Controller\CheckInManageController`, `ROLE_TEACHER`, `{id}` = lesson UUID): `checkin_manage` GET `/lessons/{id}/check-in`, `checkin_status` GET `…/status`, `checkin_open` POST `…/open` (CSRF `checkin_open`), `checkin_close` POST `…/close` (CSRF `checkin_close`). Foreign / unknown lesson → 404.
- **Plain token only in the session:** `Service\CheckInTokenStore` keeps `OpenedCheckIn::$token` under `checkin.token.<lesson id>` with the window id; `tokenFor($lessonId, $activeWindowId)` returns it only when it belongs to the active window. Missing (other device, new session) → state `token_missing`, and "Reopen" (`checkin_open` again) rotates the code; the old URL dies. Never store or log the plain token anywhere else.
- **Status JSON is counts only** (S4): `{state, expiresAt, secondsLeft, checkedIn, enrolled}`, `Cache-Control: no-store, private`. No names, ids or tokens — keep it that way when extending it.
- **Lead time is UI only:** `Service\CheckInOffer::LEAD_MINUTES = 15` decides whether "Open check-in" is offered (slot and page); the POST is not gated until #19 adds the rule in `CheckInWindows::open()` (D-055).
- **Polling** (`assets/js/checkin-live.js`) every 5 s, 15 s after an error, skipped while the tab is hidden; never below 5 s (S8).
