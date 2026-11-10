---
paths:
  - "src/CheckIn/**"
  - "tests/**/CheckIn/**"
  - "templates/checkin/**"
  - "templates/lesson/_checkin_action.html.twig"
  - "translations/checkin+intl-icu.en.yaml"
---

# Module `CheckIn`

- **Holds:** `CheckInWindow`, the token service, the public check-in flow and the teacher's QR page.
- **May depend on:** Attendance, Credits, Courses, Students, Account, Shared.
- **Owning cycles:** #3 (`CheckInWindow`), #8 (rules), #14 (public flow), #16 (teacher QR page).
- **Routes (S5):** `checkin_manage` `/lessons/{id}/check-in`, `checkin_status` `/lessons/{id}/check-in/status` (JSON) (#16), `checkin_public` `/c/{token}` (#14).
- **Tables (S3):** `check_in_window` (#3, #8 add-only).
- **Templates:** `templates/checkin/**`, `templates/lesson/_checkin_action.html.twig`.
- **Translation domain:** `checkin` (`translations/checkin+intl-icu.en.yaml`).
- **Hotspot slots:** fills `lesson/_checkin_action.html.twig`. Protocol: `docs/rpi-cycles.md` S8.

Namespace `App\CheckIn\<Layer>`. Placeholder actions in `src/CheckIn/Controller/PlaceholderController.php` are deleted by the cycle that ships the real route. Cycles append notes for this module below.
