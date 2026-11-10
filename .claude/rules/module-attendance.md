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
