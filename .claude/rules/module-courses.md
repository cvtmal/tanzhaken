---
paths:
  - "src/Courses/**"
  - "tests/**/Courses/**"
  - "templates/course/**"
  - "translations/courses+intl-icu.en.yaml"
---

# Module `Courses`

- **Holds:** `Course`, `Enrollment`, `LessonSession`, schedule generation, the lesson lifecycle and the course pages.
- **May depend on:** Students, Account, Shared. A fact owned by Credits or Attendance is read with a DBAL query of Courses' own (e.g. whether a lesson has attendance rows).
- **Owning cycles:** #3 (entities), #5 (scheduling), #10 (pages).
- **Routes (S5):** `course_index` `/courses`, `course_new` `/courses/new`, `course_show` `/courses/{id}`, `course_edit` `/courses/{id}/edit` (#10).
- **Tables (S3):** `course`, `enrollment`, `lesson_session` (#3; #5 add-only on `course`, `lesson_session`).
- **Templates:** `templates/course/**`.
- **Translation domain:** `courses` (`translations/courses+intl-icu.en.yaml`).
- **Hotspot slots:** `templates/course/show.html.twig` and `course/_roster_row.html.twig` belong to #10; they include `course/_roster_balance.html.twig` (#12, Credits) and `course/_export_action.html.twig` (#15, Attendance), which are not Courses' files.

Namespace `App\Courses\<Layer>`. Placeholder actions in `src/Courses/Controller/PlaceholderController.php` are deleted by the cycle that ships the real route. Cycles append notes for this module below.

## #3 notes (domain-model)

- `Entity\Course(Teacher, string $name, string $timezone, int $weekday, \DateTimeImmutable $localStartTime, int $durationMinutes, \DateTimeImmutable $startsOn, \DateTimeImmutable $now, ?\DateTimeImmutable $endsOn = null, ?string $description = null, ?string $location = null)`; `changeDetails()`, `changeSchedule()`, `archive($now)`.
- `Entity\Enrollment(Course, Student, \DateTimeImmutable $now, ?string $publicCheckinLabel = null)`; `changeLabel()`, `leave($now)`, `rejoin()`. Unique `(course_id, student_id)`: rejoin, never re-insert.
- `Entity\LessonSession(Course, \DateTimeImmutable $startsAtUtc, \DateTimeImmutable $endsAtUtc)` (UTC, truncated to whole seconds); `complete()`, `cancel($now)`; `Entity\LessonStatus` `scheduled|completed|cancelled`. Unique `(course_id, starts_at_utc)`.
- Repositories: `findOwned(Teacher, Uuid)` on Course/Enrollment/LessonSession; `LessonSessionRepository::hasAttendance(LessonSession): bool` (DBAL `SELECT 1 FROM attendance`, so Courses does not depend on Attendance). Exception: `Exception\InvalidCourse` (also for Enrollment and LessonSession).

## #5 notes (scheduling)

- `Service\ScheduleGenerator` (pure, no deps): `utcStart(string $tz, \DateTimeImmutable $localWallClock)`, `occurrences(Course, $fromLocalDate, $untilLocalDate): list<Service\Occurrence>`. Local wall clock → UTC with `setTime()` in the course zone (RFC 5545 §3.3.5): a time in the spring gap moves forward by the gap, a repeated autumn time takes its first occurrence. End = UTC start + duration (elapsed time).
- `Service\LessonScheduler` (final): `generateInitial(Course, int $weeks = 26)`, `extensionTarget(Course, int $weeks = 26)`, `extend(Teacher, Uuid $courseId, $untilLocalDate)`, `addLesson(Teacher, Uuid $courseId, $localStart, ?int $minutes)`, `reschedule(Teacher, Uuid $lessonId, $localStart, ?int $minutes)`, `cancel(Teacher, Uuid $lessonId, ?string $reason)`, `complete(Teacher, Uuid $lessonId)`, `reinstate(Teacher, Uuid $lessonId)`. Each change writes one audit event (`schedule.generated|extended`, `lesson.added|rescheduled|cancelled|completed|reinstated`).
- Local dates: a `\DateTimeImmutable` of which only `Y-m-d` counts. Days are advanced on a UTC midnight value, never by hours.
- Extension window: from max(`startsOn`, today in the course zone, day after the latest lesson of any status) up to the target, clipped to `endsOn`; at most 104 weeks per run. Existing starts (any status) are skipped, so a re-timed lesson's old slot is not recreated and a cancelled slot stays taken (reinstate it instead).
- `Exception\NotFound` (course/lesson) → 404 at the controller. `Exception\InvalidCourse` → form error.
- Refusals are thrown before `wrapInTransaction()` (S2). Only the race backstops throw inside it: cancel's second attendance check and a unique-key violation (mapped to `InvalidCourse::lessonTimeTaken()`); after those the EntityManager is closed.
- Not wired until #10 injects it: the container removes `LessonScheduler` and `AuditLogger` as unused, so integration tests build it by hand (`tests/Integration/Courses/BuildsLessonScheduler.php`, D-026).

## #10 notes (courses-ui)

- Routes: `course_index` GET, `course_new` GET|POST, `course_show` GET, `course_edit` GET|POST, and POST-only `course_archive`, `course_extend`, `course_lesson_add`, `course_lesson_reschedule|cancel|reinstate` (`/courses/{id}/lessons/{lessonId}/…`), `course_enroll`, `course_enrollment_remove|label` (`/courses/{id}/enrollments/{enrollmentId}/…`). Every `{id}`, `{lessonId}`, `{enrollmentId}` has `Requirement::UUID` (P8). Controllers: `CourseController`, `LessonController`, `EnrollmentController`; the template variable contract is `CourseController`'s class docblock.
- Ownership (P2): the controller loads the course with `CourseRepository::findOwned` (else 404) and a nested lesson/enrollment with its own `findOwned` **plus** "belongs to this course" (else 404). The services check again. The voter is not called by hand.
- Errors (P5): a sub-route POST always answers 303 to `course_show` with a `success` or `error` flash (refusal message from `InvalidCourse::translationKey()/translationParameters()`, domain `courses`); only `course_new`/`course_edit` re-render with 422. Never re-render after a race backstop (closed EntityManager).
- `Service\CourseCatalog`: `create(Teacher, name, weekday, localStartTime, durationMinutes, startsOn, ?endsOn, ?location, ?description): Course` (zone = teacher's, D3; calls `LessonScheduler::generateInitial` in the same transaction), `update(Teacher, Uuid, …same fields)` (never touches existing lessons, D2), `archive(Teacher, Uuid)`. Audits `course.created|updated|archived`.
- `Service\Roster`: `enroll(Teacher, Uuid $courseId, Uuid $studentId, ?string $label = null): Enrollment` (a left enrollment is rejoined, never re-inserted), `remove(Teacher, Uuid $enrollmentId)` (soft: `leave()`, keeps attendance and grants), `changeLabel(Teacher, Uuid $enrollmentId, ?string)`. Label unique among the course's active enrollments, case-insensitive in PHP (D6). Archived students cannot be enrolled (D-035). Audits `enrollment.created|rejoined|left|label_changed`.
- Read models (one DBAL query each): `CourseOverview::forTeacher(): list<CourseCard>` / `lastLocation()`, `CourseLessons::forCourse(): list<LessonRow>` + static `next()` / `previous()`, `CourseRoster::forCourse(): list<RosterRow>`, `EnrollableStudents::forCourse(): list<StudentOption>`.
- Named forms on the course page: `ConfirmActionType::formName($action, Uuid)` = `<action>_<base58 id>`; build every new course-page form through it so GET and POST agree.
- Slots (not Courses' files, included with `ignore missing … only`): `course/_card_badges.html.twig` {course_id: Uuid} (#12); `course/_roster_balance.html.twig` {course: {id, name, timezone}, row: RosterRow} (#12) — included **inside the `<tr>`**, so it renders its own `<td data-label="…">`; `_roster.html.twig` has no "Lessons left" `<th>` yet (a CTO-granted one-line edit for #12); `course/_export_action.html.twig` {course: {id, name, timezone}} (#15).
- Tests: `tests/Integration/Courses/BuildsCourseServices.php` (`self::courseCatalog()`, `self::roster()`), `tests/Functional/Courses/CoursePages.php` (`formFor()`, `followToFlash()`, `coursePath()`, `countWhere()`, `countRows()`).
