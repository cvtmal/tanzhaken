# #05 scheduling — outcome (2026-11-11)

## Delivered
Backend only. A pure `ScheduleGenerator` that turns a course's weekly template into lessons in the course's IANA zone, correct across DST (Journey H), and one `final` service `LessonScheduler` in `App\Courses\Service` to generate, extend, add, re-time, cancel, complete and reinstate lessons. It checks ownership, refuses before the transaction (S2) and writes an audit event for every change. Also new: `LessonSession::reschedule()/reinstate()`, three `LessonSessionRepository` finders, `Exception\NotFound`, seven `InvalidCourse` constructors and unit and integration tests. No controller, route, template, translation, migration or config change.

## Interfaces
- **`Service\ScheduleGenerator`** (no deps): `utcStart(string $timezone, \DateTimeImmutable $localWallClock): \DateTimeImmutable`; `occurrences(Course, \DateTimeImmutable $fromLocalDate, \DateTimeImmutable $untilLocalDate): list<Occurrence>` (clipped to `startsOn`/`endsOn`, inclusive, chronological).
- **`Service\Occurrence`** `final readonly`: `startsAtUtc`, `endsAtUtc`, `localDate` (`Y-m-d`).
- **`Service\LessonScheduler`**: constants `DEFAULT_WEEKS = 26`, `MAX_WEEKS = 104`, `REASON_MAX = 255`.

  | Method | Returns | Refuses with | Audit |
  |---|---|---|---|
  | `generateInitial(Course, int $weeks = 26)` | lessons created | `courseArchived`, `invalidWeeks` | `schedule.generated` |
  | `extensionTarget(Course, int $weeks = 26)` | `?\DateTimeImmutable` local date (null: archived or nothing to add) | `invalidWeeks` | — |
  | `extend(Teacher, Uuid $courseId, \DateTimeImmutable $untilLocalDate)` | lessons created | `NotFound`, `courseArchived`, `extensionTooFar` | `schedule.extended` |
  | `addLesson(Teacher, Uuid $courseId, \DateTimeImmutable $localStart, ?int $durationMinutes = null)` | `LessonSession` | `NotFound`, `courseArchived`, `invalidDuration`, `lessonTimeTaken` | `lesson.added` |
  | `reschedule(Teacher, Uuid $lessonId, \DateTimeImmutable $localStart, ?int $durationMinutes = null)` | void (same time: no-op) | `NotFound`, `courseArchived`, `invalidDuration`, `cancelledLessonCannotMove`, `lessonTimeTaken` | `lesson.rescheduled` |
  | `cancel(Teacher, Uuid $lessonId, ?string $reason = null)` | void | `NotFound`, `courseArchived`, `tooLong`, `lessonHasAttendance`, `lessonAlreadyCancelled` | `lesson.cancelled` {reason} |
  | `complete(Teacher, Uuid $lessonId)` | void (idempotent) | `NotFound`, `courseArchived`, `cancelledLessonCannotComplete` | `lesson.completed` |
  | `reinstate(Teacher, Uuid $lessonId)` | void | `NotFound`, `courseArchived`, `lessonNotCancelled` | `lesson.reinstated` |
- **`LessonSessionRepository`**: `latestStart(Course): ?\DateTimeImmutable`, `startsOf(Course): array<string 'Y-m-d H:i:s', true>`, `isStartTaken(Course, \DateTimeImmutable $startsAtUtc, ?LessonSession $except = null): bool` (all three see every status).
- **`Entity\LessonSession`**: `reschedule(\DateTimeImmutable $startsAtUtc, \DateTimeImmutable $endsAtUtc)`, `reinstate()`.
- **Exceptions**: `Exception\NotFound::course(Uuid)` / `::lesson(Uuid)`; `Exception\InvalidCourse::{courseArchived, lessonTimeTaken, lessonHasAttendance, lessonNotCancelled, cancelledLessonCannotMove, invalidWeeks(int), extensionTooFar(int)}`.
- "Local date" arguments: only `Y-m-d` counts; "local start": date and `H:i:s` count, read in the course zone whatever the object's own zone is.

