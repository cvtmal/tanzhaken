---
paths:
  - "src/Dashboard/**"
  - "tests/**/Dashboard/**"
  - "templates/dashboard/**"
  - "translations/dashboard+intl-icu.en.yaml"
---

# Module `Dashboard`

- **Holds:** the dashboard page.
- **May depend on:** every other module.
- **Owning cycles:** #17.
- **Routes (S5):** `dashboard` `/dashboard` (#17).
- **Tables (S3):** none; reads through the other modules' read models.
- **Templates:** `templates/dashboard/**`.
- **Translation domain:** `dashboard` (`translations/dashboard+intl-icu.en.yaml`).
- **Hotspot slots:** none.

Namespace `App\Dashboard\<Layer>`. Placeholder actions in `src/Dashboard/Controller/PlaceholderController.php` are deleted by the cycle that ships the real route. Cycles append notes for this module below.
