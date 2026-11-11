# #18 data-protection — outcome (2026-11-11)

## Delivered
The public privacy statement at `/privacy` (PRIV-1) and account deletion on `/account` (ACC-6), plus a purge of expired password-reset rows (c11 F-m2). The statement sits in the public layout: who is responsible (operator name, address and email from env), what the data is for, one heading per kind of stored data (ten), where it is stored (hosting region from env), how long it is kept, how it is deleted, and how students ask for their data. An empty env value shows a red "Not configured" badge; a configured value outside `prod` shows a "Placeholder" badge. No legal-compliance promise is made. On `/account` a red "Delete account" card asks for the password; with JS a confirmation dialog opens first ("Keep my account" / "Delete everything"); without JS the password alone confirms. A successful delete removes the teacher and every row they own in one statement, ends the session (and every other session of the teacher), and lands on `/login` with the info alert "Your account and all its data were deleted." Account's `PlaceholderController` is gone; no public placeholder route remains.

## Interfaces
- **Routes:** `privacy` GET `/privacy` (`App\Account\Controller\PrivacyController::show`, public); `account_delete` POST `/account/delete` (`AccountController::delete`; wrong password or missing CSRF → 422 re-render of `/account` with a field error; success → logout + `info` flash + redirect to `login`; GET → 405).
- **Service:** `App\Account\Service\AccountDeletion::delete(Teacher): void` — one DBAL `DELETE FROM teacher` inside `wrapInTransaction`, relying on `ON DELETE CASCADE` of all ten teacher-owned tables; `\LogicException` if no row was deleted; then `EntityManager::clear()`. No audit event (the audit rows are deleted with the teacher).
- **Repository:** `PasswordResetRequestRepository::deleteExpired(\DateTimeImmutable $now): int`, called by `PasswordResets::request()` after the email limiter (all teachers' expired rows).
- **Form:** `App\Account\Form\DeleteAccountType` (block prefix `delete_account`, field `password` with `UserPassword`), data class `DeleteAccountData`.
- **Templates:** `account/_delete.html.twig` (the #11 slot; card + dialog `account-delete-confirm`), `account/privacy.html.twig`, `account/_privacy_value.html.twig` (value, mailto for email, badges).
- **Styles:** `assets/styles/account/delete.css` (danger button and heading, card spacing), `assets/styles/account/privacy.css` (reading column, section rhythm, two-column `dl` from a 40rem viewport); both imported in `app.css`.
- **Env (in `.env` and `.env.example`, empty by default):** `APP_OPERATOR_NAME`, `APP_OPERATOR_ADDRESS`, `APP_OPERATOR_EMAIL`, `APP_HOSTING_REGION`.
- **Catalogues:** `account` domain `delete.*` and `privacy.*` (ten `privacy.data.<key>.heading/.text`); `validators` `account.delete.*`.

## How to use it
- Set the four `APP_OPERATOR_*` / `APP_HOSTING_REGION` variables per deploy; the statement text itself is in `translations/account+intl-icu.en.yaml` under `privacy.*` (date "Last updated" is a fixed string there).
- A new teacher-owned table must have `teacher_id … ON DELETE CASCADE`, or `AccountDeletion` leaves rows behind; add it to `tests/Integration/Account/AccountDeletionTest.php` and to the `privacy.data.*` categories.
- A future `danger` variant of `components/_button.html.twig` replaces the `account-delete__submit` class rules in `delete.css`.

## Decisions
- **OD-1 (granted, D-050):** one-line edit to #11's hotspot `templates/account/show.html.twig`: `page_scripts` gains `'dialog'`, because an included slot partial cannot load scripts. Undo: drop the dialog; the password alone confirms.
- **OD-2:** danger styling through the module stylesheet `account/delete.css`, not a new `_button` variant (#2's component stays untouched). Undo: #20 adds a variant and drops the class.
- **OD-3:** the "Not configured" badge also shows in `prod`, so a misconfigured deploy is visible on the public page instead of silent. Undo: hide the badge when `kernel.environment` is `prod`.
- Deletion is a single cascading `DELETE FROM teacher`, not a per-entity removal: one statement, nothing to forget in PHP; correctness rests on the FKs (tested on SQLite).
- An empty value shows only "Not configured"; "Placeholder" appears only next to a configured value (two badges on one empty value would be redundant).
- The ten data categories render from a loop over catalogue keys, not ten hand-written blocks.

## Deviations
- **Hotspot `tests/Functional/PlaceholderRoutesTest.php` restructured:** `privacy` was the last public placeholder, and phpstan rejected an empty `PUBLIC` constant (`function.impossibleType` on both `in_array` calls, with or without a `@var` hint). The `PUBLIC` constant, its two branches and the `teacherPlaceholderRoutes()` provider were removed; every placeholder test now logs in, and the anonymous-redirect test uses `placeholderRoutes()`. `privacy` and `account_delete` are in `SHIPPED`. A cycle that adds a public placeholder again has to reintroduce the split.
- `account.delete.*` validator keys sit inside the existing `account:` root of `validators+intl-icu.en.yaml` (a second root would be a duplicate YAML key).
- Reading width: `.privacy` asks for 40rem, but #2's `.public-main` caps the column at 30rem (440 px of text at 1440 px, about 60 characters per line at 17 px). Judged acceptable for reading; layout not changed.
- Design (README gap table, rows "Privacy page" and "Account deletion"): no drawn screen exists; the build follows the table's instruction (text page in the public layout; delete card with the confirmation-dialog pattern). The danger colour of the button and heading is ours, from the danger tokens.

## Carried forward
- #19 security: CSP check of `/privacy` and the dialog script; the CSRF "page expired" listener also for `account_delete`; consider a limiter on `account_delete` password attempts.
- #20 polish: a `danger` button variant replacing `account-delete__submit`; a wider reading column for `/privacy` if wanted (needs #2's `.public-main`); on `/account` the phone "Log out" link also shows in the main column at 1440 px (#11's markup, not changed here).
- #21 MariaDB: run `tests/Integration/Account/AccountDeletionTest.php` and the functional deletion test on MariaDB to prove the cascades there.
- #22 docs: document `APP_OPERATOR_NAME`, `APP_OPERATOR_ADDRESS`, `APP_OPERATOR_EMAIL`, `APP_HOSTING_REGION` in the README and the deploy notes.
- Owner (G5): legal adequacy of the statement text for the FADP; the agent states facts only.
- Review F-m1 → #22: `privacy.retention.links` says expired reset links "are then removed", but they are purged only on the next reset request that passes the email limiter; reword ("removed at the latest with the next reset request").

## Verification
- `make cs-fix`; `make lint` exit 0; `make test` exit 0 (537 tests, 5075 assertions), also after the `delete.css` spacing fix; `make db-fresh && make seed` exit 0 (before and after each browser delete).
- Browser (Chrome, port 8001; 390 and 1440 px via a same-origin iframe as in outcome 11): `/privacy` logged out with empty env (4× "Not configured") and with invented values in a temporary `.env.local` (4× "Placeholder", mailto link, two-column facts at 1440 px, stacked at 390 px; file removed afterwards); `/account` delete card (red heading, red button), dialog opens on "Delete account", Escape and "Keep my account" close it, wrong password → 422 with "This is not your password."; a real delete at 1440 px and again at 390 px → `/login` with the info alert, teacher rows gone. No horizontal scroll on any of these at either width.
- Fix found in the check: the delete card had no gap to the content above and no spacing after its heading and lede; `delete.css` now matches the settings cards (16 px card gap, 8 / 14 px under heading and lede, secondary ink for the lede).
- Review (2026-11-11, lane on 683b3cb): Account + Shared + placeholder suites pass (112 tests); no critical or major finding; F-m1 minor.
- Pending human: a real phone (G7); legal review of the statement (G5).
