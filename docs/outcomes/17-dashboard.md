# #17 dashboard — outcome (2026-11-11)

## Delivered

`/dashboard` (route `dashboard`, title "Today") is real: a date line and time-of-day greeting, today's lessons of all non-archived courses with one hero card carrying the page's only primary "Open check-in" button, compact cards for the other today lessons, a "Passes to renew" list (balance ≤ 0, from #6's `Balances`), and an "Upcoming" list for the next 7 days. One column on phones, two columns from 64rem (B1/B2). No POST on the page. The page makes 3 queries at any data size.

## Interfaces

- Route `dashboard` GET `/dashboard`, `ROLE_TEACHER` — `src/Dashboard/Controller/DashboardController.php` (`index()`, public static `greeting(\DateTimeImmutable, string $tz): 'morning'|'afternoon'|'evening'`). The Dashboard `PlaceholderController` is deleted; `dashboard` is in `SHIPPED` of `tests/Functional/PlaceholderRoutesTest.php`.
- `App\Dashboard\ReadModel\TodaysLessons::forTeacher(Teacher): TodayBoard` — one DBAL query (+1 `COUNT(course)` only when no lesson row comes back). Consts `UPCOMING_LIMIT = 10`, `UPCOMING_DAYS = 7`.
- `TodayBoard` (`final readonly`): `today`, `upcoming` (`list<DashboardLesson>`), `now` (UTC), `hasCourses`; `hero()`, `others()`, `canOpenCheckIn(DashboardLesson)`, `todayCourseIds(): array<string, true>`.
- `DashboardLesson` (`final readonly`): `lessonId, courseId, courseName, location, timezone, durationMinutes, startsAtUtc, endsAtUtc, status, studentCount, checkInOpen`; `CHECKIN_LEAD_MINUTES = 15`; `isCancelled()`, `canOpenCheckIn($now)`, `minutesSinceEnd($now)`, `minutesUntilStart($now)`, `isInProgress($now)`.
- Templates `templates/dashboard/index.html.twig` + `_hero`, `_today_lesson`, `_lesson_badge`, `_upcoming`, `_renewals`; CSS `assets/styles/dashboard/dashboard.css` (imported in `app.css`); catalogue `translations/dashboard+intl-icu.en.yaml`.
- No tables, columns, migrations, config or env variables.

## How to use it

- #12 (credits-ui) adds the "+10" quick grant with Undo to the renewal rows in `templates/dashboard/_renewals.html.twig` (a POST to a credits route; the dashboard itself stays read-only) and, if wanted, Low (1–2) rows through a new `Balances` method.
- #16 (checkin-teacher-ui) owns `checkin_manage` `/lessons/{id}/check-in`; the hero and compact cards link to it with GET. "Check-in open" shows when a `check_in_window` is not closed and not expired.
- #10/#13 replace the `course_new`, `course_show`, `lesson_show` placeholders the page links to; no change here needed.

## Decisions

- P1 Today = `starts_at_utc` in today of the **teacher's** zone; Upcoming = the next 7 days, ≤ 10 rows; archived courses excluded. Undo: bounds in `TodaysLessons`.
- P2 Cancelled lessons are listed with a "Cancelled" badge and no action. Undo: filter `status <> 'cancelled'`.
- P3 Check-in eligible = today, not cancelled, `starts − 15 min ≤ now`. P4 Hero = latest eligible today lesson, else the next to start. Undo: `TodayBoard::hero()`.
- P5 Badges: open window "Check-in open" (pulse; button "Show check-in"), "Now", "Ended n min ago" (< 60), "Ended at {time}"; "in n min" in the meta when < 60 min away (hero and compact).
- P6/P7 (D-044): renewal rows = `Balances::renewalNeeded()` only (balance ≤ 0); no "+10" on the dashboard. Meta "In today's {course}" when that course has a lesson today.
- P8 Greeting uses the full display name; date line `format_date('full')` (with year). P9 lesson times in the course zone, zone id appended when it differs from the teacher's.
- P10 Empty states: no course → "No courses yet" + "Create a course" (header "New course" hidden then); "No lessons today", "Nothing scheduled in the next 7 days", "No passes to renew".
- Page query count asserted as *equal* at 2 and 20 items (3 observed: user refresh, lessons, renewals), not as an absolute number.

## Deviations

- **From B1/B2:** no "+10" and no Low rows (D-044); 12-hour times (D-042); the greeting shows the full name; the date line includes the year; hero has a "View lesson" text link on phones where B1 has a chevron; the compact card says "Open lesson" (B1 "Open"); renewal rows have no chevron and show the balance chip without label; duration/location in the meta only from 64rem; "Ended n min ago" uses the plum `today` badge as in B1 (P5 said `neutral`).
- **B1's exact screen is not reachable under P4:** with a lesson that ended 2 min ago and another starting in 13 min, the 13-min lesson is inside the 15-min lead and becomes the hero. With the next lesson ≥ 16 min away, the page matches B1. Plan Step 4.3's "in 13 min / Open lesson" example contradicted P3/P4; the test uses 40 min.
- Sections are three siblings (Today, Passes to renew, Upcoming — B1's phone order) placed by a CSS grid, not the plan's schedule/people wrappers. Extra partial `_lesson_badge`.
- PlaceholderRoutesTest: the `dashboard` row also left `placeholderRoutes()` (the test's own rule). Controller uses `#[CurrentUser] Teacher`.
- Fixed after the browser check: hero now shows "in n min"; the compact card's pill wraps to its own row on phones instead of breaking the course name mid-word.

## Carried forward

- #12: "+10" quick grant with Undo on renewal rows; Low (1–2) rows need a `Balances` method (#6's file; CTO call).
- #20 (a11y-responsive-polish): the ICU narrow no-break space before AM/PM renders almost zero-width in Newsreader ("10:54AM") in the serif time; the shared `_list_row` focus ring sits flush against the row text (component of #2).
- Hero rule settled by D-048 (P4 as built: the latest eligible lesson wins over a just-ended one); changing it later is one method, `TodayBoard::hero()`.
- Review notes (minor, any later touch of the file): the renewal row joins first and last name in Twig (`_renewals.html.twig`), not through a catalogue key; `DashboardController::greeting()` is domain logic on the controller and could move to the read model.

## Verification

- Review re-run (2026-11-11, c17-review, after the rebase onto `2d2a875` with #11 and #14): `make lint` exit 0; `make test` OK (523 tests, 4946 assertions); `make db-fresh && make seed` exit 0; curl on 8004: anonymous `/dashboard` → `/login`, demo login → `/dashboard` 200 with Today / Passes to renew / Upcoming sections. Rebase conflicts were list files only (`SHIPPED` row, `app.css` import), both sides kept.
- `make lint` exit 0; `make test` exit 0 (446 tests); `tests/Unit/Dashboard`, `tests/Integration/Dashboard` (query count 1, or 2 without lessons, at sizes 3 and 30), `tests/Functional/Dashboard` (5 tests incl. constant page query count, tenancy, anonymous → /login); `debug:translation --only-missing` empty; no `style=`/`<script` in `templates/dashboard`.
- Browser (Chrome, same-origin iframe at 390 and 1440 px, lane dev DB with lessons moved to today): layouts as B1/B2, no horizontal scroll, targets ≥ 44 px (compact pill 36 px with a 44 px hit area), visible focus, every link 200, hero → `/lessons/{id}/check-in` placeholder, empty state checked.
- Pending human: real phone and a real 1440 window side by side with the PNGs; not exercised in the browser: the "Check-in open" pulse, cancelled lessons, a course in another zone, "No lessons today"/"Nothing scheduled" states, evening greeting ("Check-in open" and cancelled status are covered by the functional/integration tests; the rest only by template code).
