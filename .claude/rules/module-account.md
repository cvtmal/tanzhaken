---
paths:
  - "src/Account/**"
  - "tests/**/Account/**"
  - "templates/account/**"
  - "templates/auth/**"
  - "translations/account+intl-icu.en.yaml"
---

# Module `Account`

- **Holds:** `Teacher`, registration, login, the `TEACHER_OWNS` voter, password reset, settings, account deletion.
- **May depend on:** Shared.
- **Owning cycles:** #3 (`Teacher`), #4 (auth, voter), #11 (account page, reset), #18 (privacy, deletion).
- **Routes (S5):** `home` `/` (#4), `register` `/register`, `login` `/login`, `logout` `/logout` (#4), `password_forgot` `/forgot-password`, `password_reset` `/reset-password/{token}`, `account` `/account` (#11), `privacy` `/privacy` (#18).
- **Tables (S3):** `teacher` (#3, #4 add-only), `password_reset_request` (#11 creates it).
- **Templates:** `templates/account/**`, `templates/auth/**`.
- **Translation domain:** `account` (`translations/account+intl-icu.en.yaml`).
- **Hotspot slots:** `templates/account/show.html.twig` belongs to #11; #18 creates `account/_delete.html.twig`. `config/packages/security.yaml` belongs to #4.

Namespace `App\Account\<Layer>`. Placeholder actions in `src/Account/Controller/PlaceholderController.php` are deleted by the cycle that ships the real route. Cycles append notes for this module below.

## #3 notes (domain-model)

- `Entity\Teacher(string $name, string $email, string $passwordHash, \DateTimeImmutable $now, string $timezone = Timezones::DEFAULT)` implements `UserInterface`, `PasswordAuthenticatedUserInterface`, `TeacherOwned`, `TeacherAccount`. Roles `['ROLE_TEACHER']`, identifier = email (trimmed, lower-cased; `Teacher::normaliseEmail()`). Intent methods: `rename`, `changeEmail`, `changePasswordHash`, `changeTimezone`.
- `Repository\TeacherRepository::findOneByEmail(string $email): ?Teacher` (normalises first). Exception: `Exception\InvalidTeacher`.
- Deleting a teacher (`DELETE FROM teacher`, #18 only) cascades to every table. The firewall and password upgrade arrived with #4 (see #4 notes).

## #4 notes (teacher-auth)

- `Security\TeacherUserProvider` is the firewall's user provider (provider id `teachers`, firewall `main`): loads by normalised email via `TeacherRepository::findOneByEmail()`, refreshes by id, upgrades password hashes (no audit event). Do not swap in Doctrine's entity provider.
- `Security\TeacherOwnsVoter::ATTRIBUTE` (`TEACHER_OWNS`) on any `TeacherOwned` subject; always answer 404, never 403: `#[IsGranted(TeacherOwnsVoter::ATTRIBUTE, subject: 'course', statusCode: 404)]`.
- `Service\Registration::register(RegistrationData): Teacher` (throws `Exception\EmailAlreadyRegistered`; audit `teacher.registered`). `Teacher::getDisplayName()` is the name for the sidebar and public pages.
- `security.yaml` access_control is deny-by-default: a new public route needs its own `PUBLIC_ACCESS` line above the final `ROLE_TEACHER` rule.
- Functional tests: `use App\Tests\Functional\LogsInTeacher;` + `#[ResetDatabase]`. `logInTeacher($client, ?Teacher)` logs a (factory) teacher into `main`; call it after `createClient()` (booting the kernel first makes `createClient()` throw).
- D-030: every test that submits the login form calls `resetLoginThrottling($client)` first (the limiter lives in the filesystem `cache.rate_limiter` pool, not in the rolled-back DB).
- Form validation messages are keys in `translations/validators+intl-icu.en.yaml` (the validator translates in `validators`, not `account`), prefixed with the module: `account.register.*`.
- Logout is `templates/account/_logout_form.html.twig` (POST, `logout` CSRF token; renders nothing when anonymous). Included in the sidebar footer only; #11 includes it on `/account` for phones.
- Catalogue strings around a link are split into keys (`before`/`link`/`after`) so templates need no `|raw`.
