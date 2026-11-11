# #06 credit-ledger — outcome (2026-11-11)

## Delivered
The Credits backend: `App\Credits\Service\CreditLedger` (grant, void, correct, each audited) and `App\Credits\ReadModel\Balances`, the only implementation of the balance formula (credits of non-voided grants − `present` attendances at non-cancelled lessons of the enrollment's course; never stored). Each read is one DBAL query, filtered on the teacher. There is also the S9 renewal state, plus unit and integration tests covering the PRD §11 sequence, the exclusions, tenancy and a constant query count. No route, template, translation, migration or config change.

## Interfaces
- **`Balances`** (`src/Credits/ReadModel/Balances.php`, injects `Doctrine\DBAL\Connection`). A foreign or unknown ID gives `null` / `[]`:
  - `forEnrollment(Teacher, Uuid $enrollmentId): ?EnrollmentBalance`, for one badge or the grant form (#12).
  - `forCourse(Teacher, Uuid $courseId): array<string, EnrollmentBalance>`: every enrollment of the course, **left ones included**, ordered last name, first name. Keyed by enrollment id (RFC 4122) for `course/_roster_balance.html.twig` (#12).
  - `forStudent(Teacher, Uuid $studentId): list<EnrollmentBalance>`: every enrollment of the student, left ones included, ordered by course name (#12).
  - `renewalNeeded(Teacher): list<EnrollmentBalance>`: active enrollments (not left, course and student not archived) with balance ≤ 0, lowest first, then course name and last name (#17).
- **`EnrollmentBalance`** (`final readonly`): `enrollmentId`, `courseId`, `courseName`, `studentId`, `studentFirstName`, `studentLastName`, `active` (false = left), `credits`, `used`, `balance`, `state`; `outstanding(): int` = `max(0, −balance)` ("N lessons outstanding").
- **`RenewalState`** (backed enum): `RenewalNeeded = 'renewal_needed'` (≤ 0), `Low = 'low'` (1–`LOW_MAX` = 2), `Ok = 'ok'`; `fromBalance(int)`.
- **`CreditLedger`** (`src/Credits/Service/CreditLedger.php`):
  - `grant(Teacher, Uuid $enrollmentId, int $quantity = 10, ?\DateTimeImmutable $grantedAt = null, ?string $note = null): CreditGrant`
  - `void(Teacher, Uuid $grantId, ?string $reason = null): void`
  - `correct(Teacher, Uuid $grantId, int $quantity, ?\DateTimeImmutable $grantedAt = null, ?string $note = null, ?string $reason = null): CreditGrant` (returns the new grant)
  - `REASON_MAX = 255`.
- **Audit** (actor `teacher`, entity type `credit_grant`): `credit_grant.created` `{quantity, granted_at ("Y-m-d H:i:s" UTC), corrects?}`; `credit_grant.voided` `{quantity, reason?, replaced_by?}`. `corrects` / `replaced_by` hold the other grant's RFC 4122 id.
- **Exceptions:** `App\Credits\Exception\CreditNotFound` (`enrollment(Uuid)`, `grant(Uuid)`): answer 404. `InvalidCreditGrant` gained `grantedInFuture()`, `enrollmentNotActive()` and `reasonTooLong(int)`, besides `quantityNotPositive`, `noteTooLong` and `alreadyVoided`: show these as form errors.

## How to use it
- #12 / #17: inject `Balances` and `CreditLedger` into the controller. That makes them (and `AuditLogger`) reachable, so the compiled container keeps them; no config is needed. Catch `CreditNotFound` → 404 and `InvalidCreditGrant` → form error. The EntityManager is still open after a refusal, so re-rendering the form works.
- Anyone needing a balance (pages, CSV #15, dashboard #17, check-in #14): ask `Balances` and never recompute the formula. Use `forCourse()` for a roster, so the page makes no N+1.
- Tests: `tests/Integration/Credits/{BalancesTest,CreditLedgerTest}.php` build both services by hand from container parts (`balances()` / `ledger()` helpers).

## Decisions
- D1/D-026: `CreditNotFound` lives in the Credits module; nothing was added under `src/Shared`. D6/D-026: tests build services by hand, with no `config/services*.yaml` change.
- D2: `grantedAt` defaults to now, is converted to UTC and refused if it lies in the future. D3: grant and correct are refused on a left enrollment or an archived course or student. Void is always allowed, so a mistake can be undone.
- D4: the reason is trimmed (empty → null) and capped at 255 characters. The `reason` key is left out of the audit details when no reason is given.
- D5: an enrollment with no grant has balance 0 → "Renewal needed" and appears in `renewalNeeded()`.
- D7: no migration and no index. EXPLAIN shows every subquery is an index search.
- P1 (now in S2, D-027): every refusal is thrown before `wrapInTransaction()`, because ORM 3 closes the EM on any exception inside it. To undo: move the checks inside if the ORM ever stops closing the EM.
- P2: `Balances::hydrate()` computes `balance` and `state`. P3: every ORDER BY ends with the enrollment id. P4: `correct()` uses private helpers, so it is one transaction with exactly two audit rows.

## Deviations
- The plan's private `assertGrantable()` and `effectiveAt()` are merged into one private `newGrant()`. Same checks, same order.
- `'present'` is a SQL literal because Credits may not import `App\Attendance`. The cancelled status is bound from `LessonStatus::Cancelled`.

## Carried forward
- Two concurrent voids of one grant could both pass the check (read-then-write). A conditional UPDATE would fix it → #19.
- `tests/Integration/Shared/MainStoryTest.php` (#3) checks seed balances without the cancelled-lesson filter. It could assert through `Balances` instead → #21/#23.
- `CreditLedger`, `Balances` and `AuditLogger` stay removed from the compiled container until a consumer injects them → #12, #17.
- The SQL has not yet run on MariaDB (it casts integers for PDO strings) → #21.
- Review note: the ORDER BY on names sorts case-sensitively on SQLite and case-insensitively on MariaDB; check when the suite runs there → #21.
- Review F-m1: `CreditLedgerTest::teacherOf()` uses the FQCN `\App\Account\Entity\Teacher` instead of an import; tidy on the next touch of the file.

## Verification
- `make lint`: pass. `make test`: OK, 153 tests, 734 assertions. The Credits tests (34 integration + 10 unit) cover: PRD §11 sequence 10 → 1 → 0 → −1 → +10 = 9; voided, cancelled and absent excluded; other course not counted; no-grant = 0; tenancy; ordering; left and archived filters; 2 queries for `forCourse` + `renewalNeeded` at both 3 and 30 enrollments; seed balances (Noemi Frei 2 Low, Elias Huber −1); refusals write nothing and leave the EM open.
- `make db-fresh && make seed`: exit 0. `doctrine:schema:validate`: exit 0 (no migration). The public-repo grep over the lane diff found nothing.
- Review re-run (2026-11-11, c06-review): `make lint` pass, `make test` OK (153 tests, 735 assertions); D-027 checked: every refusal is thrown before `wrapInTransaction()`.
- Backend only: no page to check at 390/1440 px. Pending human: nothing.
