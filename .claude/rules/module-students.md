---
paths:
  - "src/Students/**"
  - "tests/**/Students/**"
  - "templates/student/**"
  - "translations/students+intl-icu.en.yaml"
---

# Module `Students`

- **Holds:** `Student` and the student directory pages.
- **May depend on:** Account, Shared.
- **Owning cycles:** #3 (`Student`), #9 (pages).
- **Routes (S5):** `student_index` `/students`, `student_new` `/students/new`, `student_show` `/students/{id}`, `student_edit` `/students/{id}/edit`, plus `student_archive` / `student_restore` (POST, see #9 notes) (#9).
- **Tables (S3):** `student` (#3).
- **Templates:** `templates/student/**`.
- **Translation domain:** `students` (`translations/students+intl-icu.en.yaml`).
- **Hotspot slots:** `templates/student/show.html.twig` belongs to #9 and includes, with `ignore missing`, `student/_credits.html.twig` (#12, Credits) and `student/_attendance.html.twig` (#13, Attendance). Those two partials are not Students' files.

Namespace `App\Students\<Layer>`. Placeholder actions in `src/Students/Controller/PlaceholderController.php` are deleted by the cycle that ships the real route. Cycles append notes for this module below.

## #3 notes (domain-model)

- `Entity\Student(Teacher $teacher, string $firstName, string $lastName, \DateTimeImmutable $now, ?string $email = null, ?string $notes = null)`; `changeDetails()`, `archive($now)` (throws when already archived), `restore()`. Email trimmed and lower-cased, empty → null.
- `Repository\StudentRepository::findOwned(Teacher $teacher, Uuid $id): ?Student`. Exception: `Exception\InvalidStudent`.

## #9 notes (students-ui)

- `Controller\StudentController`: `student_index` GET `/students` (`?q=` name search, `?archived=1`), `student_new` GET|POST `/students/new`, `student_show` GET `/students/{id}`, `student_edit` GET|POST `/students/{id}/edit`, `student_archive` POST `/students/{id}/archive`, `student_restore` POST `/students/{id}/restore`. `{id}` is a UUID; foreign/unknown → 404 (voter `statusCode: 404`).
- CSRF ids: `student_archive`, `student_restore` (field `_token`). Success redirects 303 to `student_show`.
- `Service\StudentDirectory`: `create(Teacher, StudentData): Student` (throws `InvalidStudent`); `update(Teacher, Uuid, StudentData)`, `archive(Teacher, Uuid)`, `restore(Teacher, Uuid)`: `Student`, throw `StudentNotFound` (unknown/foreign) or `InvalidStudent` (bad data / already archived / not archived). Audit `student.created|updated|archived|restored`; details carry no values.
- `ReadModel\StudentList::forTeacher(Teacher, string $search = '', bool $archived = false): list<StudentListItem>`; `StudentListItem(id, firstName, lastName, email, archivedAt, activeCourseCount)`, `fullName()`, `initials()`, `isArchived()`.
- `ReadModel\StudentCourses::forStudent(Teacher, Uuid $studentId): list<StudentCourseRow>`; `StudentCourseRow(courseId, name, weekday, localStartTime, timezone, location, courseArchived, joinedAt, leftAt)`, `isActive()` = not left and course not archived.
- Slots in `show.html.twig`: `student/_credits.html.twig` gets `{student, courses: active_courses}` (list of `StudentCourseRow`); `student/_attendance.html.twig` gets `{student}`. Both `ignore missing … only`.
- Archived students stay enrolled; pickers exclude them.
