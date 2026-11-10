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
- **Routes (S5):** `student_index` `/students`, `student_new` `/students/new`, `student_show` `/students/{id}`, `student_edit` `/students/{id}/edit` (#9).
- **Tables (S3):** `student` (#3).
- **Templates:** `templates/student/**`.
- **Translation domain:** `students` (`translations/students+intl-icu.en.yaml`).
- **Hotspot slots:** `templates/student/show.html.twig` belongs to #9 and includes, with `ignore missing`, `student/_credits.html.twig` (#12, Credits) and `student/_attendance.html.twig` (#13, Attendance). Those two partials are not Students' files.

Namespace `App\Students\<Layer>`. Placeholder actions in `src/Students/Controller/PlaceholderController.php` are deleted by the cycle that ships the real route. Cycles append notes for this module below.
