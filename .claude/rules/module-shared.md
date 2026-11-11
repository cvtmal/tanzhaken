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

## Design system (#2)

- **Icons:** `{{ icon('chevron-right') }}` (decorative) or `{{ icon('close', 'x'|trans) }}` (labelled); id = Material name with `_` → `-`, filled variant `<id>-fill`; only ids in `assets/icons/sprite.svg` (list and how to add one: `assets/icons/README.md`).
- **Components:** `templates/components/_<name>.html.twig`, first line a `{# … #}` doc comment listing every param with type and default; call with `include '…' with {…} only`, slotted ones with `embed '…' with {…} only` and named blocks. A boolean defaulting to true is `x ?? true`, not `x|default(true)`.
- **Scripts (P2):** a layout sets `{% set layout_scripts = [...] %}`, a page `{% set page_scripts = [...] %}`, both at template top level; JS modules `password-toggle`, `dialog`, `toast`, `alert` (dismissible alerts; the teacher layout loads `toast` and `alert`). No inline `<script>`.
- **Flashes (P3):** carry already-translated text; `success` → toast; `error` → alert `danger`, `warning` / `info` → alert of that variant.
- **Styleguide:** `/_styleguide` (dev/test only) shows every component in every state; add each new component there.
- **Raw-text test** (`tests/Integration/Shared/TemplatesHaveNoRawTextTest.php`, no baseline): literal text, `style=` and `<script>` fail; literal words between Twig tags must be expressions (`{% block body_class 'layout-teacher' %}`).
- **Error pages:** `templates/bundles/TwigBundle/Exception/` (`error`, `error403`, `error404`, `error500` extend `_error_page`, public layout, no query, no `app.user`); strings `error.*` in `messages`.
