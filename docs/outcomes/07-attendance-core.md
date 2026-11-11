# #07 attendance-core — outcome (2026-11-11)

## Delivered
The attendance rules without any page. `AttendanceMarker` lets the teacher mark a student present or absent, or clear a mark, with an optional reason and an audit event. It refuses cancelled lessons and students who are not actively enrolled. `CheckInRecorder` is #8's race-safe insert for a student's scan. Two DBAL read models give a lesson's register and a student's history. Integration tests cover Journey F's rule, every refusal, and 8 concurrent check-ins on a real SQLite file in separate processes. No schema, route, template, config or translation change.

## Interfaces
- **`App\Attendance\Service\AttendanceMarker`** (final), `REASON_MAX_LENGTH = 255`.
  - Constructor: `(EntityManagerInterface, LessonSessionRepository, StudentRepository, AttendanceRepository, AuditLogger, ClockInterface)`.
  - `mark(Teacher $teacher, Uuid $lessonId, Uuid $studentId, AttendanceStatus $status, ?string $reason = null): void`: creates or changes the row with source `teacher`.
  - `clear(Teacher $teacher, Uuid $lessonId, Uuid $studentId, ?string $reason = null): void`: removes the row.
- **`App\Attendance\Service\CheckInRecorder`** (final), constructor `(Connection, ClockInterface)`.
  - `recordCheckIn(Uuid $lessonId, Uuid $studentId): CheckInOutcome`.
  - `CheckInOutcome` is a string enum in the same namespace: `recorded`, `already_present`, `marked_by_teacher`.
- **Exceptions:**
  - `AttendanceNotFound::lesson()` / `::student()`: unknown or foreign id, the controller answers 404.
  - `InvalidAttendance::lessonCancelled()`, `studentNotEnrolled()`, `notMarked()`, `reasonTooLong(int)`, `changedConcurrently(\Throwable)` and `notEligibleForCheckIn()`. `studentOfAnotherTeacher()` is from #3.
  - Their messages are for developers; the pages map each case to their own catalogue.
- **Read models:**
  - `ReadModel\LessonRegister::forLesson(Teacher, Uuid): ?list<LessonRegisterRow>`. It runs two queries: ownership, then the roster. The result is null for a foreign or unknown lesson.
  - `LessonRegisterRow(studentId, ?enrollmentId, firstName, lastName, ?publicCheckinLabel, studentArchived, enrollmentActive, ?status, ?source, ?markedAt)`. Rows are active enrollments plus anyone who has a mark, ordered by last name, then first name. A null `status` means "Not marked".
  - `ReadModel\StudentAttendanceHistory::forStudent(Teacher, Uuid): list<StudentAttendanceRow>`. It runs one query and returns the newest lesson first; a foreign student gives `[]`.
  - `StudentAttendanceRow(lessonId, courseId, courseName, courseTimezone, startsAtUtc, lessonStatus, status, source, markedAt)`.
- **Repository:** `AttendanceRepository::findOneForLessonAndStudent(LessonSession, Student): ?Attendance` and `::hasActiveEnrollment(Uuid $courseId, Uuid $studentId): bool`.
- **Audit actions:** actor `teacher`, entity `attendance`.
  - `attendance.marked`: `lesson_id`, `student_id`, `status`, `previous_status`, `previous_source`, `reason`.
  - `attendance.cleared`: `lesson_id`, `student_id`, `previous_status`, `previous_source`, `reason`.

## How to use it
- **#8 `checkin-core`:** run S8's window checks first, then call `CheckInRecorder::recordCheckIn()` outside a transaction, or as the transaction's first write. Map `marked_by_teacher` to "your teacher has marked this lesson" and `notEligibleForCheckIn` to the public failure message.
- **#13 `attendance-ui`:**
  - Use `LessonRegister::forLesson()` for the lesson page and merge balances from `Balances` by `enrollmentId`. Use `StudentAttendanceHistory` for the student page.
  - POST actions call `AttendanceMarker::mark()` / `clear()` and catch `AttendanceNotFound` as 404 and `InvalidAttendance` as a flash message and redirect.
  - After `changedConcurrently` the EntityManager is closed, so always redirect.
