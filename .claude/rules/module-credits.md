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
