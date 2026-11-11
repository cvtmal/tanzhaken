---
paths:
  - "src/Attendance/**"
  - "tests/**/Attendance/**"
  - "templates/lesson/**"
  - "templates/student/_attendance.html.twig"
  - "templates/course/_export_action.html.twig"
  - "translations/attendance+intl-icu.en.yaml"
---

# Module `Attendance`

- **Holds:** `Attendance`, the marking service, the lesson register and the CSV export.
- **May depend on:** Credits, Courses, Students, Account, Shared.
- **Owning cycles:** #3 (`Attendance`), #7 (marking rules), #13 (register pages), #15 (CSV export).
- **Routes (S5):** `lesson_show` `/courses/{id}/lessons/{lessonId}` (#13), `course_export` `/courses/{id}/export.csv` (#15).
- **Tables (S3):** `attendance` (#3, #7 add-only).
- **Templates:** `templates/lesson/**`, `templates/student/_attendance.html.twig`, `templates/course/_export_action.html.twig`.
- **Translation domain:** `attendance` (`translations/attendance+intl-icu.en.yaml`).
- **Hotspot slots:** `templates/lesson/show.html.twig` belongs to #13 and includes `lesson/_checkin_action.html.twig` (#16, CheckIn). Attendance fills `student/_attendance.html.twig` and `course/_export_action.html.twig`.

Namespace `App\Attendance\<Layer>`. Placeholder actions in `src/Attendance/Controller/PlaceholderController.php` are deleted by the cycle that ships the real route. Cycles append notes for this module below.

## #3 notes (domain-model)

- `Entity\Attendance(LessonSession, Student, AttendanceStatus, AttendanceSource, \DateTimeImmutable $now)`; `change(AttendanceStatus, AttendanceSource, $now)` (sets `updated_at`). Enums: `AttendanceStatus` `present|absent`, `AttendanceSource` `student_qr|teacher`. Unique `(lesson_session_id, student_id)`: change the row, never insert a second.
- `Repository\AttendanceRepository::findOwned(Teacher, Uuid): ?Attendance`. Exception: `Exception\InvalidAttendance`.

## #7 notes (attendance-core)

- `Service\AttendanceMarker` (teacher writes): `mark(Teacher, Uuid $lessonId, Uuid $studentId, AttendanceStatus, ?string $reason = null)`, `clear(Teacher, Uuid $lessonId, Uuid $studentId, ?string $reason = null)`. Every refusal is thrown before `wrapInTransaction()` (S2, D-027); the EntityManager stays open after a refusal.
  - `AttendanceNotFound` (unknown or foreign lesson/student) → 404. `InvalidAttendance` (`lessonCancelled`, `studentNotEnrolled`, `notMarked`, `reasonTooLong`) → a refusal message. `changedConcurrently` (unique-key race inside the transaction) → the EntityManager is closed: redirect and let the teacher reload.
  - Same status again is a no-op (no write, no audit; D1). `clear()` is allowed on cancelled lessons and left enrollments (D2). The reason (trimmed, ≤ 255, `REASON_MAX_LENGTH`) lives only in `audit_event.details_json` (D5). Future lessons may be marked (D6; the UI decides).
  - Audit: `attendance.marked` {`lesson_id`, `student_id`, `status`, `previous_status`, `previous_source`, `reason`}, `attendance.cleared` {`lesson_id`, `student_id`, `previous_status`, `previous_source`, `reason`}; actor `teacher`, entity `attendance`, id = the row's.
- `Service\CheckInRecorder::recordCheckIn(Uuid $lessonId, Uuid $studentId): CheckInOutcome` (#8): one autocommit `INSERT … SELECT`, no read before it. Call it outside a transaction or as its first write (SQLite busy handler). `recorded` / `already_present` (any present row, also a teacher's; D4) / `marked_by_teacher` (absent row, untouched). Not eligible (unknown or cancelled lesson, not actively enrolled) → `InvalidAttendance::notEligibleForCheckIn()`. No audit event (D3).
- `Repository\AttendanceRepository`: `findOneForLessonAndStudent(LessonSession, Student)`, `hasActiveEnrollment(Uuid $courseId, Uuid $studentId)` (Attendance's own DBAL read of `enrollment`).
- Read models: `ReadModel\LessonRegister::forLesson(Teacher, Uuid): ?list<LessonRegisterRow>` (null = 404; active enrollments plus anyone with a mark; `status` null = "Not marked"; join balances from `App\Credits\ReadModel\Balances` by `enrollmentId`). `ReadModel\StudentAttendanceHistory::forStudent(Teacher, Uuid): list<StudentAttendanceRow>` (newest first; `[]` for a foreign student, so check `StudentRepository::findOwned` for the 404).
- The services have no consumer yet and are removed from the test container: tests build them by hand (D-026).

## #15 notes (csv-export)

- Route: `course_export` GET `/courses/{id}/export.csv` (`Requirement::UUID`), `Controller\CourseExportController` (invokable). `CourseRepository::findOwned` else 404; archived courses export too. Headers: `text/csv; charset=UTF-8`, attachment `register-<slug>-<Y-m-d>.csv` (today in the course zone), `Cache-Control` with `no-store, private`. Button: `templates/course/_export_action.html.twig` (secondary `_link_button`, `download`, no icon).
- Classes: `Service\CsvEncoder::encode(iterable<list<string|int|null>>): string` (BOM + RFC 4180, comma, CRLF; `[]` = empty line; `SEPARATOR` is the switch for a `;` variant). `ReadModel\CourseRegisterExport::forCourse(Teacher, Uuid, $now): list<CourseRegisterExportRow>` (one query; `[]` for a foreign course). `Service\CourseRegisterCsv::build(Teacher, Course): string` / `filename(Course): string` (register section, empty line, balance summary from `Balances::forCourse`). Catalogue keys under `export.*` in `translations/attendance+intl-icu.en.yaml`.
- D1 lessons: not cancelled, and `starts_at_utc <= now` or already carrying a mark; chronological.
- D2 students per lesson: anyone with a mark, plus active enrolments with `joined_at <= ends_at_utc`; a left student without a mark is not listed.
- D5 neutralisation: string cells starting with `= + - @ \t \r` get a leading `'`; int cells (lessons bought/used/left) are never prefixed, so `-1` stays a number. Pass every user-sourced value as a string.

## #13 notes (attendance-ui)

- `Controller\LessonController` (class docblock lists every template variable): `lesson_show` GET `/courses/{id}/lessons/{lessonId}`; `lesson_mark` POST `/courses/{id}/lessons/{lessonId}/attendance/{studentId}` (buttons `present` / `absent` / `clear`, optional `reason`); `lesson_complete` POST `/courses/{id}/lessons/{lessonId}/complete`. Cancelling posts to #10's `course_lesson_cancel`. Foreign / unknown / malformed id or a lesson of another course → 404; every POST → 303 to `lesson_show` (`#student-<id>` for a mark) with a `success` / `error` flash.
- Forms: `Form\MarkAttendanceType` (+ `MarkAttendanceData`) named `mark_<base58 student id>`; Complete is #10's `ConfirmActionType` named `complete_<base58 lesson id>`; cancel is #10's `CancelLessonType` `cancel_<base58 lesson id>`. Names come from `ConfirmActionType::formName()`.
- `Exception\InvalidAttendance::translationKey()` / `translationParameters()`: every factory carries an `attendance.error.*` key in the `attendance` domain; controllers translate the refusal with them, never `getMessage()`.
- `ReadModel\LessonRegisterEntry`: a `LessonRegisterRow` plus its `?EnrollmentBalance` (`fromRegister($rows, Balances::forCourse())`); `balanceIfPresent()` / `balanceIfAbsent()` move Balances' number by one for the cost line, `displayName()` ("Alex M."), `anchor()` (`student-<id>`).
- Twig function `student_attendance(Teacher, Uuid)` (`Twig\StudentAttendanceExtension`) → `StudentAttendanceHistory::forStudent()`; used by `student/_attendance.html.twig`.
- **#16 slot contract:** `templates/lesson/show.html.twig` includes `lesson/_checkin_action.html.twig` in the page actions, `ignore missing with {course: {id, name, timezone}, lesson: {id, status, startsAtUtc}} only` (ids RFC 4122 strings, `status` the `LessonStatus` value, `startsAtUtc` `\DateTimeImmutable`). Ask #13's owner before changing the shape.
