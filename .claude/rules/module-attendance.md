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
