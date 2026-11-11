# #15 csv-export — outcome (2026-11-11)

## Delivered
A teacher downloads one course's register as a CSV from the course page (REG-6, PRD §4.6). The file is UTF-8 with a BOM, comma-separated with CRLF line ends (RFC 4180). It holds one row per lesson and student (date, time, time zone, student, status), an empty line, then a balance summary per enrolment taken from `Balances`. Cells that a spreadsheet would read as a formula are neutralised. The "Export CSV" button fills the `course/_export_action.html.twig` slot of #10 and shows on active and archived courses. The placeholder action is gone. No schema, config, component or asset change.

## Interfaces
- **Route:** `course_export` GET `/courses/{id}/export.csv`, `id` with `Requirement::UUID`, `App\Attendance\Controller\CourseExportController` (invokable, `ROLE_TEACHER`). A foreign, unknown or malformed id answers 404.
  - Headers: `Content-Type: text/csv; charset=UTF-8`, `Content-Disposition: attachment; filename=register-<slug>-<Y-m-d>.csv`, `Cache-Control` containing `no-store, private`.
- **`App\Attendance\Service\CsvEncoder`** (final, no dependencies): `encode(iterable<list<string|int|null>> $rows): string`. Constants `BOM`, `SEPARATOR`, `EOL`, `FORMULA_PREFIXES`. `null` is an empty cell, `[]` an empty line.
- **`App\Attendance\ReadModel\CourseRegisterExport`** (final, `Connection`): `forCourse(Teacher, Uuid $courseId, \DateTimeImmutable $now): list<CourseRegisterExportRow>`, one query, `[]` for a foreign course.
  - `CourseRegisterExportRow(lessonId, startsAtUtc, studentId, firstName, lastName, ?AttendanceStatus status)`; a null status means "Not marked".
- **`App\Attendance\Service\CourseRegisterCsv`** (final): constructor `(CourseRegisterExport, Balances, CsvEncoder, TranslatorInterface, ClockInterface)`; `build(Teacher, Course): string`, `filename(Course): string`.
- **Template:** `templates/course/_export_action.html.twig`, receives `course` {id, name, timezone}.
- **Stylesheet:** `assets/styles/attendance/export.css` (imported last in `app.css`): `.course-page .page-header__actions { max-width: 100%; }`, so the header actions wrap on phones.
- **Catalogue:** `translations/attendance+intl-icu.en.yaml`, keys only under `export.*`.

## How to use it
- Any page that needs the CSV links to `path('course_export', {id: …})`; the route checks ownership itself.
- Another CSV in the app can reuse `CsvEncoder`: pass numbers as `int` so they are never prefixed, and every user-sourced value as a string.
- A `;` variant for German-locale Excel is a change of `CsvEncoder::SEPARATOR` (or a parameter on it).

## Decisions
- **D1 Which lessons:** not cancelled, and started by now or already carrying a mark; chronological. *Why:* a grid of future "Not marked" rows is noise; cancelled lessons cannot carry marks. *Undo:* drop the `starts_at_utc` condition in the SQL.
- **D2 Which students per lesson:** anyone with a mark, plus active enrolments that joined before the lesson ended. *Why:* matches #7's register rule and avoids "Not marked" before someone joined. *Undo:* drop the `joined_at` condition.
- **D3 Format (CTO D-052):** RFC 4180, comma, `"` quote, CRLF, BOM first; dates `Y-m-d`, times `H:i` in the course zone plus a "Time zone" column. *Why:* a spreadsheet must parse the values; the intl formats are for people. *Undo:* `CsvEncoder::SEPARATOR` and the formats in `CourseRegisterCsv`.
- **D4 Layout:** register header and rows, an empty line, "Balance summary", its header, one row per `Balances::forCourse()` entry (left ones as "Left"). *Why:* one file answers both "who came" and "who must renew". *Undo:* stop yielding the summary rows.
- **D5 Neutralisation:** string cells starting with `= + - @`, tab or CR get a leading `'`; int cells never. *Why:* OWASP CSV injection, while a balance of `-1` stays a number. *Undo:* `FORMULA_PREFIXES`.
- **D6 Filename and headers:** `register-<ascii slug>-<today in the course zone>.csv` (`register-<date>.csv` without a slug), `no-store, private`. *Why:* a readable name; the file holds personal data. *Undo:* `CourseRegisterCsv::filename()` and the controller headers.
- **D7 Archived course:** exports, button shown. *Why:* the register is history the teacher may need after archiving. *Undo:* a 404 for archived courses in the controller and a check in the slot.
- **D8 Button:** secondary link button "Export CSV", no icon, `download` attribute, a GET without state change. *Why:* the sprite has no download icon; S4. *Undo:* the slot template.
- **D9 Catalogue:** keys only under `export:`. *Why:* #13 creates the same file; the merge is a concatenation. *Undo:* none needed.

## Deviations
- `HeaderUtils::makeDisposition` writes `filename=register-….csv` without quotes, since the ASCII name needs none; D6 showed it quoted. The tests assert the real header.
- Symfony adds `max-age=0, must-revalidate` to `Cache-Control` because the request uses the session; the header still contains `no-store, private`, and the test checks both words.
- `fputcsv` quotes cells with a space (valid RFC 4180), so the tests parse the CSV with `fgetcsv` instead of comparing raw lines.
- `make cs-fix` renamed the methods of `CsvEncoderTest` to the project's snake_case.

## Carried forward
- **`;`-separated variant** for German/Swiss Excel → CTO / owner, on request (D3).
- **Download icon** for the button → #20 (sprite belongs to #2).
- **Audit event for an export** → #19 (S4 audits state changes only).
- **MariaDB check of the export SQL** → #21.
- **Merge note (R11):** #13 also creates `translations/attendance+intl-icu.en.yaml` and edits `src/Attendance/Controller/PlaceholderController.php` and `tests/Functional/PlaceholderRoutesTest.php`. The second lander keeps both deletions, both `SHIPPED` additions, and both key sets.

## Verification
- `make lint`: green (cs-check, phpstan level 8 + phpat, twig, yaml, container).
- `make test`: green, 671 tests, 6367 assertions. New: `CsvEncoderTest` 16, `CourseRegisterExportTest` 7, `CourseExportTest` 8; `PlaceholderRoutesTest` green.
- `make db-fresh && make seed`: green.
- Browser on the dev server, the course page in an iframe at 390, 414, 430 and 1440 px, on an active and an archived seed course (archived for the check in the dev database): `scrollWidth` equals `clientWidth` at every width (no sideways scroll). On phones "Export CSV" wraps to a second line under Edit/Add student (x=20), and sits beside "Archived" on the archived course; at 1440 px it is with the other header actions. The first build overflowed at ≤ 430 px (review F-M1); fixed by `assets/styles/attendance/export.css` (`max-width: 100%` on the course page's `.page-header__actions`, so #10's `flex-wrap` takes effect).
- `curl` with a logged-in session: status 200, first bytes `EF BB BF`, `Content-Type: text/csv; charset=UTF-8`, attachment filename `register-salsa-beginners-2026-11-11.csv`, `Cache-Control` with `no-store, private`; the archived course exports too.
- Pending human: opening the file in real Excel and LibreOffice; a real phone (G7).
