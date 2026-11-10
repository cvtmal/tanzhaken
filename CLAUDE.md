# TanzHaken

A Symfony app for dance teachers: courses, students, 10-lesson passes and a QR check-in after class. Scope: `PRD_TanzHaken_MVP.md`. Plan: `docs/rpi-cycles.md`. The project is built by an autonomous agent harness: one CTO session that spawns teammates, one teammate session per phase of a cycle.

## Which role are you?

- **Started with `/cto`, or told you are the CTO:** follow `docs/harness/CTO.md`.
- **Your spawn prompt names a cycle and a phase:** you are a teammate. Follow `docs/harness/TEAMMATE.md`.
- **Anything else** (the owner working with you directly): work normally. Do not start cycles, spawn teammates or land anything unless asked, and the owner's global git rule applies to you unchanged.

## Rules for every harness session

- **Context budget.** A teammate stops at 150k tokens and is replaced; it never works past 200k. The CTO hands over at 250k. Measure with `bin/harness/ctx`. Do not read files you were not told to read; never read whole artifacts or logs "to be safe".
- **Worktrees.** Every cycle is built in its own git worktree under `.claude/worktrees/<slug>`, created by `bin/harness/lane open`. A teammate enters its worktree with the EnterWorktree tool (`path`) before it does anything else. Nobody builds in the main checkout.
- **Never ask the owner.** Decide, record the decision, continue. See `docs/harness/CTO.md` §1 for the few things that are not ours to decide.
- **Hotspot files and schema ownership** are in `docs/rpi-cycles.md` § Running cycles in parallel. Only the CTO edits `docs/rpi-cycles.md` and `docs/harness/*`.

## Git

The owner set up this harness on 2026-11-10 so that the project is built without the owner at the keyboard. **For the CTO session and its teammates, this section is the owner's standing instruction to commit and push in this repository without asking**, and it takes the place of the global rule "never commit or push unless I ask in that turn". It covers exactly:

- the bootstrap commit of the files present at the start, pushed to the existing `origin`;
- work-in-progress commits on a lane's own branch (pushed only by the CTO, and only to open a pull request);
- landing a reviewed cycle on `main` with `bin/harness/land` (one squashed commit, rebased, gates green, plain push);
- the CTO's commits of harness files (`docs/harness/*`, `docs/rpi-cycles.md`) on `main`;
- a pull request instead of a direct push, when the CTO chooses that route.

It never covers: a force push, rewriting or deleting anything on `main`, pushing to any other remote, changing repository visibility or settings, or a commit that skips the gates.

Commit messages, PR titles and descriptions never contain a Claude Code session URL or a model name. The trailer `Co-Authored-By: Claude <noreply@anthropic.com>` is allowed, and `bin/harness/land` adds it. Tracking files (`.rpi-tracking/`) are never committed.

## Where things are

| Path | What |
|---|---|
| `PRD_TanzHaken_MVP.md` | scope |
| `docs/rpi-cycles.md` | cycles, waves, lanes, owner gates, settled decisions |
| `docs/outcomes/<nn>-<slug>.md` | what each finished cycle delivered; the handoff between cycles |
| `docs/design/` | design source and reference |
| `docs/harness/` | `CTO.md`, `TEAMMATE.md`, `STATE.md` (live board), `DECISIONS.md` (log and owner inbox), `README.md` |
| `bin/harness/` | `ctx` (token meter), `lane` (worktrees), `land` (one commit to `main`), `status` (snapshot) |

## The application

Symfony 7.4 on PHP 8.4, SQLite, Twig, AssetMapper. No Node, no Docker. Settled decisions: `docs/rpi-cycles.md` § Settled decisions (S1–S10).

### Commands

