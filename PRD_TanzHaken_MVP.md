# Product Requirements Document — TanzHaken (working name)

**Version:** 1.0 · **Status:** Ready for MVP implementation · **Date:** 2026-11-10

> **Working name only.** The availability of `tanzhaken.ch` has **not** been confirmed. The branding/domain configuration should be replacable later. The product is not dependent on its name.

## 1. Executive summary

TanzHaken is a lightweight, mobile-first web application for independent dance teachers to replace printed attendance sheets. A teacher creates recurring dance classes, maintains a roster of students, issues lesson credits (usually in packs of 10), and opens a short-lived QR-code check-in after each class. Students scan the QR code, identify themselves from that class's roster, and confirm they attended. The application records attendance and shows the student's remaining prepaid lesson balance. Teachers can review, correct, and export the register.

**Product promise:** Set up a class in minutes; check in after class in seconds; always know who attended and who needs another 10-lesson pass.

**Guiding principle:** Build the smallest useful, maintainable product. Do not build a dance-school ERP, booking platform, payment processor, or SPA.

## 2. Target users and permissions

- **Teacher (authenticated):** A self-employed dance teacher. Signs up with email and password. Owns courses, students, enrollments, lesson sessions, credit entries, and attendance records. One account represents one teacher; no organizations or staff roles in MVP.
- **Student (unauthenticated):** A person listed in the teacher's course roster. Does not register, log in, manage a password, or install an app. Can only self-check-in through a currently valid lesson-specific QR link. Cannot browse the teacher's dashboard or personal financial data.

Multiple teachers may sign up on the same hosted application, but each teacher's data is strictly isolated. **No cross-teacher student identity or global student directory.**

## 3. Goals and success criteria

1. Teacher can register, create a course, add a student, and issue a 10-lesson pass without support.
2. Teacher can create a QR code for a completed lesson and show it on a phone, tablet, or projected screen.
3. Student can scan the code, choose their name, and confirm attendance in **three taps or fewer after scanning** (excluding camera permission).
4. A check-in is recorded at most once per student per lesson.
5. Teacher immediately sees per-student balance for the course and whether renewal is due.
6. Teacher can correct mistakes without losing historical accountability.
7. App works well on common mobile browsers and remains pleasant on desktop.

## 4. MVP scope

### 4.1 Teacher account

- Self-service registration with name, email, password.
- Login, logout, forgot/reset password using standard Symfony security components and emailed single-use, expiring reset links.
- Teacher account settings: display name, password, default timezone (`Europe/Zurich`).
- All teacher screens require an authenticated session.

### 4.2 Student directory

- Create, edit, archive a student with first name and last name. Optional email and notes **for teacher use only**; email not required.
- A student belongs to exactly one teacher; can be enrolled in multiple courses belonging to that teacher.
- Add/remove a student to/from a course. Removing enrollment must preserve past attendance and credit history.
- Student records do not get user accounts in MVP.

### 4.3 Courses and lesson sessions

- Create/edit/archive a course with name (e.g. “Salsa Beginners”), optional description/location, default weekly weekday, local start time, duration, start date, and optional end date.
- On creation, generate explicit lesson occurrences for a configurable number of weeks, default **26** (or until the specified end date). Provide a simple **Extend schedule** action; do not require background jobs or generate infinite sessions.
- Allow teacher to add an individual lesson, cancel a lesson, and edit the date/time of an individual lesson without changing the whole series.
- Show a chronological list of sessions with status `scheduled`, `completed`, or `cancelled`; a cancelled lesson cannot accept check-ins.
- Store occurrence timestamps in UTC; generate weekly schedules in the course's IANA timezone to preserve the correct local hour across DST transitions.
- A session is `completed` when the teacher explicitly completes it or opens check-in; do not silently auto-check-in anyone.

### 4.4 Credits / 10-lesson passes

