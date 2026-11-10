# #03 domain-model — outcome (2026-11-10)

## Delivered
The nine PRD §6 entities in their S2 modules, one portable migration (`migrations/Version20261110225500.php`, SQLite and MariaDB branches, `down()` drops all), owned finders, the `Shared` audit/timezone/ownership types, Foundry factories for every entity, the `main` demo story (`make seed`) and integration tests for every unique key, every FK, the teacher cascade, archive/void soft states, every finder, the audit logger and migration up/down. No controller, template, translation or security config.

## Interfaces
- **IDs and time:** every `id` is UUID v7 (`uuid` type: `BLOB` on SQLite, `BINARY(16)` on MariaDB, 16 raw bytes; D1). Datetimes are UTC `datetime_immutable`; `course.local_start_time` TIME, `starts_on`/`ends_on` DATE. Enums stored as VARCHAR(16) strings.
- **Tables** (columns; `uk_` unique, `idx_` index; every FK `ON DELETE CASCADE`; Doctrine also adds an `IDX_…` per FK):
  - `teacher` id, name(120), email(180), password_hash(255), timezone(64), created_at · `uk_teacher_email(email)`
  - `audit_event` id, teacher_id→teacher, actor_type(16), action(64), entity_type(64), entity_id, occurred_at, details_json(JSON) · `idx_audit_event_teacher_occurred(teacher_id, occurred_at)`
  - `student` id, teacher_id→teacher, first_name(80), last_name(80), email(180)?, notes(text)?, created_at, archived_at? · `idx_student_teacher_archived(teacher_id, archived_at)`
  - `course` id, teacher_id→teacher, name(120), description?, location(160)?, timezone(64), weekday, local_start_time, duration_minutes, starts_on, ends_on?, created_at, archived_at? · `idx_course_teacher_archived(teacher_id, archived_at)`
  - `enrollment` id, course_id→course, student_id→student, public_checkin_label(40)?, joined_at, left_at? · `uk_enrollment_course_student(course_id, student_id)`, `idx_enrollment_student`
  - `lesson_session` id, course_id→course, starts_at_utc, ends_at_utc, status, cancelled_at? · `uk_lesson_session_course_starts(course_id, starts_at_utc)`, `idx_lesson_session_starts`
  - `credit_grant` id, enrollment_id→enrollment, quantity, granted_at, note(255)?, voided_at? · `idx_credit_grant_enrollment`
  - `attendance` id, lesson_session_id→lesson_session, student_id→student, status, source, marked_at, updated_at · `uk_attendance_lesson_student(lesson_session_id, student_id)`, `idx_attendance_student_status(student_id, status)`
  - `check_in_window` id, lesson_session_id→lesson_session, token_hash CHAR(64), opened_at, expires_at, closed_at? · `uk_check_in_window_token_hash`, `idx_check_in_window_lesson`