## How to use it
- **#10 courses-ui**: inject `LessonScheduler`. Call `generateInitial($course)` inside the transaction that persists a new course (it takes the `Course`, since `findOwned` cannot see it yet). Render `extensionTarget($course)` into the Extend form and post it to `extend()`. Use `addLesson`/`reschedule`/`cancel`/`reinstate` for the lesson actions. Map `NotFound` → 404 and `InvalidCourse` → form error. Rules: `.claude/rules/module-courses.md` § #5 notes.
- **#8 checkin-core**: call `complete($teacher, $lessonId)` when opening a check-in. It is idempotent and refuses a cancelled lesson. The per-request "lesson not cancelled" check of S8 stays in #8.
- Tests that need the service build it by hand: `tests/Integration/Courses/BuildsLessonScheduler.php`.

## Decisions
- **D1** Wall clock → UTC with `setTime()` in the course zone: a spring-gap time moves forward by the gap (02:30 → 03:30 local), a repeated autumn time takes its first (summer) occurrence. End = start + duration in elapsed time. Undo: change `ScheduleGenerator::utcStart()`.
- **D2** Initial generation: 26 weeks, or up to `endsOn` but never more than 104 weeks. **D3** No series column: an extension starts at max(`startsOn`, today, the day after the latest lesson of any status). **D4** Extending twice to the same target adds nothing. **D5** A cancelled lesson's slot stays taken; the teacher reinstates it (**D6** `reinstate()`).
- **D7/D-026** Module-local `NotFound`, no `src/Shared` file, no services.yaml change. **D8** Cancel checks attendance before the transaction and again inside it (race backstop). **D10** `addLesson` has no date rule (past and far-future dates allowed). **D11** Every change audited; nothing audited when nothing changed.
- **P2** Refusals before `wrapInTransaction()`. **P3** `generateInitial` takes the `Course`. **P4** The 104-week cap is measured from the start of the extension window. **P5** Re-timing a completed lesson is allowed; re-timing to the same time is a silent no-op.
- `reschedule` checks "cancelled" itself, in rule order, and calls the entity only after the slot check, so a refused move leaves no modified entity in the unit of work. The entity keeps its own guard.

## Deviations
- Research D1 gave 03:00 UTC as the end of a 90-minute lesson at 01:30 on 2026-03-29; the correct value is 02:00 UTC (P1). The algorithm is unchanged.
- `generateInitial`/`extend` still open an empty transaction when nothing is new. It writes nothing and audits nothing.
- No 390/1440 px page check: this cycle renders no page.

## Carried forward
- **#10**: what happens to future lessons when a course's template changes (no rule yet); UI copy for Extend, cancel and reinstate; a hint that a far-future ad-hoc lesson moves the extension start; `LessonScheduler` and `AuditLogger` are container-removed until #10 injects the scheduler.
- **#10**: after a race backstop (unique-key violation or attendance appearing mid-cancel) the EntityManager is closed; answer with a redirect and a flash, not a form re-render.
- **#21**: the D8 attendance re-check is a full backstop on SQLite (the UPDATE holds the write lock); on MariaDB/InnoDB it is a non-locking read, so a narrow race remains under the default REPEATABLE READ.
- **Review**: the two race backstops are untested (P7: no deterministic race on single-connection SQLite); the review read both and found them correct (`.rpi-tracking/reviews/2026-11-11/scheduling-review.md`).

## Verification
- `make lint` — pass (cs, phpstan 8 + phpat, twig, yaml, container).
- `make test` — pass, 166 tests, 1059 assertions. New: `ScheduleGeneratorTest` (10, Journey H and DST edges), `LessonSessionTest` (+5), `LessonSessionRepositoryTest` (+3), `LessonSchedulerScheduleTest` (13), `LessonSchedulerLessonTest` (14 + two 5-case providers).
- `make db-fresh && make seed` — pass (seed unchanged); `doctrine:schema:validate` — pass; no change under `migrations/` or `config/`.
- Nothing pending for a human: backend only.