- Teacher may add a credit entry to a **student enrollment in a specific course**, with a positive integer quantity (default 10), effective date, and optional internal note (e.g. “10er-Abo, cash received”).
- This is **manual bookkeeping only**: no checkout, online payments, invoicing, prices, tax accounting, or automatic billing.
- **Balance = sum of credits issued to the enrollment − count of its `present` attendances at non-cancelled lessons.**
- Attendance consumes one credit conceptually. Do **not** maintain a mutable balance counter, allocate check-ins to specific physical passes, or implement credit-expiration rules in MVP.
- Check-in remains possible at zero or negative balance; the teacher sees `Renewal needed` / outstanding lessons. Example: credits 10, attendances 11 → balance **−1**. Adding a new pack of 10 → balance **9**.
- A teacher can correct or void an incorrectly added credit entry. Changes must be auditable.
- Balances are isolated per enrollment/course; no credit sharing between courses in MVP.

### 4.5 QR-code self-check-in — primary feature

1. After a lesson, teacher visits that lesson and presses **Open check-in**.
2. App creates a cryptographically random, unguessable lesson-specific check-in token, activates it for **60 minutes** by default, and displays a large QR code plus copyable HTTPS URL. Teacher can close it early or reopen with a newly rotated token.
3. Student scans the QR code with the native phone camera. Mobile browser opens a minimal page showing teacher name, class name, and lesson date/time.
4. Student sees the enrolled student roster as **first name + last initial** (or a teacher-configurable short display label where needed to disambiguate duplicates). Student selects their own name, then presses **Confirm attendance**.
5. Server verifies that the lesson, token, time window, course enrollment, and request are valid, then creates/updates the attendance to `present` exactly once.
6. Student sees a clear success screen: “Attendance recorded” and an optional **remaining lessons** count for *that student and course only*. On repeat submission, show “Already checked in” without further deduction.
7. Teacher's roster updates on page refresh; teacher can use a lightweight, optional polling refresh while the QR screen is open, without WebSockets.

**MVP trust model / explicit compromise:** Like signing a paper attendance sheet, selecting a name does **not** cryptographically prove identity or physical presence. A student could select another student's name or share the QR URL. For a low-stakes, small-class MVP, accept that risk and give teachers the ability to audit/override. The public page must disclose no student contact information, notes, other students' balances, or pass history. A future opt-in private per-student code can provide stronger identity assurance without student accounts, but is deliberately **out of scope**.

Security requirements for QR check-in:

- QR URL contains only a high-entropy opaque token, never a student ID or sequential session ID that grants access on its own.
- Generate at least **128 bits** of random entropy (prefer 256); hash tokens at rest when practical.
- Token expires server-side; expiry and revocation enforced on every request; generating a new token invalidates the old one.
- Public check-in endpoints are rate-limited, use origin-appropriate CSRF protection or equivalent request-integrity controls, and must never leak another student's attendance or balance.
- Success/error pages and public QR check-in routes should be `noindex` and avoid third-party tracking scripts.
- A token is bound to exactly one lesson and one teacher.
- Duplicate requests, retries, double taps, and concurrent submissions must not create multiple attendance records; enforce a **DB unique constraint** on `(lesson_session_id, student_id)`.
- Protect against token reuse through check-in close/expiry. Do not represent QR check-in as proof of physical proximity.

### 4.6 Teacher attendance register

- Lesson detail shows roster, each student's `Not marked`, `Present`, or `Absent` state, and the student's current credit balance.
- Teacher can manually set present/absent, remove an erroneous attendance mark, and add an optional correction reason.
- Student view shows all sessions attended or marked absent, credits added, and calculated balance per course.
- Changes are immediately reflected in calculated balances. An `Absent` entry does not consume a credit.
- Course view highlights students at balance **≤ 0**; optional second warning state at balance **1–2**.
- Offer a simple **CSV export** of a course register (date/time, student, status, balance summary). UTF-8 with BOM is acceptable for compatibility with Excel; protect spreadsheet exports from formula injection.

### 4.7 Responsive UI

