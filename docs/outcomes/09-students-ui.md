# #9 students-ui — outcome (2026-11-11)

## Delivered

The teacher's student directory and student page. `/students` lists the teacher's own students (initials avatar, name, active course count) with a name search and an Active/Archived filter and three empty states; a teacher creates and edits a student (first and last name, optional email and notes, teacher-only) on their own pages, archives and restores from the student page. The student page shows the details, the courses the student is on, the earlier courses ("Left on …") linking to `course_show`, and two hotspot slots for #12 (credits) and #13 (attendance). Every write goes through one audited service; foreign or unknown students answer 404 on every route. The Students `PlaceholderController` is gone.

## Interfaces

- **Routes** (`src/Students/Controller/StudentController.php`, all `ROLE_TEACHER`, `{id}` = UUID requirement, foreign/unknown → 404 via `#[IsGranted(TeacherOwnsVoter::ATTRIBUTE, subject: 'student', statusCode: 404)]`):
  `student_index` GET `/students` (`?q=` name search, `?archived=1`) · `student_new` GET|POST `/students/new` · `student_show` GET `/students/{id}` · `student_edit` GET|POST `/students/{id}/edit` · `student_archive` POST `/students/{id}/archive` · `student_restore` POST `/students/{id}/restore`. Success → 303 to `student_show` with a toast; invalid form → 422.
- **CSRF ids:** the form (`Form\StudentType` on `Form\StudentData`) uses the default stateless `submit` id; archive/restore use session ids `student_archive` / `student_restore` (field `_token`, `#[IsCsrfTokenValid]`).
- **`Service\StudentDirectory`** (final): `create(Teacher, StudentData): Student` (throws `InvalidStudent`); `update(Teacher, Uuid, StudentData): Student`, `archive(Teacher, Uuid): Student`, `restore(Teacher, Uuid): Student` throw `Exception\StudentNotFound` (unknown/foreign) or `InvalidStudent` (bad data, already archived, `notArchived()`). Audit `student.created|updated|archived|restored`; `updated` details are `{fields: [...]}`, names only, never values.
- **`ReadModel\StudentList::forTeacher(Teacher, string $search = '', bool $archived = false): list<StudentListItem>`** — one DBAL query; search matches first, last or full name, case-insensitive, `%`/`_` literal. `StudentListItem(Uuid id, string firstName, string lastName, ?string email, ?DateTimeImmutable archivedAt, int activeCourseCount)` + `fullName()`, `initials()`, `isArchived()`.
- **`ReadModel\StudentCourses::forStudent(Teacher, Uuid $studentId): list<StudentCourseRow>`** — teacher-scoped DBAL query on `enrollment`/`course` (no `App\Courses` class, S2), active first. `StudentCourseRow(Uuid courseId, string name, int weekday (ISO 1–7), DateTimeImmutable localStartTime, string timezone, ?string location, bool courseArchived, DateTimeImmutable joinedAt, ?DateTimeImmutable leftAt)` + `isActive()` = not left and course not archived.
- **No tables, migrations, commands, config or env changes.** Translations: `translations/students+intl-icu.en.yaml`; form error keys `students.student.*` in `validators+intl-icu.en.yaml`. CSS: `assets/styles/students/directory.css`.

## How to use it

- **#12 Credits:** create `templates/student/_credits.html.twig`. `show.html.twig` includes it `ignore missing … only` with `{student: App\Students\Entity\Student, courses: list<StudentCourseRow>}` (active courses only). Render your own section heading.
- **#13 Attendance:** create `templates/student/_attendance.html.twig`; it gets `{student}` only, renders its own heading. Both slots follow the course sections. `StudentDirectoryPagesTest` has a test that skips itself once either partial exists.
- **#10 roster picker:** archived students stay enrolled; pickers must exclude them (`student.archived_at IS NULL`). Link to a student with `path('student_show', {id})`.

## Decisions

