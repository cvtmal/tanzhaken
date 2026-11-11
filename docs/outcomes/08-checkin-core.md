# #8 checkin-core — outcome (2026-11-11)

## Delivered

The S8 check-in rules, backend only. A teacher can open a 60-minute check-in window for a lesson (this completes the lesson and closes any earlier window), close it, or reopen it, which always issues a new token. A token is 32 random bytes in base64url; only its SHA-256 hex is stored. `resolve(token)` returns the lesson facts or one typed reason for refusal, checked in S8 order. The public side returns the roster labels for a valid token and confirms one enrollment on top of #7's `CheckInRecorder`, returning the outcome, the label and that enrollment's balance. Two rate limiters (per IP, per token hash) are wrapped in a throttle service. There is no route, template, translation, migration or seed change.

## Interfaces

- `App\CheckIn\Service\CheckInTokens`: `issue(): IssuedToken{plain, hash}`, `hash(string): string`, `isWellFormed(string): bool` (`/^[A-Za-z0-9_-]{43}$/`).
- `App\CheckIn\Service\CheckInWindows` (`DURATION_MINUTES = 60`):
  - `open(Teacher, Uuid $lessonId): OpenedCheckIn{windowId, lessonId, token, expiresAt}`;
  - `close(Teacher, Uuid $lessonId): bool`;
  - `resolve(string $token): ValidCheckIn|CheckInRefusal`, where `ValidCheckIn` = windowId, lessonId, lessonStartsAtUtc, lessonEndsAtUtc, courseId, courseName, courseTimezone, teacherId, teacherName, expiresAt.
- `App\CheckIn\Service\PublicCheckIn`:
  - `roster(string $token): PublicRoster{checkIn: ValidCheckIn, students: list<RosterLabel{enrollmentId, label}>}|CheckInRefusal`;
  - `confirm(string $token, Uuid $enrollmentId): CheckInConfirmation{outcome: CheckInOutcome, label, balance: int}|CheckInRefusal`.
- `App\CheckIn\Service\CheckInRefusal` (enum): `Malformed`, `Unknown`, `Closed`, `Expired`, `LessonCancelled`, `CourseArchived`, `NotEnrolled`.
- `App\CheckIn\Service\CheckInThrottle`: `allowIp(string $ip)` and `allowToken(string $tokenHash)` each return `?\DateTimeImmutable`: null when allowed, otherwise the retry-after time.
- `App\CheckIn\Service\RosterLabeller::label(list<RosterEntry>): list<RosterLabel>`.
- Read models: `App\CheckIn\ReadModel\CheckInLookup::byTokenHash(): ?CheckInFacts` (one query over window, lesson, course and teacher); `CheckInRoster::forCourse(Uuid): list<RosterEntry>` (active enrollments of non-archived students).
- `CheckInWindowRepository::findActiveForLesson(LessonSession, \DateTimeImmutable $now): ?CheckInWindow` (newest active window).
- Exceptions: `CheckInNotFound::lesson()` (answer 404), `InvalidCheckInWindow::lessonCancelled()`, `InvalidCheckInWindow::courseArchived()`.
- Config: `config/packages/rate_limiter_checkin.yaml` defines `checkin_ip` (sliding_window, 100 per 5 minutes) and `checkin_token` (sliding_window, 300 per 60 minutes). Services `limiter.checkin_ip` and `limiter.checkin_token`, autowired as `$checkinIpLimiter` and `$checkinTokenLimiter`.
- Audit events (entity `check_in_window`): `checkin.opened` `{lesson_id, expires_at, closed_windows}`, `checkin.closed` `{lesson_id}`, plus #5's `lesson.completed` on the first open.
- Tables: none changed. `check_in_window` is used as #3 created it.

## How to use it

- **#14:**
  1. Call `CheckInThrottle::allowIp()` before any query.
  2. Call `allowToken(CheckInTokens::hash($token))` after `isWellFormed()`.
  3. Call `PublicCheckIn::roster()` / `confirm()`.
  4. Map every `CheckInRefusal` to one public message, and map `CheckInOutcome::MarkedByTeacher` to "the teacher has marked this lesson". The confirm POST sends the enrollment id.