- **Mobile first**: big touch targets (≥44 px), short forms, clear typography, readable contrast, no hover-only actions, minimal navigation.
- Teacher mobile navigation: Dashboard / Courses / Students / Account. Dashboard shows today's and upcoming lessons, a prominent **Open check-in** action where appropriate, and renewal alerts.
- Desktop: centered, spacious content area with course cards, a compact top/side navigation, a readable attendance table, and a large presentable QR view.
- Student check-in page must be fast, uncluttered, accessible, and usable with one hand. No app installation or account required.
- Support English UI in MVP; code should use Symfony translation conventions so German can be added later. Dates/times localized, timezone clear.
- Browser-only application; optional “Add to Home Screen” / PWA polish is future scope, not a dependency.

## 5. Essential user journeys and acceptance criteria

### Journey A — First teacher setup

**Given** an anonymous visitor, **when** they register, log in, create “Salsa Beginners — Mondays 19:00”, add Alex and Sam, and assign 10 credits to Alex, **then** they can see the new course, its generated lesson dates, two enrollments, and Alex's balance of 10.

### Journey B — Student check-in

**Given** a session with open and unexpired QR check-in and Alex enrolled, **when** Alex scans the QR, selects their label, and confirms, **then** exactly one `present` attendance exists and Alex's balance decreases from 10 to 9.

### Journey C — Duplicate submission

**Given** Alex already checked in, **when** Alex submits again (including concurrent requests), **then** no duplicate is created, balance stays 9, and the page returns “Already checked in.”

### Journey D — Expired or invalid QR

**Given** a QR token is expired, revoked, malformed, or points to a cancelled lesson, **when** it is opened or submitted, **then** the server rejects check-in with a helpful public message; no attendance or credit change occurs.

### Journey E — Renewal threshold

**Given** Alex has received 10 credits and attended 10 lessons, **then** balance is 0 and the teacher sees “Renewal needed.” If Alex attends an 11th lesson, attendance is still accepted and balance becomes −1. If the teacher then grants 10 credits, balance becomes 9.

### Journey F — Teacher correction

**Given** Alex was marked present by mistake, **when** the teacher changes that record to absent, **then** the counted attendance reduces by one, balance increases by one, and the correction is logged.

### Journey G — Multi-teacher isolation

**Given** Teacher A and Teacher B have separate accounts, **when** Teacher A guesses or requests Teacher B's URLs/IDs, **then** every private cross-tenant read and write is denied. Public QR URLs reveal only the intentionally minimal lesson/check-in details while valid.

### Journey H — Scheduling and DST

**Given** a course starts at 19:00 Europe/Zurich and weekly sessions are generated across the summer/winter-time boundary, **then** all lessons remain at 19:00 Zurich local time, with correctly adjusted UTC timestamps.

### Journey I — Responsive operation

**Given** an iPhone/Android-size viewport or a desktop browser, **when** teacher and student complete their key workflows, **then** no horizontal scroll is required in normal forms and each key action is visually clear and keyboard-accessible.

## 6. Recommended data model (minimal, relational)

The following model is guidance, not a mandate for a particular ORM mapping. Use UUIDs or integer primary keys consistently; opaque public tokens must not rely on sequential IDs.

| Entity | Essential fields / relationships | Rules |
|---|---|---|
| `Teacher` | id, name, email, password_hash, timezone, created_at | Unique normalized email; owns all private data |
| `Student` | id, teacher_id, first_name, last_name, optional email/notes, archived_at | Tenant-owned; no login |
| `Course` | id, teacher_id, name, description?, location?, timezone, weekday, local_start_time, duration_minutes, starts_on, ends_on?, archived_at | Weekly template |
| `Enrollment` | id, course_id, student_id, optional public_checkin_label, joined_at, left_at? | Unique `(course_id, student_id)`; preserves history |
| `LessonSession` | id, course_id, starts_at_utc, ends_at_utc, status, cancelled_at? | Unique `(course_id, starts_at_utc)` initially; date changes must remain conflict-safe |
| `CreditGrant` | id, enrollment_id, quantity, granted_at, note?, voided_at? | Positive quantities; teacher-managed ledger |
| `Attendance` | id, lesson_session_id, student_id, status (`present`/`absent`), source (`student_qr`/`teacher`), marked_at, updated_at | Unique `(lesson_session_id, student_id)`; must match course enrollment |
| `CheckInWindow` | id, lesson_session_id, token_hash, opened_at, expires_at, closed_at? | One active window per lesson; rotation revokes prior window |
| `AuditEvent` | id, teacher_id, actor_type, action, entity_type, entity_id, occurred_at, details_json | Minimal immutable record for manual corrections and credit changes |

