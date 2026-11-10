# #00 project-skeleton — outcome (2026-11-10)

## Delivered
A Symfony 7.4 skeleton on PHP 8.4 with SQLite, Doctrine ORM 3 / DBAL 4, Twig, AssetMapper, Form, Validator, Security (recipe default), Mailer, Translation, Clock and Uid. Eight module folders under `src/` (S2), every S5 route as a placeholder page, `/health`, SQLite PRAGMAs on connect, four PHPUnit suites, phpat module and layer rules inside PHPStan level 8, PHP-CS-Fixer, a Makefile with the landing gate (`make lint`, `make test`), GitHub Actions CI, `CLAUDE.md` § The application, 13 rule files in `.claude/rules/`, `.env.example` and a README. No entity, table or migration exists yet.

## Interfaces
- **Routes** (23, `debug:router --format=json` without `_` names): `home register login logout password_forgot password_reset account privacy` (`src/Account/Controller/PlaceholderController.php`), `student_*` ×4 (Students), `course_index/new/show/edit` (Courses), `lesson_show course_export` (Attendance), `checkin_manage checkin_status checkin_public` (CheckIn), `dashboard` (Dashboard), `health` (`src/Shared/Controller/HealthController.php` → JSON `{"status":"ok"}`). Placeholders are GET, render `templates/placeholder.html.twig` with the route name; path params unconstrained.
- **Services:** `App\Shared\Doctrine\SqliteConnectionMiddleware` (`#[AsMiddleware(priority: 200)]`) wraps the driver in `SqliteConnectionDriver`, which on a SQLite connect runs `foreign_keys=ON`, `busy_timeout=5000`, `journal_mode=WAL`; other platforms pass through.
- **Kernel:** `Kernel::boot()` forces PHP's default timezone to UTC (also set in `phpunit.dist.xml`).
- **Templates:** `base.html.twig` (links `styles/app.css` explicitly), `layout/{teacher,auth,public}.html.twig` stubs (empty until #2), `placeholder.html.twig`. Layout strings: `translations/messages+intl-icu.en.yaml`.
- **Make targets:** `init serve serve-stop db-migrate db-fresh seed test cs-fix cs-check phpstan lint-twig lint-yaml lint-container lint help`. `serve` uses `HARNESS_PORT` from `.lane.env` (default 8000) and refuses a port that already listens.
- **Tests:** suites `Unit`, `Integration`, `Functional`, `Architecture` (`*ProbeTest.php`). `tests/Architecture/ArchitectureRules.php` holds the S2 table; `ModuleBoundariesTest`, `LayersTest`; the probe runs phpstan on fixtures and expects exactly one Students→Courses error. `tests/Factory/` (empty), `tests/Story/MainStory.php` (empty story `main`).
- **Config files:** `config/packages/dama_doctrine_test_bundle.yaml` (key `dama_doctrine_test`), `zenstruck_foundry.yaml`, `doctrine.yaml` (attribute mapping `src/<M>/Entity` for every module except Dashboard), plus the recipe files. `.github/workflows/ci.yaml`.
- **Env:** `APP_ENV`, `APP_SECRET` (empty in `.env`), `APP_SHARE_DIR`, `DEFAULT_URI`, `DATABASE_URL` (SQLite `var/data_%kernel.environment%.db`), `MAILER_DSN=null://null`; all documented in `.env.example`.

## How to use it
- Ship a real route: delete its action from `src/<Module>/Controller/PlaceholderController.php` and add your controller; `tests/Functional/PlaceholderRoutesTest.php` lists the placeholder routes, so remove the route from its data provider in the same change.
- Entities go in `src/<Module>/Entity/` (mapping already configured); migrations in `migrations/`; factories in `tests/Factory/`, demo data in `tests/Story/MainStory.php` (`make seed`).
- Module notes: append to your module's `.claude/rules/module-<name>.md`; a new dependency goes into `composer.json` with a one-line reason in your outcome file.

## Decisions
- OD-1 `phpstan/extension-installer` added, so later cycles add PHPStan extensions by `composer require` only. Undo: remove it and include phpat's `extension.neon`.
- OD-2 Entity layer rule is a deny-list (no Controller/Repository/Service/ReadModel/Form/Twig/Console), so #3 can use Shared types and implement `TeacherOwned`.
- OD-3 PHP default timezone forced to UTC. OD-4 CSS linked in `base.html.twig`, not imported via `app.js`. OD-5 `serve` port guard + `serve-stop`. OD-6 translation domains = lower-case module names; template folders per module as in the rule files. OD-7 placeholders extend `base.html.twig`.
- `SqliteConnectionMiddleware` priority 200: it must sit inside DAMA's middleware (100), which opens a transaction on connect; WAL fails inside a transaction.
- phpat rules forbid `App\Tests` for every module (keeps Dashboard's target list non-empty) and use `shouldNot()->dependOn()` / `should()->beFinal()` (shortcuts deprecated in phpat 0.12).
- Foundry is dev/test only, so factories and stories live in `tests/`, not `src/`. DAMA + Foundry `#[ResetDatabase]` reset the test DB.
- CI pins `actions/checkout@v7`, `actions/cache@v6`, `shivammathur/setup-php@v2` (latest majors on 2026-11-10).

## Deviations
- DAMA's recipe is contrib and did not run; bundle and config written by hand.
- `phpstan.dist.neon` excludes `tests/Architecture/Fixtures (?)`; `tests/bootstrap.php` drops the recipe's `method_exists` guard (level 8).
- `migrations/.gitignore` renamed to `.gitkeep`; `composer.json` got `name`/`description` for `composer validate --strict`.
- `LayersTest` is a plain class, not an `ArchitectureRules` subclass.

## Carried forward
- Entities, tables, migrations, clock/timezone helpers, `AuditEvent`/`AuditLogger`, `TeacherOwned` → #3.
- `security.yaml` content, login throttling, voter → #4; rate limiter configs → #4/#14.
- Design tokens, components, layout chrome, `_styleguide`, `APP_BRAND_NAME` → #2. The importmap inline script needs a CSP nonce → #19.
- `phpstan-doctrine` / `phpstan-symfony` → #3/#19. QR library → #16. `APP_OPERATOR_*` → #18. MariaDB CI job → #21. Logging, trusted proxies, production docs → #22.
- `Requirement::UUID` on path params → each owner cycle with its real route.
- First GitHub Actions run → the CTO after landing.
- Review F-m1: CI caches `~/.composer/cache`, but Composer on the ubuntu runner likely uses `~/.cache/composer`; use `composer config cache-files-dir` as the path → #21 (only slows CI).

## Verification
- `make lint` — pass. `make test` — OK (29 tests, 107 assertions), all four suites.
- Lane serve on 8001: `/health` 200 `{"status":"ok"}`; `/`, `/students/new`, `/c/abc` 200 with their route names; `symfony server:list` shows 8001; server stopped.
- `debug:router --format=json`: 23 application routes.
- Clean clone of the branch: `make init` (creates `var/data_dev.db`), `make test`, `make seed` exit 0; `make -n serve` uses `--port=8000` without `.lane.env`.
- Public-repo grep (reference-project names, local paths, model names, session URLs): no hits in the tree after the sync onto `main` or in the lane's commit messages. No committed `APP_SECRET`.
- Review re-run: `make lint` pass, `make test` OK (29 tests, 107 assertions), 23 routes, lane serve 8001 answers 200.
- Not checked in a browser (no design yet; curl only). Pending human: `make serve` actually binding 8000 on a clean clone outside the harness.
