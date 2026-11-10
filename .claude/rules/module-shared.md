---
paths:
  - "src/Shared/**"
  - "tests/**/Shared/**"
---

# Module `Shared`

- **Holds:** clock and timezone helpers, `AuditEvent` + `AuditLogger`, the `TeacherOwned` interface, Twig extensions, the SQLite connection setup (`Doctrine/SqliteConnectionMiddleware`) and the health check.
- **May depend on:** nothing. Every module may depend on `Shared`. Refer to a teacher by ID (`Uuid`), never by the `Teacher` class.
- **Owning cycles:** #0 (SQLite setup, health), #3 (clock, audit, `TeacherOwned`); others add files.
- **Routes (S5):** `health` → `/health` (`Controller/HealthController.php`, JSON `{"status":"ok"}`).
- **Tables (S3):** `audit_event` (#3).
- **Templates:** none.
- **Translation domain:** none.
- **Hotspot slots:** `src/Shared/*`: add files, never change an existing signature.

Namespace `App\Shared\<Layer>`. Cycles append notes for this module below.
