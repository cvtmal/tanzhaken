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

## #12 notes (credits-ui)

- **Routes** (`Controller\CreditController`, `ROLE_TEACHER`, UUID requirements, GET page + POST → 303 with a `success` flash, 422 on an invalid form or a ledger refusal): `credit_grant` `/courses/{id}/enrollments/{enrollmentId}/credits`, `credit_void` `/students/{id}/credits/{grantId}/void`, `credit_correct` `/students/{id}/credits/{grantId}/correct`. Foreign, unknown, mismatched, inactive (grant) or voided (void/correct) → 404. Template variables: the class docblock.
- **Return target:** `?from=course|student|dashboard` (`Controller\ReturnTarget::fromQuery()`, hidden `from` field); any other value gives the default (grant → course, void/correct → student). Never redirect to a URL from the request.
- **Slots fetch their own data:** the `only` slots (`student/_credits`, `course/_roster_balance`, `course/_card_badges`) call the Twig functions of `Twig\CreditsExtension`: `credits_student(studentId)` → `{balances, history}`, `credits_course_balance(courseId, enrollmentId)`, `credits_renewal_count(courseId)`, `credits_course_open(courseId)`. Ids as `Uuid` or RFC 4122 string. Each memoises one query per course / student / teacher for the request (keyed by teacher too, cleared by `kernel.reset`); a non-`Teacher` user gets empty results. Do not call `Balances` per row from Twig.
- `ReadModel\CreditHistory::forStudent(Teacher, Uuid): array<string, list<GrantRow>>` (by enrollment id, newest first, voided included).
- **CSS hooks** (`assets/styles/credits/credits.css`): `credit-page`, `credit-form` (`__danger` for the void button), `credit-change` (Now → After, `data-credit-*` for `assets/js/credit-amount.js`), `credit-amount` (`__other`), `credit-summary`, `credit-accounts` / `credit-account`, `credit-history`, `roster-balance` (row tint via `.roster-row:has(.balance--danger|--low)` below 64rem), `renewal-add` (dashboard "+10").
- `InvalidCreditGrant` has no translation key; `CreditController::addRefusal()` maps it to `credits.error.*` by comparing messages. Add a key to the exception when you next touch it.