**Derived view:** Enrollment balance = active `CreditGrant.quantity` total − number of `Attendance.status = present` records for non-cancelled sessions in the enrollment's course. Document a consistent policy for corrections to cancelled sessions; recommended behavior: cancelling a lesson with attendance requires the teacher to resolve/clear those attendance records first.

**Deletion rules:** Prefer archiving teachers' courses/students/enrollments and voiding credit entries to destroying historical records. Teacher account deletion must have an explicit data-deletion path. Keep all database-level relationships constrained with foreign keys.

## 7. Technology and architecture requirements

### Backend

- **Symfony 7.4 LTS** (PHP **8.2+**; select a supported hosting-compatible PHP version, e.g. 8.3/8.4).
- Server-rendered **Twig** templates for all pages; **Vanilla JavaScript** for QR view, check-in enhancement, simple modals, form interactions, and optional status polling.
- Use **Doctrine ORM / DBAL** with schema migrations; make query behavior portable across SQLite and MySQL/MariaDB.
- **SQLite** as zero-config development and preferred small-instance deployment database. Configurable `DATABASE_URL` allows **MySQL/MariaDB** without changes to domain logic. **No PostgreSQL dependency.**
- Prefer Symfony Security, CSRF, Validator, Forms (if useful), Mailer, and built-in rate limiting; avoid third-party admin panels, heavy frontend frameworks, queues, search engines, or paid hosted services.
- Generate QR as an SVG server-side or via a small, well-maintained JS library bundled locally (no runtime CDN dependency). QR contains only the HTTPS check-in URL.
- Conventional controllers, application services for attendance/check-in and balances, Doctrine repositories, and Twig templates. Avoid microservices, CQRS/event sourcing frameworks, generic plugin systems, and unnecessary abstractions.
- Public and teacher-facing routes should follow conventional Symfony routing; teacher endpoints use authorization checks for `teacher_id` ownership, including nested resources.

### Frontend

- Semantic HTML5, CSS with custom properties/design tokens, progressive enhancement, small ES modules. No React, Vue, Angular, SPA router, Node build pipeline, or UI kit required for MVP.
- Use responsive CSS grid/flexbox, sensible typography, an attractive restrained color palette, lightweight icons, obvious success/error state, and mobile touch interactions.
- Server-rendered forms should remain usable when possible without JavaScript; QR code rendering and optional live updates can enhance them.

### Hosting and deployment

- One low-cost PHP web host/VPS, HTTPS, SMTP or transactional email delivery for password reset, filesystem-backed sessions as suitable for one server.
- SQLite DB stored **outside web root**, with restricted permissions and automated backup. Avoid SQLite on networked filesystems. Enable foreign keys; evaluate WAL mode when supported by the deployment filesystem.
- Provide `.env.example`, Composer setup, Doctrine migration commands, `APP_SECRET` setup, web-server document root (`public/`), backup/restore notes, and production configuration guidance.
- No Redis, background worker, cron job, message bus, Docker, or CDN should be required for core function. Docker may be provided optionally for local development, but not necessary.

### Performance expectations

- Optimized for one freelance teacher with e.g. 5–30 courses, up to a few hundred students, and dozens of concurrent scans at most, **not** infinite horizontal scalability.
- Normal teacher dashboard and student check-in should feel responsive on 4G. Avoid N+1 queries on rosters and balances; add appropriate indexes.
- Check-in must be robust under concurrent submissions and SQLite's single-writer semantics. Use database transactions and uniqueness constraints; do not rely on read-then-insert logic without a race-safe write strategy.

## 8. Security, privacy, and accessibility

