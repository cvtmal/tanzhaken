# TanzHaken: RPI cycles

_Last updated: 2026-11-10 (plan created; nothing built yet) · Source of scope: `PRD_TanzHaken_MVP.md` (v1.0) · Design source: `docs/design/` (imported by #1) · Who runs it: `docs/harness/CTO.md`_

We are building TanzHaken (working name): a mobile-first Symfony app with which a dance teacher replaces the printed attendance sheet. The teacher keeps courses, students and 10-lesson passes; after a class the teacher opens a QR check-in, students scan it, pick their name and confirm. The PRD is the scope. This file turns it into `/rpi` cycles and sets the order.

One cycle = one slug = one pass through Research → Plan → Implement → Review. A cycle ends with `make lint` and `make test` green and exactly one commit on `main`. Cycles are grouped in **waves**: cycles in the same wave depend only on earlier waves, so they run in parallel, each in its own **lane** (git worktree + port).

**Reading guide (context budget).** A session working on a cycle reads: this file from the top down to the end of **Settled decisions**, then the rows of **Requirements** for the IDs in its cycle's overview row (each row names the PRD section to read), **Reference material**, its own cycle section, and the outcome files `docs/outcomes/<nn>-<slug>.md` of the cycles in its "Depends on" column. Nothing else from this file, and no PRD section beyond those the requirement rows name.

## How to run a cycle

The CTO session assigns cycles to teammates (`docs/harness/CTO.md`); a teammate follows `docs/harness/TEAMMATE.md`. The RPI commands come from the `rpi` plugin.

1. `/rpi-research <slug>`: the task description is the cycle's section below plus **Settled decisions**. Cycles marked **Plan** in the overview start at `/rpi-plan <slug>`.
2. Then `/rpi-plan <slug>` → `/rpi-implement <slug>` → `/rpi-review <slug>`. RPI asks for `/clear` between phases. A teammate cannot clear itself, so **a phase boundary is a session boundary**: the CTO ends the teammate and starts a fresh one for the next phase. The artifact on disk is the only handoff.
3. RPI's "the human reviews before `/clear`" checkpoints go to the CTO, never to the owner. The teammate ends each phase with the short report from `TEAMMATE.md`; the CTO accepts it or sends it back.
4. If the review says `Needs Rework`, the cycle re-enters the phase the review names. A cycle counts as done only when its review says `Complete` and its commit is on `main`.
5. The implementation session writes `docs/outcomes/<nn>-<slug>.md`, and the review session corrects it before it lands the cycle, so the file is part of the cycle's commit: routes, class and table names, commands, deviations, decisions taken, and what is carried forward. It is the handoff to later cycles, so write it for a reader who has not seen the code. Keep it under 80 lines.
6. The CTO then sets the cycle's row in the overview to `✅ Done`. Only the CTO edits this file.

Artifacts land in `.rpi-tracking/{research,plans,changes,reviews}/<date>/<slug>-*.md` inside the lane's worktree (git-ignored). `bin/harness/lane close` copies them to the main checkout before it removes the worktree.

## Important

- **Never ask the owner to decide.** Apply your own recommendation, record it (research artifact, then the outcome file) and move on. The only exceptions are the **Owner gates** below, and each of those has a default.
- **One commit per cycle**, made by `bin/harness/land`. The subject is `<slug>: <what it delivers>`. Rework after landing goes in a new commit on top. Commit rules: `CLAUDE.md` § Git.
- **The app stays runnable.** After every cycle, `make init && make serve` works on a clean checkout, and from #3 on `make db-fresh && make seed` loads demo data (one teacher, two courses, students, credits, attendance).
- **Tests are part of the cycle**, not of a later one. Every rule a cycle implements has a test in it. #23 only adds the end-to-end journeys.
- **UI cycles compare with the design.** From #2 on, every cycle that renders a page checks it against `docs/design/` at 390 px and 1440 px with the browser tools, on its lane's port, and says in its outcome file what differs and why.
- **The repository is public. No secrets and nothing private in any committed file:** no credentials, no real personal data, no names or paths of the owner's other work, no machine-specific detail. `.env` holds defaults only; real values go to `.env.local`. Demo data uses invented names.
- **Scope is the PRD.** PRD §9 lists what is out. A cycle that finds a gap writes it under "Carried forward" in its outcome file; the CTO decides whether it becomes a cycle.

## Running cycles in parallel

1. **One lane per cycle.** `bin/harness/lane open <slug>` creates the worktree `.claude/worktrees/<slug>` on branch `<slug>` from `origin/main`, installs vendors and assigns a lane letter and port. A session enters it with `EnterWorktree` and its `path`. Two cycles never share a working tree, and nobody builds in the main checkout.
2. **Ports.** Each lane has its own SQLite files under its own `var/`, so lanes share nothing but the port range:

   | Lane | Port (`make serve`) |
   |---|---|
   | main checkout (CTO, read-only use) | 8000 |
   | A | 8001 |
   | B | 8002 |
   | C | 8003 |
   | D | 8004 |

3. **Finishing.** After the review is `Complete`: `bin/harness/land -m "<slug>: <subject>"` in the lane. It rebases onto `origin/main`, squashes to one commit, runs `make lint` and `make test`, and pushes to `main`; if the push is rejected it rebases, tests and pushes again. Then the CTO runs `bin/harness/lane close <slug>`.
4. **Migrations.** Name each migration `VersionYYYYMMDDHHMMSS` from the wall clock at the moment it is written. #3 creates every table except `password_reset_request` (#11). Later cycles only add columns or indexes, and only to the tables the overview's **Schema** column gives them. Two cycles in one wave never alter the same table.
5. **Hotspot files.** Some files are owned by one cycle. Later cycles fill the slots it left and never restructure it:

   | File | Owner | Others may |
   |---|---|---|
   | `composer.json`, `composer.lock`, `Makefile`, `phpstan*.neon`, `phpunit.dist.xml`, `.github/workflows/*` | #0 | add a dependency with a one-line reason in the outcome file; on a lock conflict take `main`'s lock and run `composer require` again; #21 may add its MariaDB job to the CI workflow |
   | `tests/Architecture/*` (module table, S2) | #0 declares **all** modules up front | nothing; a needed change goes to the CTO as a design change |
   | `src/*/Controller/PlaceholderController.php` (S5) | #0 | delete the placeholder action when shipping the real route, in your own module only |
   | `src/Shared/*` | #0, #3 | add files; never change an existing signature |
   | `config/packages/*.yaml` | the cycle that creates the file | nothing; a new concern gets a new file (e.g. `rate_limiter_checkin.yaml`) |
   | `config/packages/security.yaml` | #4 | nothing; guard with `#[IsGranted]` and the S4 voter |
   | `templates/base.html.twig`, `templates/layout/*`, `templates/components/*`, `assets/styles/tokens.css` | #2 (takes them over from #0's stubs) | fill blocks, use components; a missing variant goes into the cycle's own module folder first and into its outcome file |
   | `assets/styles/app.css` (import list) | #2 | append one line for their own file |
   | `translations/messages+intl-icu.en.yaml` (layout strings) | #2 | nothing; each module has its own domain file |
   | `templates/student/show.html.twig` | #9 | #12 creates `student/_credits.html.twig`, #13 creates `student/_attendance.html.twig` (both `ignore missing` includes) |
   | `templates/course/show.html.twig`, `course/_roster_row.html.twig` | #10 | #12 creates `course/_roster_balance.html.twig`, #15 `course/_export_action.html.twig` |
   | `templates/lesson/show.html.twig` | #13 | #16 creates `lesson/_checkin_action.html.twig` |
   | `templates/account/show.html.twig` | #11 | #18 creates `account/_delete.html.twig` |
   | `.env`, `.env.example` | #0 | append their own variables with safe defaults and a one-line comment (#2 adds `APP_BRAND_NAME`) |
| `templates/placeholder.html.twig`, the placeholder routes test | #0 | #2 puts the placeholder into the layouts and adds `_styleguide` to the expected routes; nobody else |
| `CLAUDE.md`, `.claude/rules/module-<name>.md` | #0 | append to their own module's rule file; `CLAUDE.md` keeps one pointer per module |
   | `docs/rpi-cycles.md`, `docs/harness/*` | CTO | nothing |

6. **Concurrency.** Four lanes at most. The rebase step and the CTO's attention are the bottleneck beyond that.

## Overview and order of execution

Size is a guess for budgeting sessions: S under 60k tokens for the whole cycle's biggest phase, M under 110k, L may need a mid-phase handoff.

| # | Slug | Wave | Requirements | Depends on | Schema | Gate | Start at | Size | Status |
|---|---|---|---|---|---|---|---|---|---|
| 0 | `project-skeleton` | W0 | TEC-1–TEC-4, TEC-6, OPS-3, OPS-4 | — | — | G1 | Research | L | ✅ Done |
| 1 | `design-reference` | W0 | UI-1–UI-4 (source) | — | — | G2 | Research | M | ✅ Done |
| 2 | `design-system` | W1 | UI-1–UI-5, A11Y-1 (base) | 0, 1 | — | — | Research | L | Open |
| 3 | `domain-model` | W1 | PRD §6, TEC-3, PERF-1 (indexes) | 0 | creates all nine tables | — | Research | L | ✅ Done |
| 4 | `teacher-auth` | W2 | ACC-1, ACC-2, ACC-5, SEC-1, SEC-2 (guard) | 2, 3 | `teacher` (add-only) | — | Research | M | Open |
| 5 | `scheduling` | W2 | CRS-2–CRS-7, J-H | 3 | `course`, `lesson_session` (add-only) | — | Research | M | ✅ Done |
| 6 | `credit-ledger` | W2 | CRD-1–CRD-6, J-E (math), PERF-1 | 3 | `credit_grant` (add-only) | — | Research | M | ✅ Done |
| 7 | `attendance-core` | W2 | REG-2, REG-4, J-F (rule), PERF-2 | 3 | `attendance` (add-only) | — | Research | M | Open |
| 8 | `checkin-core` | W3 | QR-1, QR-4, SQR-1–SQR-8, J-C, J-D (rules) | 5, 7 | `check_in_window` (add-only) | — | Research | M | Open |
| 9 | `students-ui` | W3 | STU-1, STU-2 | 2, 4 | — | — | Plan | M | Open |
| 10 | `courses-ui` | W3 | CRS-1, CRS-3–CRS-5, STU-3 | 2, 4, 5 | — | — | Research | L | Open |
| 11 | `account-and-reset` | W3 | ACC-3, ACC-4 | 2, 4 | new `password_reset_request` (the outcome file states the final name for #18 and #21) | G6 | Research | M | Open |
| 12 | `credits-ui` | W4 | CRD-1, CRD-4, CRD-5, REG-5, STU-4 (credits), J-A | 6, 9, 10 | — | — | Plan | M | Open |
| 13 | `attendance-ui` | W4 | REG-1–REG-4, STU-4 (attendance), J-E, J-F | 6, 7, 9, 10 | — | — | Research | M | Open |
| 14 | `checkin-public` | W4 | QR-2–QR-5, QR-7, SQR-1–SQR-8, UI-4, J-B–J-D | 2, 6, 8 | — | — | Research | M | Open |
| 15 | `csv-export` | W4 | REG-6 | 6, 7, 10 | — | — | Plan | S | Open |
| 16 | `checkin-teacher-ui` | W5 | QR-1, QR-6, TEC-5 | 8, 13 | — | — | Research | M | Open |
| 17 | `dashboard` | W5 | UI-2 | 4, 5, 6 | — | — | Plan | S | Open |
| 18 | `data-protection` | W5 | PRIV-1, ACC-6 | 11 | — | G5 | Research | M | Open |
| 19 | `security-hardening` | W6 | SEC-1–SEC-4, TEC-7, J-G | 14, 16, 18 | — | — | Research | M | Open |
| 20 | `a11y-responsive-polish` | W6 | A11Y-1, UI-1–UI-4, J-I | 12–18 | — | G7 | Research | L | Open |
| 21 | `db-portability` | W6 | TEC-3, DOD (MariaDB) | 16, 18 | fixes only | G8 | Research | M | Open |
| 22 | `deploy-docs` | W6 | OPS-1–OPS-4, DOD (README) | 14, 16, 18 | — | G3, G4, G6 | Plan | S | Open |
| 23 | `release-verification` | W7 | J-A–J-I, DOD | 19, 20, 21, 22 | — | G7 | Research | M | Open |

### Waves at a glance

```
W0  #0 project-skeleton  ‖  #1 design-reference              (#1 writes docs only)
W1  #2 design-system  ‖  #3 domain-model
W2  #5 scheduling  ‖  #6 credit-ledger  ‖  #7 attendance-core   (need only #3; #2 may still be running)
    #4 teacher-auth                                           (needs #2 and #3)
W3  #8 checkin-core  ‖  #9 students-ui  ‖  #10 courses-ui  ‖  #11 account-and-reset
W4  #12 credits-ui  ‖  #13 attendance-ui  ‖  #14 checkin-public  ‖  #15 csv-export
W5  #16 checkin-teacher-ui  ‖  #17 dashboard  ‖  #18 data-protection
W6  #19 security-hardening  ‖  #20 a11y-responsive-polish  ‖  #21 db-portability  ‖  #22 deploy-docs
W7  #23 release-verification
```

**Lanes by kind of work.** Backend lanes (#3, #5–#8, #21) touch `src/*/{Entity,Repository,Service,ReadModel}`, `migrations/` and tests, and no template. UI lanes (#2, #9–#18, #20) touch controllers, forms, templates, CSS and JS. That split is why W2's backend cycles can run while #2 still builds the design system.

**Critical path:** #1 → #2 → #4 → #10 → #13 → #16 → #19 → #23, the UI path. The path through #0 and #3 into #4 is just as long, so W0 and W1 have no slack on either side; the pure backend path (#0 → #3 → #7 → #8 → #14 → #19 → #23) is one shorter. Never let a UI lane sit idle. Only #20 and #23 wait for #15 or #17, and only #23 waits for #20, #21 or #22.

**Milestones.**
- After #3: `make seed` loads a full demo data set.
- After #4 and #10: a teacher can register, log in and create a course with generated lessons. First look for the owner.
- After #12: Journey A works end to end.
- After #14 and #16: Journeys B, C and D work: the primary feature is usable on a phone.
- After #13: Journeys E and F work.
- After W5: feature-complete.
- After #23: the Definition of done in PRD §10 is met, except the owner's part of G7.

**What can move.** #4's research and plan can start while #2 is still running; only its implementation needs #2's layout. #17 can run any time after #4, #5 and #6 (links to later pages go through S5's placeholders). #18 can run any time after #11. Only #18 waits for #11 directly, but #19–#22 wait for #18. #15 is small and fits a lane that is short of work.

**Cut line.** If the owner wants it sooner: #15 and #21 can land after a first release. #20 and #23 then run without them, and #22 writes G8's default sentence into the README, since #21 is not there to own it. The minimum is #0–#14, #16–#20, #22 and #23.

## Owner gates

Every gate has a default, so no cycle is blocked as a whole. The CTO records answers here and in `docs/harness/DECISIONS.md`.

| ID | Question | Default until answered | Affects |
|---|---|---|---|
| ~~G1~~ | Where does the repository live? | **Answered by the owner, 2026-11-10:** the repository is the public `cvtmal/tanzhaken` on GitHub, set up by the owner (public for unlimited Actions minutes); the CTO pushes `main` there and creates nothing. | #0 |
| G2 | Can the session read the Claude Design project (`docs/design/HANDOFF.md`)? It needs the owner's one-time `/design-login`. | Backend lanes start regardless. #1 waits for access until #3 has landed. If there is still no access, #1 writes the reference from PRD §4.7 alone, #2 builds a restrained system from it, and a later cycle `design-alignment` re-skins. | #1, #2 |
| G3 | Final product name and domain? `tanzhaken.ch` is not confirmed. | "TanzHaken" from one parameter (`APP_BRAND_NAME`). No domain is written into code or templates. | #2, #22 |
| G4 | Which host runs production, and does the CTO deploy? | No deployment. #22 documents a shared PHP host with SQLite (the owner uses cyon; see the `cyon-php-app` skill) and rehearses a production-mode run locally. | #22, #23 |
| G5 | Who is the data controller on the privacy page (name, address, contact)? | Values from `APP_OPERATOR_NAME`, `APP_OPERATOR_ADDRESS`, `APP_OPERATOR_EMAIL`, shown as clearly marked placeholders outside production. | #18 |
| G6 | Which SMTP account sends the reset mails in production? | `MAILER_DSN` from the environment. Development and tests send nothing real. | #11, #22 |
| G7 | Manual smoke test on a real iPhone (Safari) and Android phone (Chrome). | The CTO tests in desktop Chrome at 390 px and 1440 px and in Firefox if installed. The device test is listed for the owner in the release report. | #20, #23 |
| G8 | May the README advertise MariaDB/MySQL support? | Only if #21 ran migrations and the whole suite green against MariaDB in Docker. Otherwise the README says "SQLite supported, MariaDB untested". | #21, #22 |

## Settled decisions

Settled now so that parallel cycles do not each decide them differently. A cycle that finds strong evidence against one records it in its research and continues on the settled path. A settled decision changes only by a CTO edit to this file.

### S1. Stack

- Symfony 7.4 LTS on PHP 8.4 (`"php": ">=8.4"`), Doctrine ORM 3 and DBAL 4 with migrations, Twig, Symfony Security, Form, Validator, Mailer, RateLimiter, Translation, Clock and Uid, AssetMapper.
- SQLite for development, tests and the default production setup. `DATABASE_URL` switches to MariaDB/MySQL without a change to domain code (#21 proves it). No PostgreSQL.
- Local server: the Symfony CLI (`make serve`). No Docker, Redis, queue worker, cron job or CDN is needed to run the app. Docker is used only by #21, as a tool.
- **No Node, at build time or at runtime.** No JS framework, no CSS preprocessor, no UI kit.
- Tooling: PHPUnit, Foundry (dev and test), PHPStan level 8 with phpat, PHP-CS-Fixer (`@Symfony`, `@Symfony:risky`). `make lint` = cs-check + phpstan + `lint:twig` + `lint:yaml` + `lint:container`. `make test` runs every suite on SQLite.

### S2. Architecture

Conventional Symfony, module-first: `src/<Module>/<Layer>/`, namespace `App\<Module>\<Layer>`. No command or query bus, no event sourcing, no plugin system (PRD §7).

| Module | Holds | May depend on |
|---|---|---|
| `Shared` | clock and timezone helpers, `AuditEvent` + `AuditLogger`, the `TeacherOwned` interface, Twig extensions, SQLite connection setup, health | — |
| `Account` | `Teacher`, registration, login, the `TEACHER_OWNS` voter, password reset, settings, account deletion | Shared |
| `Students` | `Student`, directory pages | Account |
| `Courses` | `Course`, `Enrollment`, `LessonSession`, schedule generation, lesson lifecycle, course pages | Students, Account |
| `Credits` | `CreditGrant`, balance read model, renewal state | Courses, Students, Account |
| `Attendance` | `Attendance`, marking service, lesson register, CSV export | Credits, Courses, Students, Account |
| `CheckIn` | `CheckInWindow`, token service, public flow, teacher QR page | Attendance, Credits, Courses, Students, Account |
| `Dashboard` | dashboard page | all of the above |

- **`Shared` is open to every module and depends on none.** It refers to a teacher by ID (`Uuid`), never by the `Teacher` class. A module that needs a fact owned by a module it may not depend on reads it with a DBAL query of its own: `Courses` asks whether a lesson has attendance rows without using a class of `Attendance`.
- **Layers:** `Controller`, `Entity`, `Repository`, `Service`, `ReadModel`, `Form`, `Security`, `Twig`, `Console`, `Exception`.
- **Writes:** controller → one `final` service method that validates, checks ownership, changes entities inside a transaction and writes the audit event. Every domain refusal (validation, ownership, state) is thrown **before** `wrapInTransaction()`: Doctrine ORM 3 closes the EntityManager on any exception inside it, which breaks a form re-render in the same request (from #6). **Reads:** lists and tables come from a `ReadModel` class with one DBAL query that returns `final readonly` view objects, so no page has an N+1.
- **Balance** has one implementation, `App\Credits\ReadModel\Balances` (#6). Every page and export asks it; nobody recomputes the formula.
- **Enforcement:** phpat rules for the module table and the layers, inside `make lint`. Test suites: `Unit`, `Integration` (kernel + SQLite), `Functional` (`WebTestCase`), `Architecture`.

### S3. Data

- Tables and columns follow PRD §6 with singular snake_case table names: `teacher`, `student`, `course`, `enrollment`, `lesson_session`, `credit_grant`, `attendance`, `check_in_window`, `audit_event`.
- **IDs are UUIDv7** (`symfony/uid`), created in the constructor, Doctrine type `uuid`. #3 verifies how SQLite and MariaDB store them and records it.
- **Time:** every timestamp is UTC `datetime_immutable`. A course keeps its IANA `timezone`, `weekday`, `local_start_time` and `duration_minutes`; lesson times are derived from those in the course's zone. Code reads the time from `ClockInterface`, never from `new \DateTimeImmutable()`.
- **Nothing is hard-deleted** except by account deletion (#18): students, courses and enrollments are archived (`archived_at`, `left_at`), credit grants are voided (`voided_at`), lessons are cancelled.
- **Constraints in the database:** every relation has a foreign key; unique `teacher.email` (normalised to lower case), `(course_id, student_id)` on `enrollment`, `(course_id, starts_at_utc)` on `lesson_session`, `(lesson_session_id, student_id)` on `attendance`, `token_hash` on `check_in_window`.
- **The balance is never stored.** It is credits of non-voided grants minus `present` attendances at non-cancelled lessons of that enrollment's course (PRD §4.4).
- **Migrations** run unchanged on SQLite and MariaDB. Where SQL must differ, the migration branches on the platform. Every migration has a `down()`.
- **Repositories and UUIDs in SQL** (from #3): entities declare no `repositoryClass` (phpat forbids Entity → Repository); services inject repositories by class. Raw DBAL binds a UUID with type `'uuid'` (or its string form), never `ParameterType::BINARY`.
- **SQLite:** `foreign_keys=ON`, a `busy_timeout`, and WAL where the file system supports it, set on connect by #0.

### S4. Tenancy and security

- Every private row leads to one `teacher_id`. Repositories take the `Teacher` as a required argument (`findOwned…`). A foreign or unknown ID answers **404**, not 403, at the controller and again in the service.
- One voter (`TEACHER_OWNS`) and `#[IsGranted]`; no ownership check is written by hand in a template.
- Every state change is a POST with a CSRF token. No state changes on GET.
- Sessions: secure, HttpOnly, SameSite=Lax cookies; passwords with Symfony's `auto` hasher; login throttling with the built-in limiter.
- Rate limiters live in one config file per concern.
- Public responses carry only the teacher's display name, the course name, the lesson time and the check-in labels (PRD §4.5).

### S5. URLs and route names

#0 registers every route below as a placeholder action (one `PlaceholderController` per module), so navigation and cross-links work from the first day. A cycle deletes a placeholder when it ships the real route.

| Route name | Path | Owner |
|---|---|---|
| `home` | `/` (to `dashboard` when logged in, else `login`) | #4 |
| `register`, `login`, `logout` | `/register`, `/login`, `/logout` | #4 |
| `password_forgot`, `password_reset` | `/forgot-password`, `/reset-password/{token}` | #11 |
| `account` | `/account` | #11 |
| `dashboard` | `/dashboard` | #17 |
| `student_index`, `student_new`, `student_show`, `student_edit` | `/students`, `/students/new`, `/students/{id}`, `/students/{id}/edit` | #9 |
| `course_index`, `course_new`, `course_show`, `course_edit` | `/courses`, `/courses/new`, `/courses/{id}`, `/courses/{id}/edit` | #10 |
| `course_export` | `/courses/{id}/export.csv` | #15 |
| `lesson_show` | `/courses/{id}/lessons/{lessonId}` | #13 |
| `checkin_manage`, `checkin_status` | `/lessons/{id}/check-in`, `/lessons/{id}/check-in/status` (JSON) | #16 |
| `checkin_public` | `/c/{token}` | #14 |
| `privacy` | `/privacy` | #18 |
| `health`, `_styleguide` | `/health`, `/_styleguide` (dev and test only) | #0, #2 |

POST sub-routes (archive, enroll, cancel, grant, void, mark, open, close) are the owner cycle's choice under its own prefix.

### S6. Frontend

- Server-rendered Twig. Every page works without JavaScript except drawing nothing new: the QR code is an SVG rendered on the server, and polling only enhances.
- CSS: plain CSS with custom properties as design tokens (`assets/styles/tokens.css`), one file per component, served by AssetMapper. No inline styles and no inline scripts, so a strict CSP is possible (#19).
- JS: small vanilla ES modules, one per behaviour, loaded only by the pages that need them.
- Components are Twig partials in `templates/components/`. Three layouts: `layout/teacher.html.twig` (navigation Dashboard / Courses / Students / Account), `layout/auth.html.twig`, `layout/public.html.twig` (check-in).
- Touch targets at least 44 px, no hover-only action, visible focus, `prefers-reduced-motion` respected.
- The look comes from `docs/design/` (#1). Where the design and the PRD disagree on behaviour, the PRD wins and the difference is noted.

### S7. Language and time on screen

- English only, `default_locale: en`. ICU catalogues, one translation domain per module, `messages` for layout strings. No user-facing string in Twig or PHP outside a catalogue, so German is a new file later.
- Dates and times are printed with the intl Twig filters in the course's timezone for lessons and the teacher's timezone elsewhere, and the zone is shown where it could be unclear.

### S8. Check-in protocol

- Token: 32 random bytes, base64url in the URL `/c/{token}`. Only its SHA-256 hash is stored. The QR code holds that HTTPS URL and nothing else.
- Opening a check-in creates a window of 60 minutes, sets the lesson to `completed` and closes any earlier window of that lesson. Reopening always rotates the token. At most one active window per lesson.
- Each public request checks, in this order: token format, window found by hash, not closed, not expired, lesson not cancelled, student actively enrolled in the lesson's course. Any failure shows one helpful public message and changes nothing.
- Recording is one DBAL insert; a unique-key violation on `(lesson_session_id, student_id)` means "Already checked in". No read-then-insert. A teacher's `absent` mark is not overwritten by a later scan; the page says the teacher has marked the lesson.
- Limits by IP and by token. Public pages send `noindex`, `Referrer-Policy: no-referrer` and load no third-party resource.
- The teacher's QR page polls `checkin_status` no more often than every 5 seconds. No WebSockets.

### S9. Credits and attendance policy

- A grant has a positive whole quantity, default 10. A correction is a void plus a new grant; both are audit events.
- States on screen: balance ≤ 0 "Renewal needed" (negative shows "N lessons outstanding"), 1–2 "Low", otherwise neutral.
- `absent` never consumes a credit. Clearing a mark removes the row and writes an audit event with the optional reason.
- Cancelling a lesson that has attendance is refused until the teacher clears those marks (PRD §6).
- Check-in is allowed at zero and negative balance.

### S10. Done for every cycle

`make lint` and `make test` green in the lane after the rebase; new behaviour covered by tests; pages checked at 390 and 1440 px; the outcome file written; no TODO left without an entry under "Carried forward".

## Requirements

IDs point into the PRD, which holds the wording.

| ID | PRD | Requirement |
|---|---|---|
| ACC-1 | §4.1 | Self-service registration: name, email, password |
| ACC-2 | §4.1, §8 | Login, logout, login rate limit |
| ACC-3 | §4.1 | Forgot/reset password by emailed single-use, expiring link |
| ACC-4 | §4.1 | Settings: display name, password, timezone (default `Europe/Zurich`) |
| ACC-5 | §4.1 | Every teacher screen needs a session |
| ACC-6 | §6 | Account deletion with an explicit data-deletion path |
| STU-1 | §4.2 | Create, edit, archive a student; optional email and notes for the teacher only |
| STU-2 | §4.2 | A student belongs to one teacher and can be in several of that teacher's courses |
| STU-3 | §4.2 | Enroll and remove; removal keeps attendance and credit history |
| STU-4 | §4.6 | Student view: sessions attended or absent, credits added, balance per course |
| CRS-1 | §4.3 | Create, edit, archive a course with its weekly template |
| CRS-2 | §4.3 | Generate lessons for 26 weeks by default, or until the end date |
| CRS-3 | §4.3 | "Extend schedule" action; no background job |
| CRS-4 | §4.3 | Add, cancel, re-time a single lesson without touching the series |
| CRS-5 | §4.3 | Chronological lesson list with `scheduled`, `completed`, `cancelled`; a cancelled lesson takes no check-in |
| CRS-6 | §4.3 | UTC storage, generation in the course's IANA zone, correct across DST |
| CRS-7 | §4.3 | A lesson is completed explicitly or by opening check-in; nobody is checked in silently |
| CRD-1 | §4.4 | Credit entry per enrollment: positive quantity (default 10), effective date, note |
| CRD-2 | §4.4 | Balance = credits − `present` attendances at non-cancelled lessons |
| CRD-3 | §4.4 | No stored counter, no allocation to passes, no expiry |
| CRD-4 | §4.4 | Check-in at zero or below; "Renewal needed" and outstanding lessons shown |
| CRD-5 | §4.4 | Correct or void an entry; auditable |
| CRD-6 | §4.4 | Balances are per enrollment; nothing is shared between courses |
| QR-1 | §4.5 (1–2) | Open check-in: token, 60 minutes, large QR, copyable URL, close early, reopen rotates |
| QR-2 | §4.5 (3) | Public page shows teacher name, class name, lesson date and time |
| QR-3 | §4.5 (4) | Roster as first name + last initial, or the enrollment's label; select, then confirm |
| QR-4 | §4.5 (5) | Server checks lesson, token, window, enrollment; `present` exactly once |
| QR-5 | §4.5 (6) | Success screen with that student's remaining lessons; repeat shows "Already checked in" |
| QR-6 | §4.5 (7) | Teacher's roster updates on refresh, with optional polling |
| QR-7 | §4.5 | Trust model: no contact data, notes, other balances or pass history on the public page |
| SQR-1–SQR-8 | §4.5 list | Opaque token only; ≥128 bits (we use 256), hashed at rest; expiry and revocation on every request, rotation invalidates; rate limits and request integrity; `noindex`, no trackers; one token = one lesson and teacher; unique constraint against duplicates; no reuse after close |
| REG-1 | §4.6 | Lesson detail: roster, `Not marked` / `Present` / `Absent`, current balance |
| REG-2 | §4.6 | Set present or absent by hand, remove a mark, optional correction reason |
| REG-3 | §4.6 | Student view of sessions and credits (with STU-4) |
| REG-4 | §4.6 | Balances reflect changes at once; `Absent` consumes nothing |
| REG-5 | §4.6 | Course view highlights balance ≤ 0, second state at 1–2 |
| REG-6 | §4.6 | CSV export of a course register; UTF-8 with BOM; safe against formula injection |
| UI-1 | §4.7 | Mobile first: 44 px targets, short forms, contrast, no hover-only action |
| UI-2 | §4.7 | Navigation Dashboard / Courses / Students / Account; dashboard with today's and upcoming lessons, "Open check-in", renewal alerts |
| UI-3 | §4.7 | Desktop: centred content, course cards, readable attendance table, large QR view |
| UI-4 | §4.7 | Check-in page: fast, uncluttered, one-handed, no install, no account |
| UI-5 | §4.7 | English UI through translation catalogues; localised dates; clear timezone |
| TEC-1–TEC-7 | §7 Backend, Frontend | Symfony 7.4 and PHP ≥ 8.2; Twig and vanilla JS; Doctrine with portable migrations; built-in components over third-party; QR as server-side SVG or a local library; conventional structure; ownership checks on nested resources |
| OPS-1–OPS-4 | §7 Hosting | One cheap PHP host with HTTPS and SMTP; SQLite outside the web root with backup; `.env.example` and setup documentation; nothing beyond PHP needed |
| PERF-1 | §7 Performance | No N+1 on rosters and balances; indexes |
| PERF-2 | §7 Performance | Check-in survives concurrent submissions on SQLite's single writer |
| SEC-1 | §8 | HTTPS, cookie flags, hashed passwords, CSRF, login limit, reset expiry, validation, escaping |
| SEC-2 | §8 | Ownership enforced at controller and service; tests for IDOR and cross-tenant access |
| SEC-3 | §8 | Public QR responses limited to the minimal fields |
| SEC-4 | §8 | Throttling by IP and token; referrer policy keeps check-in URLs private |
| PRIV-1 | §8 | Minimal privacy statement for the Swiss FADP |
| A11Y-1 | §8 | WCAG 2.2 AA in the core flows |
| J-A–J-I | §5 | The nine acceptance journeys |
| DOD | §10 | Definition of done |

## Reference material

- **Scope:** `PRD_TanzHaken_MVP.md`.
- **Design:** `docs/design/HANDOFF.md` (the Claude Design handoff, verbatim) and, after #1, the imported files beside it.
- **The owner's other Symfony projects**, for conventions to copy and trim: listed in `.claude/harness/local-references.md` in the main checkout (git-ignored; from a lane it is `../../harness/local-references.md`). They use PostgreSQL, Docker and a command bus; TanzHaken uses none of the three (S1, S2), so take the patterns, not the stack. Never name them or their paths in a committed file.
- **Hosting notes:** the `cyon-php-app` skill.

## W0 Foundation

### 0. `project-skeleton`

- **Repository:** G1 is resolved by the CTO's bootstrap before this cycle starts; create and push nothing. No branch protection (the harness pushes to `main`). `.gitignore` already covers the harness's local files.
- **Symfony 7.4** via Composer, trimmed to S1's components. Remove the recipe's layer-first `src/Controller`, `src/Entity`, `src/Repository`.
- **Modules:** the S2 folders with `.gitkeep`, the phpat rules for the whole module table and the layers, and a probe test that proves a forbidden dependency fails.
- **Database:** SQLite `var/data_<env>.db`, the on-connect setup from S3, the migrations folder and namespace, an empty first migration if needed.
- **Routes:** every S5 route as a placeholder, one `PlaceholderController` per module, rendering `placeholder.html.twig` with the route name. `health` answers `{"status":"ok"}`.
- **Stubs for #2:** `templates/base.html.twig` with the blocks `title`, `meta`, `stylesheets`, `body_class`, `main`, `scripts`, and the three S6 layouts as empty shells that extend it.
- **Makefile:** `init`, `serve` (reads `HARNESS_PORT` from `.lane.env`, default 8000), `db-migrate`, `db-fresh`, `seed`, `test`, `cs-fix`, `cs-check`, `phpstan`, `lint`. `lint`, `test` and `serve` depend on a `vendor` target that runs `composer install` when `vendor/` is missing or older than `composer.lock`, because a lane is rebased without a reinstall.
- **Tests:** the four suites, the test database reset strategy (research picks it), one functional smoke test.
- **CI:** GitHub Actions on push to `main` and on pull requests: `composer install`, `make lint`, `make test` on PHP 8.4.
- **Instructions:** add the app's commands, module map and conventions to `CLAUDE.md` below the harness section, and one `.claude/rules/module-<name>.md` per module plus rules for architecture, database, security, frontend and i18n taken from S2–S7.
- **Also:** `.env` with safe defaults, `.env.example` without secrets, a README stub with local startup, which replaces the root `README.md` that already exists on `main`.
- **Done when:** `make init && make serve` works on a clean clone; `make lint` and `make test` are green locally (the CTO checks CI after the landing); the app starts on the lane's port, and on 8000 when `.lane.env` is absent.

### 1. `design-reference`

Writes documentation only (`docs/design/`), so it cannot collide with #0. It still runs in its own lane like every cycle. Gate G2.

- Import the Claude Design project named in `docs/design/HANDOFF.md` through the `claude_design` MCP server: `TanzHaken.dc.html` and the files it imports (`ios-frame.jsx`, `support.js`). Store them unchanged under `docs/design/source/`.
- Write `docs/design/README.md`: a screen inventory (one row per screen or state: name, purpose, which cycle builds it), the tokens read from the design (colours, type scale, spacing, radii, shadows, icons), the component list with variants, and the breakpoints shown.
- Export or capture one image per screen at the widths the design provides into `docs/design/screens/`.
- List every difference between the design and the PRD (screens the PRD needs that the design lacks, e.g. desktop layouts, error and empty states, the privacy page; and anything the design shows that PRD §9 excludes). For each one, state what the build will do.
- **Done when:** a UI teammate can build any screen from `docs/design/` without the MCP server; the gap list is complete; nothing under `src/`, `templates/` or `assets/` changed.

## W1 Design system and data model (parallel)

### 2. `design-system`

- **Tokens** from #1 into `assets/styles/tokens.css`; record every contrast pair that had to change to meet AA.
- **Layouts:** take over `base.html.twig` and the three layouts. Teacher layout: bottom tab bar on phones, compact top or side navigation on desktop, the four items linking to S5's routes; skip link; flash-message region. Public layout: nothing but the brand name (G3) and the content. Every layout links to `privacy` from the start (the route exists as a placeholder), so #18 never edits a layout.
- **Components** (Twig partial + CSS file each), as the design defines them; at least: button, link button, text field and the Symfony form theme, select, checkbox, card, list row, status badge, balance badge (S9 states), alert, empty state, page header with action, table that stacks on phones, dialog, tab bar, toast.
- **Icons:** one inline SVG sprite from a permissively licensed set, licence recorded.
- **Styleguide** at `/_styleguide` (dev and test only) rendering every component in every state.
- **Catalogue:** layout strings in `messages`; a test that fails on raw text in templates.
- **Error pages:** 403, 404, 500 in the public layout.
- **Done when:** the styleguide matches `docs/design/` at 390 and 1440 px; no inline style or script; keyboard focus is visible on every component; placeholders render inside the teacher layout with working navigation.

### 3. `domain-model`

- The nine entities of PRD §6 in their S2 modules, with S3's IDs, time rules, constraints and indexes for the roster, balance and register queries.
- `Teacher` implements the Security user interfaces (role `ROLE_TEACHER`, identifier = email) but this cycle configures no firewall.
- Repositories with the owned finders from S4, including `LessonSessionRepository::hasAttendance()` for #5: a DBAL query, so `Courses` does not depend on `Attendance` (S2).
- `Shared`: `AuditLogger` writing immutable `audit_event` rows (actor, action, entity, JSON details).
- Foundry factories for every entity and the demo story behind `make seed`.
- Integration tests: every unique and foreign key rejects bad data on SQLite; cascade and archive behaviour is as S3 says; one migration creates the schema from empty and `down()` removes it.
- **Done when:** `make db-fresh && make seed` works; the constraint tests pass; the outcome file lists every table, column, index and factory.

## W2 Rules of the domain (parallel backend lanes) and login

### 4. `teacher-auth`

- `security.yaml`: one firewall, form login, logout, entity provider, `ROLE_TEACHER` on everything except S5's public and auth routes, login throttling, the S4 cookie settings.
- Registration (name, email, password; unique normalised email; timezone preset `Europe/Zurich`), login, logout, `home` redirect.
- The S4 voter and a small test helper other cycles use to log a teacher in.
- Functional tests: register → login → reach a protected placeholder; wrong password; throttling; anonymous access redirects to login; a second teacher is a separate tenant.
- **Done when:** the tests pass and the auth pages match the design.

### 5. `scheduling`

Backend only.

- `ScheduleGenerator`: lessons from a course template for N weeks (default 26) or until `ends_on`, in the course's zone, idempotent against `(course_id, starts_at_utc)`.
- Extend the schedule; add one lesson; re-time one lesson (conflict-safe); cancel (refused while attendance exists, S9); complete.
- Unit tests for Journey H: 19:00 Europe/Zurich across both DST changes stays 19:00 local with shifted UTC; the non-existent and the repeated local hour; end-date and week-count limits; extension without duplicates.
- **Done when:** the tests pass and the service API is listed in the outcome file.

### 6. `credit-ledger`

Backend only.

- `CreditLedger`: grant, void, correct (void + new grant), each with an audit event.
- `Balances` read model (S2): balance per enrollment; all enrollments of a course in one query; all of a student's enrollments; the renewal state of S9; the teacher-wide list of enrollments at or below zero for #17.
- Tests: 10 → 9 → 0 → −1 → +10 = 9 (PRD §11); voided grants and cancelled lessons excluded; `absent` ignored; query count stays constant as the roster grows.
- **Done when:** the tests pass and the read model's methods are listed in the outcome file.

### 7. `attendance-core`

Backend only.

- `AttendanceMarker`: mark present or absent as the teacher, clear a mark, each with source `teacher`, an optional reason and an audit event; refuses students not enrolled in the lesson's course and cancelled lessons.
- `recordCheckIn()` for #8: the race-safe insert of S8 with source `student_qr`, returning `recorded`, `already_present` or `marked_by_teacher`.
- Register read model: a lesson's roster with status, and a student's history across courses.
- Tests: Journey F's rule (present → absent lowers the count of `present` marks by one and is logged; the balance side is tested in #13); the unique key under two concurrent inserts on SQLite; every refusal.
- **Done when:** the tests pass, including the concurrency test.

## W3 Check-in rules and the first pages (parallel)

### 8. `checkin-core`

Backend only. Implements S8.

- `CheckInWindows`: open (completes the lesson), close, rotate; token creation and hashing; `resolve(token)` returning the lesson or one typed reason for failure.
- `PublicCheckIn`: the roster labels for a valid token (first name + last initial, the enrollment's label where set, and a rule that keeps two students apart when both forms collide), and `confirm(token, enrollment)` on top of #7.
- The two rate limiters (per IP, per token) as services, in their own config file.
- Tests: expiry, close, rotation invalidating the old token, malformed tokens, cancelled lessons, non-enrolled and archived students, duplicates (Journey C) and every failure of Journey D leaving the data unchanged; the stored value is a hash.
- **Done when:** the tests pass.

### 9. `students-ui`

- List with search by name and an archived filter, create, edit, archive and restore.
- Student page: details, enrolled courses (read-only, linking to `course_show`), and the two slots of the hotspot table.
- Functional tests including a foreign teacher's student answering 404.
- **Done when:** the pages match the design and the tests pass.

### 10. `courses-ui`

- Course list as cards, create (generates the lessons through #5), edit, archive.
- Course page: lesson list with status; extend schedule; add, re-time, cancel a lesson; roster with enroll (from the teacher's existing students; creating a student is #9's page), remove, and the check-in label; the roster-row slots of the hotspot table.
- Functional tests: Journey A up to the enrollments; lesson operations; the 404 rule on nested IDs (`/courses/{id}/…` with another teacher's lesson).
- **Done when:** the pages match the design and the tests pass.

### 11. `account-and-reset`

- Forgot and reset password: emailed link, single use, expiry, no account enumeration, limiter on requests. Research picks between `symfonycasts/reset-password-bundle` and a small own table and records why.
- Account page: display name, password change (current password required), timezone; the slot for #18.
- Tests with the mailer assertions; an expired and a reused link.
- **Done when:** the tests pass and the mails read well in plain text.

## W4 Credits, register and the public check-in (parallel)

### 12. `credits-ui`

- "Add credits" on a roster row and on the student page (quantity 10 preset, date, note); void and correct with a confirmation; the balance badge of S9 in both places; the student's credit history.
- The course page highlights balances at or below zero and the "Low" state (REG-5).
- Functional test for Journey A's end (Alex has 10) and for a void.
- **Done when:** the pages match the design and the tests pass.

### 13. `attendance-ui`

- Lesson page `lesson_show`: roster with status and balance; mark present or absent, clear, with the optional reason; complete the lesson; the slot for #16.
- The student page's attendance history.
- Functional tests for Journeys E and F through the pages.
- **Done when:** the pages match the design and the tests pass.

### 14. `checkin-public`

- `/c/{token}`: the lesson header (QR-2), the roster as large buttons, a confirm step, then "Attendance recorded" with the remaining lessons of that student in that course (from #6's `Balances`), or "Already checked in"; one public message for every invalid case.
- At most three taps after the scan. Works without JavaScript. S8's headers, limits and request integrity.
- Functional tests for Journeys B, C and D, and a test that no response body contains an email, a note, another student's balance or a full last name.
- **Done when:** the page matches the design at 390 px, is usable with one hand, and the tests pass.

### 15. `csv-export`

- `course_export`: one row per lesson and student (date, time with zone, student, status), then a balance summary per student. UTF-8 with BOM, `text/csv` download, cells starting with `=`, `+`, `-`, `@`, tab or carriage return neutralised.
- The export button through its slot.
- **Done when:** the file opens correctly in Excel and LibreOffice conventions (tested on the bytes) and the injection test passes.

## W5 The teacher's check-in screen and the rest

### 16. `checkin-teacher-ui`

- "Open check-in" on the lesson page; `checkin_manage` with the large QR code as server-side SVG (research picks the PHP library), the copyable URL, time left, close and reopen (rotate), and the roster filling in through `checkin_status`.
- A presentation layout that suits a phone, a tablet and a projected screen.
- Functional tests: open, rotate (old URL dead), close, the status JSON holding no more than S4 allows.
- **Done when:** a phone can scan the code from the screen in the local network test, and the tests pass.

### 17. `dashboard`

- Today's and upcoming lessons across courses, "Open check-in" where a lesson is today or just ended, and the renewal alerts from #6.
- **Done when:** the page matches the design, uses a fixed number of queries, and its test passes.

### 18. `data-protection`

- `privacy`: the statement PRD §8 lists (controller and contact from G5, purpose, categories, storage region, retention, deletion, how a student asks). The link to it is already in every layout (#2).
- Account deletion on the account page: password confirmation, then every row of that teacher is removed in one transaction, the session ends, and a test proves no row remains and another teacher's data is untouched.
- **Done when:** the tests pass and the statement names every kind of data the schema holds.

## W6 Hardening (parallel)

### 19. `security-hardening`

- Response headers: CSP without `unsafe-inline`, `Referrer-Policy`, `X-Content-Type-Options`, frame denial, HSTS in production, `noindex` where S8 asks.
- A test that walks **every** route with a second teacher's IDs and expects 404 on reads and writes (Journey G), generated from the router so a new route cannot be forgotten.
- Review of every limiter, every CSRF token and every cookie flag against SEC-1–SEC-4.
- **Done when:** the route walk is green and a header test covers each layout.

### 20. `a11y-responsive-polish`

- Every page at 390 and 1440 px against the design; no horizontal scroll in forms; keyboard path and focus order through Journeys A–F; labels, error summaries, contrast, reduced motion.
- Empty, error and loading states everywhere they can occur.
- **Done when:** an automated accessibility check reports no serious issue on the core pages, and the findings that need the owner's device (G7) are listed.

### 21. `db-portability`

- MariaDB in Docker as a tool: run every migration from empty, then the whole suite with `DATABASE_URL` pointing at it; fix what differs; add the run as a second CI job if it is stable.
- The README's sentence about database support (G8) belongs to this cycle; #22 leaves that sentence alone.
- **Done when:** both databases are green, or G8's default text is in the README with the reason.

### 22. `deploy-docs`

- README: local startup and deployment, everything except the database-support sentence, which #21 owns. A deployment guide: document root `public/`, `APP_ENV=prod`, `APP_SECRET`, `DATABASE_URL`, `MAILER_DSN`, the SQLite file outside the web root with restricted permissions, WAL, backup and restore commands, running migrations, HTTPS.
- A rehearsal: build and run in production mode locally and walk Journey B.
- **Done when:** someone who has not seen the project can deploy from the guide, and `.env.example` holds every variable and no secret.

## W7 Release

### 23. `release-verification`

- Journeys A–I as one end-to-end suite; a concurrency test that fires parallel confirmations at a running server on SQLite.
- The PRD §10 checklist, item by item, with evidence.
- A release report for the owner in `docs/RELEASE.md`: what was built, every decision from `docs/harness/DECISIONS.md` that the owner may want to reverse, open gates, and the manual device test of G7 as a step list.
- **Done when:** the suite is green on a clean clone and the report is written.

## Out of scope / proposals (not scheduled)

PRD §9 in full. Noted for later: German catalogue; per-student private check-in code; PWA install; `design-alignment` (only if G2's default was used).
