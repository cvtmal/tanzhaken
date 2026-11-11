# #12 credits-ui — outcome (2026-11-11)

## Delivered
The teacher's credit pages on top of #6's ledger. A grant page adds lessons to an enrollment (E2: Now → After panel, pass dots, 10 / 5 / Other, effective date and note behind a "Date and note" disclosure). It is reached from the roster's "+10", the student page's "Add credits" and the dashboard's "+10" (D-044), and it returns to where it came from. A void page and a correct page confirm without JS. The course roster gets a "Lessons left" column: S9 chip, a 10-segment bar from 64rem, "+10" on rows at ≤ 2 and a row ring below 64rem. Course cards show "n to renew". The student page gets a Credits section with every enrollment's balance, pass and history, voided entries struck through. One read model (`CreditHistory`), one Twig extension, six form classes, one controller, eight templates, one stylesheet, one JS module, the `credits` catalogue and 60 new tests (Journey A's end included). No migration, no config change, no change to `Balances` or `CreditLedger`.

## Interfaces
- **Routes** (`src/Credits/Controller/CreditController.php`, class `#[IsGranted('ROLE_TEACHER')]`, ids `Requirement::UUID`, GET page + POST → 303 with a `success` flash, 422 on an invalid form or a ledger refusal): `credit_grant` GET|POST `/courses/{id}/enrollments/{enrollmentId}/credits`; `credit_void` GET|POST `/students/{id}/credits/{grantId}/void`; `credit_correct` GET|POST `/students/{id}/credits/{grantId}/correct`. Query `?from=course|student|dashboard` (`Controller\ReturnTarget`). Template variables: the controller's class docblock.
- **Twig functions** (`src/Credits/Twig/CreditsExtension.php`, ids `Uuid|string`): `credits_student(studentId)` → `{balances: list<EnrollmentBalance>, history: array<enrollment id, list<GrantRow>>}`; `credits_course_balance(courseId, enrollmentId): ?EnrollmentBalance`; `credits_renewal_count(courseId): int`; `credits_course_open(courseId): bool`. Memoised per request and teacher (`ResetInterface`); a non-`Teacher` user gets empty/0/null/false.
- **Read model**: `App\Credits\ReadModel\CreditHistory::forStudent(Teacher, Uuid $studentId): array<string, list<GrantRow>>` (keyed by enrollment RFC 4122 id, newest first, voided included, one DBAL query); `GrantRow` (`final readonly`: `grantId, enrollmentId, quantity, grantedAt, note, voidedAt`, `isVoided()`).
- **Forms** (`src/Credits/Form/`): `GrantData`/`GrantType` (`amount` 10/5/other, `otherAmount` 1–100, `date`, `note`, hidden `from`; `quantity()`, `effectiveAt()`), `CorrectData extends GrantData` + `reason` / `CorrectType` (parent `GrantType`), `VoidData`/`VoidType` (`reason`, `from`).
- **Slots filled**: `student/_credits.html.twig` (#9), `course/_roster_balance.html.twig` (a `<td>`, #10), `course/_card_badges.html.twig` (#10). Hotspot edits: one `<th>` in `course/_roster.html.twig` (D-047); "+10" link in `dashboard/_renewals.html.twig` (D-044).
- **Assets/i18n**: `assets/styles/credits/credits.css` (imported in `app.css`), `assets/js/credit-amount.js` (importmap entry `credit-amount`, loaded via `page_scripts` on grant/correct); domain `credits`; constraint messages `credits.grant.*`, `credits.void.*` in `validators`. CSS hooks: `.claude/rules/module-credits.md` § #12 notes.

## How to use it
- Link to the grant page: `path('credit_grant', {id: courseId, enrollmentId: id, from: 'student'})`. A balance in a slot or partial: call a `credits_*` function, never `Balances` per row.
- #20 (sheets/dialogs): wrap the bodies of `templates/credit/{grant,void,correct}.html.twig` in `_dialog`; the forms post to the same routes.
- Tests: `tests/Functional/Credits/CreditPages.php` (`enrolled()` with a balance, paths, `balanceOf()`, `followToToast()`, `rosterRow()`).

## Decisions
- **PD1** Grant, void, correct are pages, not sheets: `only` slots cannot carry forms and `student/show` loads no dialog script; works without JS (S6). Undo: `_dialog variant: 'sheet'` once the hosts load `dialog` (#20).
- **PD2** Void/correct confirm on their own GET page with an optional reason. Undo: `_dialog` once `student/show` loads `dialog`.
- **PD3** Dashboard "+10" is a link to the grant page (`from=dashboard`), no Undo toast; Low rows on the dashboard not built. Why: flashes are text only; keeps #12 at M. Undo: see Carried forward.
- **PD4** Return target is an enum from `?from=`, never a URL (no open redirect). Undo: add a case to `ReturnTarget`.
- **PD5** Date field in the teacher's zone, `max` today; today → `grantedAt = null` (now), earlier → 00:00 local; future → ledger error on the field.
- **PD6** Amount 10 / 5 / Other (1–100); the 100 cap is form-only, against typos. Undo: drop the `Range` max.
- **PD7** Roster "+10" only on active rows at ≤ 2, course open and student not archived (no dead link). Undo: drop the condition in `_roster_balance`.
- **PD8** Row highlight via `.roster-row:has(.balance--danger|--low)`, no edit to #10's row; a ring below 64rem, none from 64rem (E3 flags by chip and bar).
- **PD9** Void/correct of a voided grant → 404; history shows it struck through, no actions. **PD10** Twig functions read the teacher from `Security`, memoise one query per id and teacher.
- Also decided: `CreditsExtension` implements `ResetInterface` (no cross-request leak in a reused kernel); `CorrectData extends GrantData` (so `GrantData` is not `final`); void/correct default to `student` as return target; "Correct" shows only where a grant is allowed, "Void" on every active entry; date and note behind a disclosure on the grant page (open on the correct page); the Other field hidden by CSS `:has()`; void button styled danger with `credit-form__danger` (no danger variant in `_button`).

## Deviations
- Plan: `credits_course_open()` loads the teacher's open course ids once per request (`findBy`) instead of `findOwned` per course — the query-count test found an N+1 on the student page. Step 4.7 done in Phase 2. Wrong CSRF answers 422 (re-render), not a silent no-op.
- `InvalidCreditGrant` (#6) has no translation key, so `CreditController::addRefusal()` matches the message of each named constructor to map it to `credits.error.*`.
- E1: no sub-lines "Owes 1 lesson" / "Last lesson on this pass" (the slot is its own `<td>`); the S9 label beside the chip says it. Mobile roster is #10's stacked card.
- E2: a page, title "Add lessons" with an avatar/name line below; selected tile is #2's solid `segmented` (E2: soft lavender); with Other empty, "After" keeps the last valid value.
- E3: chip with label + bar instead of a bare number; "+10" trails the bar in the same cell (x-position varies); no "Last here" column.
- Phase 6 copy fixes: the note and reason help no longer repeat the theme's "optional"; the correct page's owed line says "Without this entry n lessons are owed; the corrected amount covers them first."

## Carried forward
- Dashboard Undo toast after "+10" and Low (1–2) dashboard rows (a `Balances` method in #6's file, a toast with an action from a flash) → #20 (D-053).
- Void/correct as dialogs need `{% set page_scripts = ['dialog'] %}` in #9's `student/show.html.twig` → CTO call, then #20. E2 bottom sheet / desktop side panel → #20.
- E3 "Last here" roster column → #13/#20. E1-style single-card roster rows → #20.
- `_pass_dots` (#2) aria-label reads "12 of 10 lessons left" above 10 → #20. `_button` has no danger variant → #2/#20.
- `InvalidCreditGrant` needs `translationKey()` (like `InvalidCourse`) so the controller stops matching messages → #19 or the next touch of #6's file.
- The double-void race from #6 (read-then-write) is still open; the void page makes a double submit likelier → #19.
- Harness (CTO): every lane serves on 127.0.0.1, so session cookies are shared across ports; another lane's login/logout broke the browser session of Phase 5. Use `localhost`/distinct hostnames per lane or a lane-specific session cookie name.
- MariaDB run of the `CreditHistory` SQL → #21.

## Verification
- Phase 6: `make cs-fix` 0 of 296 files fixed; `make lint` pass; `make test` OK, 698 tests, 6753 assertions, 1 skipped (#9's slot-absent test); `make db-fresh && make seed` exit 0; `debug:translation en --domain=credits --only-missing` empty; no `style="`/`<script` in the cycle's templates; public-repo grep over the branch diff empty. Logs: `var/harness-logs/p6-*.log`.
- New tests: Functional `tests/Functional/Credits` 29 (Grant 10, VoidCorrect 4, Ownership 9, Badges 5 incl. equal query counts at 3 and 15 enrollments, JourneyA 1); Integration `CreditHistoryTest` 4, `CreditsExtensionTest` 5; Unit `GrantDataTest`, `ReturnTargetTest` (22 with data sets); `PlaceholderRoutesTest` `SHIPPED` += 3 routes.
- Browser (Chrome, `make serve` on 8002, demo seed; 390 px as a same-origin iframe, 1440 px window, measured with JS): course list badge, course roster (chip, bar, "+10", ring), grant page with JS (After/pass/label update, Other), grant submit → course page and toast, student credits section — no horizontal scroll, targets ≥ 44 px (`.hit-target`), focus visible. Three CSS fixes made in Phase 5. Void, correct and dashboard "+10" checked with `curl` only (cookie jar) after the browser session broke.
- Review (c12-review): make lint pass, make test OK (698 tests); browser at 390/1440 px on localhost:8002: dashboard +10 to grant and back, student history (wrapped note at 390 px), void and correct pages and submits, roster +10 at <= 2 all pass; no overflow, no console error. Course-page name links and Edit summary under 44 px (#10) -> #20.
- Pending human: real phone (G7: roster, amount tiles, Other numeric keyboard, toast above the tab bar).