- **Entities** (constructor; intent methods — repeats throw the module's `Invalid*` exception; all have `getId()`, getters, `ownerId()` via `Shared\Security\TeacherOwned`):
  - `Account\Entity\Teacher(name, email, passwordHash, $now, timezone = Timezones::DEFAULT)`: `rename`, `changeEmail`, `changePasswordHash`, `changeTimezone`; `UserInterface` + `PasswordAuthenticatedUserInterface`, `ROLE_TEACHER`, identifier = normalised email (`Teacher::normaliseEmail()`); also `Shared\Security\TeacherAccount`.
  - `Students\Entity\Student(Teacher, firstName, lastName, $now, ?email, ?notes)`: `changeDetails`, `archive($now)`, `restore`.
  - `Courses\Entity\Course(Teacher, name, timezone, weekday, localStartTime, durationMinutes, startsOn, $now, ?endsOn, ?description, ?location)`: `changeDetails`, `changeSchedule`, `archive($now)`.
  - `Courses\Entity\Enrollment(Course, Student, $now, ?publicCheckinLabel)`: `changeLabel`, `leave($now)`, `rejoin`. `Courses\Entity\LessonSession(Course, startsAtUtc, endsAtUtc)`: `complete`, `cancel($now)`; `LessonStatus` scheduled/completed/cancelled.
  - `Credits\Entity\CreditGrant(Enrollment, $now, quantity = 10, ?note)`: `void($now)`.
  - `Attendance\Entity\Attendance(LessonSession, Student, AttendanceStatus, AttendanceSource, $now)`: `change(status, source, $now)`; `AttendanceStatus` present/absent, `AttendanceSource` student_qr/teacher.
  - `CheckIn\Entity\CheckInWindow(LessonSession, tokenHash, openedAt, expiresAt)`: `close($now)`, `isActiveAt($now)`. `Shared\Entity\AuditEvent` is insert-only.
- **Repositories** (`ServiceEntityRepository<T>`): `findOwned(Teacher, Uuid): ?X` on Student, Course, Enrollment, LessonSession, CreditGrant, Attendance, CheckInWindow; `TeacherRepository::findOneByEmail(string)`, `CheckInWindowRepository::findOneByTokenHash(string)`, `LessonSessionRepository::hasAttendance(LessonSession): bool` (DBAL).
- **Services:** `Shared\Audit\AuditLogger::record(Uuid $teacherId, ActorType, string $action, string $entityType, Uuid $entityId, array $details = [])`; `Shared\Time\Timezones::DEFAULT` (`Europe/Zurich`), `isValid()`.
- **Config:** `config/packages/doctrine_resolve_target_entities.yaml` (`TeacherAccount` → `Teacher`).
- **Test support:** factories in `tests/Factory/` for all nine entities; states `StudentFactory::archived()`, `CourseFactory::archived()`, `EnrollmentFactory::left()`, `LessonSessionFactory::completed()/cancelled()`, `CreditGrantFactory::voided()`, `CheckInWindowFactory::closed()`. `tests/Factory/SameTeacher.php` lets Enrollment/Attendance factories fill the missing parent from the given one's teacher. `tests/Integration/DbalRows.php` (trait: `connection()`, `insertRow()`, `countRows()`, `rowIds()`) for raw-row tests.
- **Demo data** (`tests/Story/MainStory.php`): login `demo@example.com` / `tanzhaken-demo` (`TeacherFactory::DEMO_PASSWORD`, a precomputed bcrypt hash; demo data, not a credential). 8 students, 2 courses (Salsa 19:00 Europe/Zurich, Tango), 9 enrollments (1 left), 21 lessons (1 cancelled), 10 grants (1 voided), 51 attendance rows, 1 closed window, 3 audit rows; balances include a "Low" (2) and a "Renewal needed" (−1) case.

## How to use it
- **D3 — deletion:** every FK is `ON DELETE CASCADE`. Nothing in code hard-deletes; only #18 deletes, with one `DELETE FROM teacher`. New tables (#11 `password_reset_request` and later) must cascade from `teacher` too. Everything else is soft state (archive, leave, void, cancel, close).
- **D4 — check-in:** "at most one active window per lesson" is not a DB constraint. #8 enforces it in its transaction (close earlier windows first). Optional add-only design: nullable `active_flag` (1 open / NULL closed) + unique `(lesson_session_id, active_flag)`.
- **Repositories:** entities have no `repositoryClass` (phpat forbids Entity→Repository). Inject the repository class; `EntityManager::getRepository()` returns Doctrine's default one without the custom finders.
- **Raw DBAL and UUIDs** (now part of S3, like the repository rule above): bind UUIDs with type `UuidType::NAME` (`'uuid'`) or as a plain `toBinary()` string; read with `Uuid::fromBinary()`. Never `ParameterType::BINARY`: SQLite then stores a BLOB that never equals the TEXT-class value Doctrine wrote, so FKs and lookups fail.
- **Audit:** call `AuditLogger::record()` inside the writing service's transaction. It is currently removed from the compiled container as unused; the first consumer (#6/#7) makes it reachable, no config change needed.

## Decisions
- P1: `audit_event.teacher_id` FK via `resolve_target_entities` on `Shared\Security\TeacherAccount` (Step 2.2 kept it; fallbacks B listener / C no FK not needed). Undo: drop the yaml and the association, keep a plain `uuid` column.
- P2: migration `isTransactional()` false on MySQL-family platforms, true on SQLite. P3: `LessonSession` times truncated to whole seconds. P4: demo password constant + precomputed hash. P5: constraint tests assert exception class and, for unique keys, the column list.
- Intent methods refuse a repeat (archive, leave, void, cancel, close) so a timestamp is never overwritten; optional strings trimmed, empty → null.
- One exception per module: `InvalidCourse` also covers Enrollment and LessonSession.

## Deviations
- No `repositoryClass:` on entities (phpat `LayersTest`), see How to use it.
- Demo balances: two Salsa enrollments got smaller grants (8 and 5, note "Rest of an earlier pass") instead of 10, so "Low" and "Renewal needed" exist with 6 completed lessons.
- Migration statements are the verbatim `--dump-sql` of each platform, regrouped parent-first; Doctrine's `FK_…`/`IDX_…` names kept so `schema:validate` stays green.
- `AuditLoggerTest` builds `AuditLogger` from the container's `Connection` and `ClockInterface` (service removed while unused).

## Carried forward
- MariaDB branch is the real `--dump-sql` output (10.11), unexecuted until #21.
- `phpstan-doctrine` → #19 (finders check `instanceof` instead). `Enrollment.public_checkin_label` uniqueness per course → #10. `PasswordUpgraderInterface`, firewall, voter → #4. "One active window" → #8.
- Review F-m1: Doctrine keeps its own single-column `IDX_…(teacher_id)` beside the composite `idx_*_teacher_*` indexes (student, course, audit_event), so those FK columns carry a redundant index; harmless at MVP size, drop only together with a mapping change → #21.
- Review F-m2: `AuditLogger` has no consumer yet and is removed from the compiled container; the first writing service (#6/#7) must inject it and its tests fetch it from the container → #6/#7.

## Verification
- `make lint` — pass. `make test` — OK (111 tests, 401 assertions), all four suites; no deprecated DBAL API in our code (`MigrationTest` uses `introspectTableNames()`).
- `make db-fresh && make seed` — exit 0; `bin/console doctrine:schema:validate` — exit 0 (mapping correct, in sync). `doctrine:schema:update --dump-sql` — nothing to update.
- MariaDB 10.11 `doctrine:schema:create --dump-sql` without a server — exit 0 (source of the MariaDB branch).
- Public-repo grep over the lane's diff (session URLs, local paths, model names, personal data): no hits; only `example.com`/`example.org` addresses.
- Review re-run (2026-11-11, after the lane sync): `make lint` pass, `make test` OK (111 tests, 401 assertions); `make db-fresh && make seed` exit 0; `schema:validate` exit 0; `migrate first` leaves only `doctrine_migration_versions`, `migrate` restores all nine; `PRAGMA foreign_key_check` clean on the seeded DB; demo hash verifies `tanzhaken-demo`; lane commit messages carry only the `Co-Authored-By: Claude <noreply@anthropic.com>` trailer.
- No UI in this cycle, so no 390/1440 px check. Pending human: nothing; MariaDB execution is #21's.
