# #4 teacher-auth — outcome (2026-11-11)

## Delivered
Teacher registration, login, logout and the deny-by-default firewall (ACC-1, ACC-2, ACC-5, SEC-1): a custom user provider with normalised emails and password rehash, built-in login throttling, the `TEACHER_OWNS` voter for every later ownership check, the A1/A2/A3 auth pages (phone and desktop split with brand panel), the sidebar logout, the `home` redirect, and a functional-test login helper. The four placeholder actions `home`, `register`, `login`, `logout` are gone. No new table, migration, package or env variable.

## Interfaces
- **Routes:** `home` GET `/` → `dashboard` when logged in, else `login`. `register` GET|POST `/register`: A2 form; success logs in (`Security::login()`) and redirects to `dashboard`; a taken email (any case) → field error, 422; logged in → `dashboard`. `login` GET|POST `/login`: A1/A3 form, errors `login.error.{invalid,throttled,expired}`; logged in → `dashboard`. `logout` POST `/logout` only (GET → 405), handled by the firewall, → `/login`.
- **`config/packages/security.yaml`:** one firewall `main` (lazy) with provider `teachers` (`App\Account\Security\TeacherUserProvider`); `form_login` on `login` with CSRF (token id `authenticate`, stateless), target `dashboard`; `logout` with CSRF (token id `logout`), target `login`; `login_throttling` 5 failures per email+IP per 5 minutes; `access_control` = list of `PUBLIC_ACCESS` paths (`/`, `/login`, `/register`, `/forgot-password`, `/reset-password/`, `/c/`, `/privacy`, `/health`, `/_styleguide`, `/_error`) then `^/` → `ROLE_TEACHER`.
- **Entity:** `Teacher::getDisplayName(): string` (sidebar and public pages).
- **Security:** `Account\Security\TeacherUserProvider` (load by `TeacherRepository::findOneByEmail()`, refresh by id, `PasswordUpgraderInterface`). `Account\Security\TeacherOwnsVoter` (`ATTRIBUTE = 'TEACHER_OWNS'`, subject `Shared\Security\TeacherOwned`, grants only the owner).
- **Service:** `Account\Service\Registration::register(RegistrationData): Teacher` — refuses a known email before the transaction (`Exception\EmailAlreadyRegistered::forEmail()`, also on a concurrent unique-key hit), hashes, persists, audit event `teacher.registered` (actor teacher, entity `teacher`).
- **Forms:** `Account\Form\LoginType` (unnamed: `_username`, `_password`, `_csrf_token`), `RegistrationType` + DTO `RegistrationData` (`PASSWORD_MIN` 8, `PASSWORD_MAX`).
- **Templates:** `account/login.html.twig`, `account/register.html.twig`, `account/_brand_panel.html.twig` (A3 panel, ≥ 64rem, aria-hidden), `account/_logout_form.html.twig` (POST + CSRF; nothing when anonymous), included in `layout/_sidebar.html.twig`'s footer.
- **CSS:** `assets/styles/account/auth.css` (imported from `app.css`): auth spacing, A3 split via body class `auth-split`, sidebar logout form.
- **Translations:** domain `account` (`login.*`, `register.*`, `logout.button`, `brand_panel.*`); domain `validators` (`translations/validators+intl-icu.en.yaml`, `account.register.*`).
- **Test support:** trait `tests/Functional/LogsInTeacher.php`: `logInTeacher(KernelBrowser, ?Teacher = null): Teacher`, `resetLoginThrottling(KernelBrowser)`.

## How to use it
- **Protect an owned resource:** `#[IsGranted(TeacherOwnsVoter::ATTRIBUTE, subject: 'course', statusCode: 404)]` — a foreign id answers 404, never 403. Every non-public path already needs `ROLE_TEACHER`; a new public route needs its own `PUBLIC_ACCESS` line above the last rule.
- **Functional tests:** `use LogsInTeacher;` + `#[ResetDatabase]`; `$client = static::createClient(); $this->logInTeacher($client);` — always after `createClient()` (booting the kernel first makes it throw).
- **D-030:** every test that submits the login form calls `resetLoginThrottling($client)` first; the limiter counts live in the filesystem `cache.rate_limiter` pool and outlive the rolled-back transaction.
- **`PlaceholderRoutesTest` (D-028, D-034):** a shipping cycle edits only its own routes' rows: it moves them from `placeholderRoutes()` to `SHIPPED` (and out of `PUBLIC`); the test body is #4's. Public placeholders are listed in `PUBLIC` (rendered anonymously); all others are logged in and also asserted to redirect anonymous visitors to `/login`.
- **Form validation messages (D-038):** `translations/validators+intl-icu.en.yaml` is a hotspot owned by #4. Other cycles only append keys prefixed with their module (`students.…`, `courses.…`); never restructure the file. Symfony translates constraint messages only in the `validators` domain.

