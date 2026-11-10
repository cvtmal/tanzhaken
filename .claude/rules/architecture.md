---
paths:
  - "src/**"
  - "tests/**"
---

# Architecture (S2)

- Module-first: `src/<Module>/<Layer>/`, namespace `App\<Module>\<Layer>`. Layers: `Controller`, `Entity`, `Repository`, `Service`, `ReadModel`, `Form`, `Security`, `Twig`, `Console`, `Exception`.
- Module dependencies follow the table in `CLAUDE.md` § Module map. `Shared` is open to every module and depends on none; it refers to a teacher by `Uuid`, never by `Teacher`.
- A module that needs a fact owned by a module it may not depend on reads it with a DBAL query of its own.
- **Writes:** controller → one `final` service method that validates, checks ownership, changes entities inside a transaction and writes the audit event.
- **Reads:** lists and tables come from a `ReadModel` class with one DBAL query that returns `final readonly` view objects. No N+1.
- The balance has one implementation: `App\Credits\ReadModel\Balances`. Nobody recomputes it.
- No command or query bus, no event sourcing, no plugin system.
- Enforcement: phpat rules in `tests/Architecture/` run inside `make lint`. They are owned by #0; a needed change goes to the CTO.