- **#15 `csv-export`:** may call `LessonRegister` once per lesson or write its own export query. The services are hand-built in tests until a consumer wires them (D-026).

## Decisions
- **D1:** marking the status a student already has is a no-op, with no write and no audit row. Undo: drop the early return in `mark()`.
- **D2:** `clear()` also works on cancelled lessons and left enrollments, so S9's "clear the marks, then cancel" stays possible.
- **D3:** a scan writes no audit event; the row's `source` and `marked_at` are the record. #19 may add one.
- **D4:** a scan after a teacher's `present` gives `already_present`. Only `absent` gives `marked_by_teacher`.
- **D5:** the reason (trimmed, empty becomes null, at most 255 characters) is stored only in `audit_event.details_json`. There is no column for it.
- **D6:** lessons in the future may be marked; #13 decides whether the UI offers it.
- **D7:** `AttendanceNotFound` is this module's own exception, per CTO D-026.
- **P1:** `CheckInOutcome` stays in `Service`, because phpat accepted it.
- **P3:** the concurrency child script boots `new Kernel('test', true)` and takes its connection from the `doctrine` registry.

## Deviations
- **D-027 (S2, newer than the plan):** `AttendanceMarker` throws every refusal before `wrapInTransaction()`, including the same-status no-op. The only exception left inside the transaction is the unique-key race, `changedConcurrently`. Every refusal test asserts that the EntityManager is still open.
- **`changedConcurrently(\Throwable $previous)`:** it takes the previous exception so the cause is chained.
- **Placeholder names:** `CheckInRecorder` binds `:marked_at` and `:updated_at` rather than `:now` twice, so the query stays portable to pdo_mysql.
- **Child script:** it validates its arguments inside its `try`, so a bad argument also exits 1 with the message on STDERR.

## Carried forward
- **#13:**
  - map the exceptions to the `attendance` catalogue;
  - decide whether lessons in the future may be marked (D6);
  - show `studentArchived` and `enrollmentActive` on the register.
- **#19:**
  - decide on an audit event for a scan (D3);
  - add query-count assertions for both read models.
- **Untested branch:** in `CheckInRecorder`, the branch "the row vanished after a unique-key violation, so retry once" has no test, because it needs another process to clear the row between two statements (plan P2). Test it in #8 or #23 if a seam appears.
- **CTO:** the services stay hand-built in tests until a consumer exists. A shared rule for test services is the D-026 question.
- **Review F-m1 → #8 or #23:** the concurrency test proves the outcome, not that the 8 scans overlapped: a child that boots later than the 1.5 s barrier runs late. Let the children print start/end times and assert an overlap.
- **#21:** the new SQL (an `INSERT … SELECT` and plain joins) has not been run on MariaDB.

## Verification
- `make lint`: pass. `make test`: OK (145 tests, 781 assertions), all four suites.
- `vendor/bin/phpunit --group concurrency`: 3 of 3 runs green. With one student, 8 processes give 1 `recorded`, 7 `already_present` and 1 row. With 8 students they give 8 `recorded` and 8 rows.
- `make db-fresh && make seed`: exit 0. `doctrine:schema:validate`: exit 0. There is no diff under `config`, `src/Shared`, `src/Courses`, `src/Students`, `migrations`, any `Entity`, `tests/Architecture` or `composer.*`.
- Public-repo grep of the lane diff and the commit messages for session URLs, model names, local paths and personal data: no hits.
- There is no UI in this cycle, so there was no check at 390 or 1440 px, and nothing is pending for a human.
- Review (2026-11-11): `make lint` pass, `make test` OK (145 tests, 781 assertions); `--group concurrency` 5/5 green, then 3/3 with a cold `var/cache/test` and the CPU loaded. D-026 and D-027 checked against the code.