- **Labels (P2):**
  1. A label is the enrollment's `publicCheckinLabel` when set; otherwise the first name plus the last initial (`Anna M.`).
  2. When two generated labels collide (compared case-insensitively), each grows by one more last-name letter, up to 3 letters and never the whole last name (`Anna Mü.` / `Anna Ma.`).
  3. Labels that still collide get ` (2)`, ` (3)` … in order of joining.
  4. The list is sorted with `\Collator('en')`.
- **Functional tests (#14):** reset the limiter keys in every functional test. `cache.app` persists between tests, so the counts pile up.
- **#16:** `CheckInWindows::open()` / `close()` from `checkin_manage`. Keep `OpenedCheckIn::$token` in the session keyed by window id; it is the only place the plain token exists. Find the open window with `findActiveForLesson()`; if the session has lost the token, reopen.

## Decisions

- **Reopen:** reopening is `open()` again; there is no `rotate()` method. `open()` closes every unclosed window, expired ones included, so exactly one row has `closed_at IS NULL` afterwards.
- **Close:** `close()` touches only active windows. A window that expired without being closed is dead by expiry and stays as it is.
- **Archived students (D-035):** they are excluded from the roster and get `NotEnrolled` at confirm, even while still enrolled.
- **Public view objects carry no student id** (P6). Undo by adding the field to `RosterLabel`.
- **The teacher for `Balances`** is loaded with `TeacherRepository::find()` before the insert, so a missing teacher (`Unknown`) writes nothing (P5).
- **Two "cannot happen" branches throw `\LogicException`**: no balance after the insert, and no label for a roster entry. Returning a refusal there would claim nothing was written.
- **Limiter numbers are tested from the YAML file** (P7), because the limiter services are not in the compiled container until #14 injects the throttle.

## Deviations

- `CheckInNotFound` extends `\DomainException`, as the plan says. The other modules' not-found exceptions extend `\RuntimeException`.
- `confirm()` loads the teacher before `recordCheckIn()`; the plan listed it after.
- A generated label is trimmed, so an empty first name gives no leading space.

## Carried forward

- **#14:**
  - CSRF or other request integrity on the confirm POST;
  - `noindex` and `Referrer-Policy: no-referrer`;
  - wiring the throttle into the request, with limiter keys reset in functional tests;
  - the wording of the refusals, of `MarkedByTeacher` and of a negative balance.
- **#14 / #10:** P2 has no rule for a ` (n)` suffix that collides with a custom label such as `Anna (2)`.
- **#16:** the plain token in the session, a reopen when it is missing, polling.
- **#19:** an audit event per scan (#7 D3).
- **#21:** two `open()` calls at once on MariaDB (serialisation relies on the closing UPDATE being the first statement, P8; the alternative is an `active_flag` column with a unique index); #7's `INSERT … SELECT` on MariaDB.
- **#16 / #21 (review note):** `open()` checks the lesson before its transaction; a cancel of the same lesson in a concurrent request can be overwritten by `complete()` on the stale entity. Re-read the status inside the transaction if #16 shows it matters.
- **#23:** untested branches: `InvalidAttendance` → `NotEnrolled` (a leave or cancellation racing the insert); the missing teacher; the two `\LogicException` branches; `close()` when its UPDATE hits 0 rows.

## Verification

- Every command exits 0: `make cs-fix`, `make lint`, `make test` (346 tests, 3368 assertions), `make db-fresh && make seed`, `doctrine:schema:validate`, `debug:config framework rate_limiter`.
- New tests: `CheckInTokensTest` 13, `CheckInThrottleTest` 3, `RosterLabellerTest` + `CheckInLookupAndRosterTest` 17, `CheckInWindowsTest` 15, `PublicCheckInTest` 15 (Journey C and 12 Journey D cases, each leaving attendance, credits, audit and balance unchanged), `CheckInWindowRepositoryTest` +2.
- Review (2026-11-11): `make lint` pass, `make test` OK (346 tests, 3368 assertions); D-026, D-027, D-035 checked against the code.
- Pages: none. This cycle is backend only, so the S10 check at 390 and 1440 px does not apply. Nothing is pending for a human.