- HTTPS only in production; secure, HttpOnly, SameSite cookies; hashed passwords; CSRF defense on teacher mutations; login rate limiting; reset token expiry; input validation and output escaping.
- The teacher must be authorized against tenant ownership at both controller and application service layers where appropriate; tests must cover IDOR and cross-tenant access.
- Limit public QR responses to teacher display name, class/session details, and minimal enrollment labels. Do not expose other students' credit balances, email, or notes.
- Apply public QR request throttling by IP/token; use unpredictable tokens and expiry. Referrer policy should avoid leaking check-in URLs to external origins.
- Provide a minimal privacy statement for Swiss FADP obligations: controller/contact, purpose, categories of data, data storage region, retention, deletion, and student inquiries. For MVP, keep student personal data minimal; don't use third-party analytics by default.
- Accessibility target: WCAG 2.2 AA in core flows where practical; semantic labels, focus indications, error summaries, keyboard usability, contrast, and reduced-motion preference.
- Product should not promise that QR check-in proves attendance or identity. Teacher retains final authority over the register.

## 9. Explicitly out of scope for MVP

- Student registration, login, passwords, account recovery, personal dashboards.
- Student-initiated course booking, seat reservations, cancellations, waiting lists.
- Stripe/TWINT integration, e-commerce, automatic payment collection, invoicing, VAT, accounting.
- Notifications, push messages, calendar integrations, marketing emails, messaging/chat.
- Multiple teacher roles per studio, team permissions, franchises, white-labeling.
- Offline synchronization, native iOS/Android apps, required PWA installation.
- Mandatory personal student codes, biometrics, GPS verification, anti-fraud identity proof.
- Arbitrary complex recurrence rules and cross-course credit sharing.
- Multi-language UI beyond English at launch (design for future German support).
- High-availability clustered architecture, global scale, microservices, analytics dashboards.

## 10. Build sequence for Claude Code

**Phase 1 — Foundation:** Create Symfony project; configure SQLite + MySQL/MariaDB portability, Doctrine migrations, teacher registration/login/reset, layout/design system, authorization and baseline tests.

**Phase 2 — Core CRUD:** Students, courses, enrollments, weekly lesson occurrence generation and extension, course/student views.

**Phase 3 — Credit ledger:** Credit grants, computed balances, renewal indicators, and tests for 10 → 0 → −1 → 9.

**Phase 4 — Attendance and QR:** Teacher attendance register, open/close/rotate check-in window, QR page, public student check-in, idempotency/concurrency handling, rate limiting, and related tests.

**Phase 5 — Polish:** Responsive design, CSV exports, account data deletion, accessibility, error states, privacy page, deployment documentation, and smoke tests.

### Definition of done

- All acceptance journeys A–I pass.
- Automated unit tests cover credit math, schedule generation, expiry/rotation, duplication, cancellation, and tenant boundaries.
- Functional tests cover teacher auth, public check-in, manual corrections, negative balance, and cross-tenant protection.
- Run tests on SQLite; validate migrations and core flows against MySQL or MariaDB before advertising portable production support.
- Manual smoke test on recent Safari iOS, Chrome Android, and Chrome/Firefox desktop.
- `README.md` documents local startup and deployment; `.env.example` has no secrets.
- App can be deployed as one Symfony/PHP process with SQLite and no paid infrastructure dependencies.

## 11. Concrete implementation examples

**Example balance:** Enrollment in “Salsa Beginners”: Teacher adds 10 lesson credits. Student checks in at 9 lessons, balance 1. Student checks in a 10th time, balance 0 / renewal needed. Student checks in an 11th time, balance −1 / 1 lesson outstanding. Teacher adds another 10 credits, balance 9.

**Example URLs (illustrative):**

- `/register`, `/login`, `/logout`, `/forgot-password`
- `/dashboard`, `/courses`, `/courses/{id}`, `/courses/{id}/lessons/{lessonId}`
- `/students`, `/students/{id}`
- `/lessons/{id}/check-in` (authenticated teacher QR management)
- `/c/{opaqueToken}` (public student check-in)

**Working-name choices (none confirmed available for registration):** `tanzhaken.ch` (directly evokes checking off dance attendance), `kurshaken.ch` (broader classes), `tanzstempel.ch` (stamping a 10-lesson pass). Confirm via SWITCH's official domain-check service *before* committing branding. An unindexed website is not proof a domain is unregistered. `.ch` registrations are paid even if the domain is currently available.
