---
paths:
  - "migrations/**"
  - "src/**/Entity/**"
  - "src/**/Repository/**"
  - "src/**/ReadModel/**"
  - "src/Shared/Doctrine/**"
---

# Database (S3)

- Singular snake_case tables: `teacher`, `student`, `course`, `enrollment`, `lesson_session`, `credit_grant`, `attendance`, `check_in_window`, `audit_event` (#3 creates them; `password_reset_request` is #11's).
- IDs are UUIDv7 (`symfony/uid`), created in the constructor, Doctrine type `uuid`.
- Every timestamp is UTC `datetime_immutable`. Code reads the time from `ClockInterface`, never `new \DateTimeImmutable()`. Lesson times derive from the course's `timezone`, `weekday`, `local_start_time` and `duration_minutes`.
- Nothing is hard-deleted except by account deletion (#18): archive (`archived_at`, `left_at`), void (`voided_at`), cancel.
- Constraints live in the database: a foreign key on every relation and the unique keys listed in S3.
- The balance is never stored.
- **Migrations:** one file `VersionYYYYMMDDHHMMSS`, named from the wall clock when written; runs unchanged on SQLite and MariaDB (branch on the platform where SQL must differ); always a `down()`. Only add columns or indexes to the tables your cycle's **Schema** column names.
- SQLite runs with `foreign_keys=ON`, `busy_timeout=5000` and WAL, set on connect by `App\Shared\Doctrine\SqliteConnectionMiddleware`.