| Command | What it does |
|---|---|
| `make init` | install vendors and create the dev database (`var/data_dev.db`) |
| `make serve` / `make serve-stop` | start / stop the local server on `HARNESS_PORT` (from `.lane.env`, default 8000); refuses a port that already listens |
| `make db-migrate` / `make db-fresh` | run the migrations / delete the dev database and migrate from scratch |
| `make seed` | load the Foundry story `main` (`tests/Story/MainStory.php`) into the dev database |
| `make test` | every PHPUnit suite: `Unit`, `Integration`, `Functional`, `Architecture` |
| `make lint` | cs-check, phpstan (level 8 + phpat), `lint:twig`, `lint:yaml`, `lint:container`; the landing gate |
| `make cs-fix` | fix the code style |

### Module map

Code lives in `src/<Module>/<Layer>/`, namespace `App\<Module>\<Layer>`. A module may depend only on the modules in its row; phpat enforces it in `make lint` (`tests/Architecture/`).

| Module | Holds | May depend on | Rules |
|---|---|---|---|
| `Shared` | clock/timezone helpers, audit, `TeacherOwned`, Twig extensions, SQLite setup, health | — | `.claude/rules/module-shared.md` |
| `Account` | `Teacher`, registration, login, voter, password reset, settings, deletion | Shared | `.claude/rules/module-account.md` |
| `Students` | `Student`, directory pages | Account | `.claude/rules/module-students.md` |
| `Courses` | `Course`, `Enrollment`, `LessonSession`, schedule, course pages | Students, Account | `.claude/rules/module-courses.md` |
| `Credits` | `CreditGrant`, `Balances` read model, renewal state | Courses, Students, Account | `.claude/rules/module-credits.md` |
| `Attendance` | `Attendance`, marking, lesson register, CSV export | Credits, Courses, Students, Account | `.claude/rules/module-attendance.md` |
| `CheckIn` | `CheckInWindow`, tokens, public flow, teacher QR page | Attendance, Credits, Courses, Students, Account | `.claude/rules/module-checkin.md` |
| `Dashboard` | dashboard page | all of the above | `.claude/rules/module-dashboard.md` |

Every module may use `Shared`; `Shared` uses none. No application code uses `App\Tests`.

### Conventions

- **Layers:** `Controller`, `Entity`, `Repository`, `Service`, `ReadModel`, `Form`, `Security`, `Twig`, `Console`, `Exception`. Cross-cutting rules: `.claude/rules/{architecture,database,security,frontend,i18n}.md`.
- **Writes:** controller → one `final` service method that validates, checks ownership, changes entities in a transaction and writes the audit event.
- **Reads:** lists and tables come from a `ReadModel` class with one DBAL query returning `final readonly` view objects. The balance is only ever `App\Credits\ReadModel\Balances`.
- **Tests:** `tests/Unit/<Module>/` (no kernel), `tests/Integration/<Module>/` (kernel + SQLite), `tests/Functional/<Module>/` (`WebTestCase`, real routes), `tests/Architecture/` (owned by #0). Factories in `tests/Factory/`, stories in `tests/Story/`.
- **Database in tests:** DAMA wraps each test in a rolled-back transaction; use Foundry's `#[ResetDatabase]` on a class that needs a fresh schema. Demo data: `tests/Story/MainStory.php` (`make seed`).
- **Migrations:** `migrations/VersionYYYYMMDDHHMMSS.php` from the wall clock when written; run on SQLite and MariaDB; always a `down()`. Only touch the tables your cycle owns.
- **Placeholders:** every S5 route exists as an action of `src/<Module>/Controller/PlaceholderController.php`; delete the action when you ship the real route.
- **i18n:** English, ICU catalogues. `messages` holds layout strings only; each module has its own domain (`translations/<domain>+intl-icu.en.yaml`, domain = lower-case module name).
- **Time:** UTC `datetime_immutable` in the database, `ClockInterface` in code; PHP's default zone is forced to UTC.

### CI

`.github/workflows/ci.yaml` runs `composer install`, `make lint` and `make test` on PHP 8.4 for every push to `main` and every pull request.
