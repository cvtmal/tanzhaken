---
paths:
  - "src/Credits/**"
  - "tests/**/Credits/**"
  - "templates/student/_credits.html.twig"
  - "templates/course/_roster_balance.html.twig"
  - "translations/credits+intl-icu.en.yaml"
---

# Module `Credits`

- **Holds:** `CreditGrant`, the balance read model `App\Credits\ReadModel\Balances` (the only implementation of the balance formula) and the renewal state.
- **May depend on:** Courses, Students, Account, Shared.
- **Owning cycles:** #3 (`CreditGrant`), #6 (ledger, `Balances`), #12 (pages).
- **Routes (S5):** none of its own in S5; grant and void POST routes are #12's choice under the student/course prefixes.
- **Tables (S3):** `credit_grant` (#3, #6 add-only). The balance is never stored.
- **Templates:** `templates/student/_credits.html.twig`, `templates/course/_roster_balance.html.twig`.
- **Translation domain:** `credits` (`translations/credits+intl-icu.en.yaml`).
- **Hotspot slots:** fills `student/_credits.html.twig` and `course/_roster_balance.html.twig`.

Namespace `App\Credits\<Layer>`. Cycles append notes for this module below.

## #3 notes (domain-model)

- `Entity\CreditGrant(Enrollment, \DateTimeImmutable $now, int $quantity = CreditGrant::DEFAULT_QUANTITY, ?string $note = null)` (default 10); `void($now)` (throws when already voided). A voided grant stays as a row.
- `Repository\CreditGrantRepository::findOwned(Teacher, Uuid): ?CreditGrant`. Exception: `Exception\InvalidCreditGrant`. The balance read model is #6 (`ReadModel\Balances`).

## #6 notes (credit-ledger)

- **`ReadModel\Balances` is the only balance formula** (credits of non-voided grants − `present` at non-cancelled lessons of the enrollment's course). Never recompute it in SQL, PHP or Twig; ask it. One query per call, filtered on the teacher: `forEnrollment(Teacher, Uuid): ?EnrollmentBalance`, `forCourse(Teacher, Uuid): array<string, EnrollmentBalance>` (keyed by enrollment id, left ones included), `forStudent(Teacher, Uuid): list<…>` (left ones included), `renewalNeeded(Teacher): list<…>` (active, balance ≤ 0, for #17).
- `ReadModel\EnrollmentBalance` (`final readonly`: ids, names, `active`, `credits`, `used`, `balance`, `state`, `outstanding()`); `ReadModel\RenewalState` (`RenewalNeeded` ≤ 0, `Low` 1–`LOW_MAX` (2), `Ok`).
- **`Service\CreditLedger`**: `grant(Teacher, Uuid $enrollmentId, int $quantity = 10, ?\DateTimeImmutable $grantedAt = null, ?string $note = null)`, `void(Teacher, Uuid $grantId, ?string $reason = null)`, `correct(Teacher, Uuid $grantId, int $quantity, ?$grantedAt, ?$note, ?$reason)` (void + new grant, one transaction). Audit actions `credit_grant.created` / `credit_grant.voided`.
- Exceptions: `Exception\CreditNotFound` → answer 404; `Exception\InvalidCreditGrant` → form error. Every refusal is thrown **before** `wrapInTransaction()`, so the EntityManager stays open for a form re-render.
- Until a controller injects them (#12, #17), the compiled container removes `CreditLedger`, `Balances` and `AuditLogger`; tests build them by hand from container parts (`tests/Integration/Credits/*Test.php`).
