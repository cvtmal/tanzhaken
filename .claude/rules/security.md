---
paths:
  - "src/**"
  - "templates/**"
  - "config/packages/security.yaml"
---

# Tenancy and security (S4)

- Every private row leads to one `teacher_id`. Repositories take the `Teacher` as a required argument (`findOwned…`).
- A foreign or unknown ID answers **404**, not 403, at the controller and again in the service.
- One voter (`TEACHER_OWNS`) and `#[IsGranted]`; no hand-written ownership check in a template.
- Every state change is a POST with a CSRF token. No state change on GET.
- Sessions: secure, HttpOnly, SameSite=Lax cookies; passwords with the `auto` hasher; login throttling with the built-in limiter.
- Rate limiters live in one config file per concern (e.g. `config/packages/rate_limiter_checkin.yaml`).
- Public responses carry only the teacher's display name, the course name, the lesson time and the check-in labels.
- `config/packages/security.yaml` belongs to #4.