- **D1** One `_list`/`_list_row` markup at both widths (centred column on desktop), not the E3 table — nothing tabular. Undo: `_table` at ≥ 64rem.
- **D2** Archive without confirmation dialog; Restore undoes it, works without JS. Undo: wrap the form in `_dialog`.
- **D3** Notes limited to 2000 characters (`StudentData::NOTES_MAX`); the column is TEXT.
- **D4** Earlier (left) courses shown with "Left on …" (STU-3 keeps history). Undo: render only `isActive()` rows.
- **D5** New/edit are their own pages (S5 routes), not a C3 side panel (needs JS or a second form). Undo: `_dialog` `variant: 'sheet'` around `_form`.
- **D6** Archive/restore CSRF ids are session-based (not in #0's stateless list). **D7** Update audit carries field names only.
- **OD-1** `PlaceholderRoutesTest`: used #4's `SHIPPED` const — added the six `student_*` names and dropped the four placeholder rows.

## Deviations

- `firstName`/`lastName` have `autocomplete="off"` (not `given-name`/`family-name`): the browser would offer the teacher's own name.
- Course time is `localStartTime|format_time('short', timezone: false)`: `local_start_time` is a TIME column already in the course's wall time, so a zone conversion would shift it.
- Validator messages are keys in the `validators` domain (`students.student.*`), not `students` (D-038, #4): Symfony translates constraint messages only there. An `InvalidStudent` from the service becomes one generic form error `students.student.invalid`.
- **Accepted in review (F-m1):** an archive/restore POST with a wrong `_token` answers a redirect to `/login`, not a 4xx: `InvalidCsrfTokenException` is an `AuthenticationException`, so the firewall entry point handles it. Nothing changes (test asserts no `archived_at`, no audit row). A 403 needs a manual `isCsrfTokenValid()` or an exception listener.
- **Hotspot:** `tests/Functional/PlaceholderRoutesTest.php` edited (OD-1 above).
- `StudentCourses` sorts a row on an archived course with the earlier ones (`isActive()`), not just `left_at IS NULL`.
- Integration tests carry `#[ResetDatabase]` like the other modules' integration tests; "Earlier courses" and "Details" are their own `<section>`s with `h2`; "Add student" icon is `person-add` (sprite id).
- Design: the form is a page with back arrow, left title and "Add student" + "Cancel" side by side, not d1/c3's X close, centred title, full-width button and desktop side panel. A long name wraps beside Edit/Archive at 390 px.

## Carried forward

- Search uses SQLite `LOWER()`, which folds ASCII only ("ö" does not find "Ö") → CTO to assign (a normalised search column or a PHP-side fold).
- `btn--pill` is 40 px; the students filter overrides it to 44 px. Raise the component itself → #2.
- Form layout vs d1/c3 (side panel, close button, full-width primary) → #2 / design owner if the panel is wanted (D5 undo).
- Course rows show "Tuesdays · 7:00 PM": intl filters for `en`, as D-042 settles (no 24 h pattern). The location the design shows ("· Studio 2") is missing, although `StudentCourseRow::$location` holds it (review F-m3) → #20.
- Seed has no student with both an active and a left course (manual check had to edit the dev DB) → owner of `tests/Story/MainStory.php`.
- Archived but still enrolled students: #10 excludes them from the enroll picker; #8/#14 decide check-in listing (recommend: not listed).
- Wrong-CSRF redirect (review F-m1, accepted). The teacher stays logged in, `/login` forwards to `/dashboard` with no message, and nothing changes. Every module that uses `#[IsCsrfTokenValid]` shares this, so it should be handled once: a Shared listener turning `InvalidCsrfTokenException` for a logged-in user into a 303 back with a "page expired" flash → #19 (or a CTO decision now).
- `update()` writes `student.updated` with `fields: []` when nothing changed (review note) → #19 if audit noise matters. No pagination → #20 if a teacher has hundreds.

## Verification

- `make lint` — exit 0 (cs-check, phpstan level 8 + phpat, 42 Twig, 30 YAML, container OK).
- `make test` — exit 0, OK (425 tests, 3871 assertions); covers `tests/Integration/Students`, `tests/Functional/Students` (incl. `StudentIsolationTest`), `PlaceholderRoutesTest`, `TemplatesHaveNoRawTextTest`.
- `make db-fresh && make seed` — OK. `grep -rnE 'style="|<script>' templates/student` — empty. Branch diff grep for session URLs, local paths, model names, personal data — empty.
- Manual (Chrome, demo teacher, port 8004): `/students`, `?archived=1`, `?q=` hit and miss, `/students/new` invalid (422) and valid, student page with active and left course, edit, archive, restore — at 390 and 1440 px. The window would not resize, so each width was rendered in a same-origin iframe of exactly that width: no horizontal scroll, visible focus, 44 px targets after the filter-pill fix (40→44 px in `directory.css`).
- **Pending for a human:** a real phone (Safari, touch, safe areas; owner gate G7) and a run with JavaScript switched off (by markup every action is a plain link, GET form or POST form).
