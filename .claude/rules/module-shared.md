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

## #3 notes (domain-model)

- `Entity\AuditEvent` (`audit_event`): insert-only, getters only. ManyToOne to `Security\TeacherAccount` (interface, `getId(): Uuid`), resolved to `App\Account\Entity\Teacher` in `config/packages/doctrine_resolve_target_entities.yaml`, so the FK cascades from `teacher` without Shared naming `Teacher`.
- `Audit\AuditLogger::record(Uuid $teacherId, ActorType $actor, string $action, string $entityType, Uuid $entityId, array $details = []): void` — one DBAL insert, time from `ClockInterface`; call it inside the writing service's transaction. `Audit\ActorType`: `teacher|student|system`. Unused so far, so the compiled container removes it until its first consumer.
- `Security\TeacherOwned::ownerId(): Uuid` — implemented by every teacher-owned entity; the voter (#4) compares it with the logged-in teacher's id.
- `Time\Timezones::DEFAULT = 'Europe/Zurich'`, `Timezones::isValid(string $tz): bool`.
