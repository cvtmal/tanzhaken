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
- Deleting a teacher (`DELETE FROM teacher`, #18 only) cascades to every table. No firewall or `PasswordUpgraderInterface` yet (#4).