## Decisions
- P1 Logout only in the sidebar footer (D-029); unreachable on phones until #11 (CTO accepted, D-033). Undo: move the include.
- P2 Login form via `createNamed('')`, not `getBlockPrefix(): ''`: same field names, less magic. Undo: override the block prefix.
- P3 `Registration` hashes by class with `PasswordHasherFactoryInterface` before constructing `Teacher` (the constructor needs the hash). Undo: none needed while the constructor requires a hash.
- P4 A CSRF failure on login gets its own "page expired" message. Undo: map it to `login.error.invalid`.
- P5 Design deviations from A1–A3 accepted (see Deviations). P6 Brand panel built only in `account/auth.css` with a body modifier; `layout.css` untouched. Undo: delete the class and partial.
- P7 `Shared/LayoutTest` (#2's file) logs in before teacher-layout requests; required once ACC-5 holds (CTO accepted, D-033). Undo: drop the logins (only if teacher routes were public again).
- Validation messages in domain `validators`: Symfony translates constraint messages there, not in `account`; keys prefixed with the module so modules never collide. Undo: move them and set `translation_domain` per constraint.
- Text around links split into keys (`before`/`link`/`after`, `question`/`link`) so the link stays escaped Twig, no `|raw` (S7). Undo: one key with HTML and `|raw`.
- Throttled message shows Symfony's computed minutes (can read "6 minutes" for a 5-minute window); tests match the prefix only. Undo: own message without a number.
- Brand tagline `calc(var(--display-44) * 1.35)` and 44 px pass dots in the panel: tokens only, no new colours. Undo: drop the overrides.

## Deviations
- D-031 (postdates the plan's base): `layout/_flashes.html.twig` included in `layout/auth.html.twig` and `layout/public.html.twig`, stale comment at the top of `layout/teacher.html.twig` fixed — at the CTO's request.
- `RegistrationData` messages renamed `register.*` → `account.register.*` and moved to `validators` (the plan said `account` domain).
- `RegistrationType` also sets label keys and `autocomplete` (`name`, `email`, `new-password`); `TeacherOwnsVoter` relies on the `Voter<string, TeacherOwned>` generic (no second `instanceof`, phpstan).
- Tests: `RegistrationTest` (integration) checks the hash via `PasswordHasherFactoryInterface`; `PlaceholderRoutesTest`'s anonymous test has its own filtered provider `teacherPlaceholderRoutes()` (no skipped-test noise); `LoginTest` also asserts the sidebar name and that a throttled visitor stays anonymous; `HomeTest` holds the anonymous `/courses`, `/students`, `/account` checks.
- `TenantSeparationTest` uses `createClient()` for A and `getContainer()->get('test.client')` (non-shared: own cookie jar) for B, since a second `createClient()` throws once the kernel is booted.
- Design (P5): "Forgot password?" under the field; A1's lede also on desktop (not A3's); register keeps the layout wordmark; layout's Privacy footer instead of A3's line; no check icon on the password hint; legal line names only the Privacy Policy (no Terms page exists). From #2's shared components: wordmark plain Newsreader (A3: italic "Haken"), empty pass dots solid rings (A3: dashed).
- Step 6.3 fix (fc54521): `.auth-card:has(> .auth-page)` and `.auth-page` flex columns put the "New to …? / Already registered?" line at the bottom of the phone screen, as in A1/A2.

## Carried forward
- Logout and teacher name unreachable on phones (sidebar hidden below 64rem) → #11: the account page includes `account/_logout_form.html.twig`.
- Registration rate limit, enumeration-neutral duplicate-email message, `NotCompromisedPassword` → #19.
- JSON 401 instead of a login redirect for `checkin_status` → #16.
- `toast.js` not loaded in the auth/public layouts (success toasts there do not auto-hide) → #11, if it flashes success onto `/login`.
- Wordmark italic "Haken" and dashed empty pass dots differ from A3 → #20 (or a #2 follow-up).
- Review F-m1: the `security.yaml` comment above `^/c/` says "#16"; the public check-in is #14's. Comment only → #19 (security.yaml is #4's hotspot: needs a CTO grant).
- Note (dev only): the web debug toolbar covers the sidebar "Log out" at 900 px height; not in prod.

## Verification
- `make cs-fix` 0 files; `make lint` — pass (cs-check, phpstan level 8 + phpat, lint:twig, lint:yaml, lint:container).
- `make test` — pass (324 tests, 2386 assertions, no deprecations); Functional 67 tests, green twice within the 5-minute limiter window.
- `bin/console debug:firewall main` — provider, form_login entry point, throttling and logout CSRF listeners; `debug:router` — the four routes once each.
- `make db-fresh && make seed` exit 0; demo login `demo@example.com` / `tanzhaken-demo` on port 8003 via curl (302 `/dashboard`, sidebar "Mara Lindqvist") and in Chrome.
- Browser at 390 and 1440 px (viewport emulated with a same-origin iframe because `resize_window` had no effect): `/login`, `/register`, wrong-password and throttled states, register → dashboard, sidebar logout; no horizontal scroll.
- Review re-run (2026-11-11, lane on `dd23f9d` with #5–#8): `make lint` pass; `make test` OK (389 tests, 3513 assertions); `make db-fresh && make seed` exit 0; curl on 8003: `/` and `/courses` → `/login`, `/login`, `/register`, `/c/abc`, `/health` 200.
- Pending human: real-phone check of `/login` and `/register` (G7).
