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
