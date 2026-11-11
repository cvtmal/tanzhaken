# #14 checkin-public — outcome (2026-11-11)

## Delivered

The student side of the QR check-in. A student opens `/c/{token}` on a phone, taps their name on the roster (H1) and confirms; the attendance is written through #8's `PublicCheckIn::confirm()` and the page shows "You're checked in", the lesson, and the pass with the lessons left (H2), with low, zero and owed states (H3). A second scan shows "Already checked in". Every refused token (malformed, unknown, closed, expired, rotated, cancelled lesson, archived course) and every enrollment that is not on the roster gets one 404 message. A cross-site POST is refused with 403, rate limits answer 429. There is no session, no cookie and no CSRF token (D-041). Every response carries the S8 headers and meta tags. The page works without JavaScript. No migration, no new dependency.

## Interfaces

- Routes: `checkin_public` GET `/c/{token}` and `checkin_public_confirm` POST `/c/{token}/confirm` (form field `enrollment` = enrollment UUID), `App\CheckIn\Controller\PublicCheckInController`. The placeholder action is deleted.
- Status codes: 200 roster/result/"marked by teacher"; 403 cross-site POST; 404 any refusal; 422 missing or non-UUID `enrollment` (roster again with an alert); 429 + `Retry-After` (seconds, ≥ 1).
- Headers on every response: `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store, private`; meta `robots` and `referrer` in `templates/checkin/_base.html.twig`.
- Templates `templates/checkin/`: `_base`, `roster`, `_name_tile`, `result`, `_pass_card`, `message`. Catalogue `translations/checkin+intl-icu.en.yaml` (domain `checkin`).
- Assets: `assets/styles/checkin/checkin.css` (imported by `app.css`), `assets/js/checkin-select.js` (importmap entry `checkin-select`).
- `RosterLabeller`: a ` (n)` suffix now takes the smallest free n ≥ 2, so it never collides with a custom label.
- `config/packages/security.yaml`: only the comment above `^/c/` changed (D-040).

## How to use it

- **Link to the flow:** `path('checkin_public', {token: plainToken})` — #16 builds the QR code from this URL.
- **Functional tests:** `use OpensPublicCheckIn;` (`tests/Functional/CheckIn/OpensPublicCheckIn.php`) and call `resetCheckInLimits()` in `setUp()`; `assertPublicResponse()` checks the S8 headers, both metas and no `Set-Cookie`.
- **Public layout changes:** `templates/checkin/_base.html.twig` copies the shell of `layout/public.html.twig` without the flash region; change both together.

## Decisions

- **P1** Cross-site check before the IP limiter. Why: a forged POST runs at the victim's IP and must not burn their quota. Undo: move it after `allowIp()`.
- **P2** Tests create the window with `CheckInWindowFactory` and a fixed token hash. Why: `CheckInWindows::open()` is not in the test container until #16. Undo: use `open()` then.
- **P3** The 429 tests exhaust the limiter through `CheckInThrottle`. Why: independent of the configured numbers. Undo: none needed.
- **P4** Bad `enrollment` → 422 with the roster, only after `roster()` succeeded; a valid UUID not on the roster → 404. Why: a refusal of the token wins. Undo: answer 404 for both.
- **P5** No-cookie contingency: `_base` overrides the body without the flashes include (applied, see Deviations). Undo: delete the override once the layout's flashes do not start a session.
- **P6** GET on `/confirm` → Symfony's 405 page. Why: POST-only per D-041. Undo: add a GET that redirects to the roster.
- **P7** `Retry-After` = `max(1, retryAt − now)` seconds from `ClockInterface`. Undo: send an HTTP date instead.
- **D-041 applied:** order on the POST is cross-site 403 → IP limit → token limit → roster refusal 404 → 422 → confirm outcomes.
- **D-042:** lesson times use intl's short time ("Tue 17 Nov, 7:00 PM") where the board shows 24 h "19:00"; a recorded difference.

## Deviations

- **P5 override needed:** `layout/_flashes.html.twig` reads `app.flashes`, which starts a session; Symfony then rewrote `Cache-Control` to `max-age=0, must-revalidate, no-store, private`. `_base.html.twig` now copies the public shell minus the flashes include; `layout/*` is untouched.
- The confirm POST calls `roster()` before `confirm()`: the result page needs course, teacher and lesson time, which `CheckInConfirmation` does not carry.
- The recorded heading is "You're checked in, {label}" without a full stop (a label like "Mia S." already ends with one).
- The roster heading is the `<legend>` of a `<fieldset>` around the radios (group name for assistive tech).
- Dates are formatted with `locale: app.locale`, not a hard-coded `'en'`.
- `tests/Functional/Shared/LayoutTest.php` (#2's test) now requests `/privacy` instead of `/c/abc`, which is now a 404 page.
- QR-7 privacy test: no entity has a phone field, so emails and notes are checked; the teacher's full display name appears by design.
- The roster caption and the low-balance note show the teacher's full display name ("Ask Mara Lindqvist"); the board uses the first name. No first name exists on `Teacher` or `ValidCheckIn`.

## Carried forward

- **#20:** roster search above 16 names; tonight's tick dot and its caption; teacher first name in the caption and low note; board polish (pass numeral ~56 px and heading ~36 px, zero state shows 10 empty dots where the board shows none, owed shows "1 lesson owed" text where the board shows a large "−1", footer not pinned to the screen bottom with short lists); keep `_base.html.twig` in step with `layout/public.html.twig` (applies to any cycle editing it).
- **#19:** review of request integrity without a cookie; the 405 page on GET `/confirm` lacks the S8 headers; app-wide security headers and CSP; an audit event per scan; review notes: a visitor who picks another name sees that student's pass or the "marked" message (the PRD §4.5 trust model accepts it), and the IP limiter keys on `getClientIp()`, so a proxy needs `trusted_proxies` (#22).
- **G7:** check on a real phone.

## Verification

- `make cs-fix` (0 files fixed), `make lint` pass, `make test` OK (427 tests, 4168 assertions). Lane-diff grep for inline styles/scripts, private hosts and local paths: empty.
- New tests: `PublicCheckInPageTest` 9 (Journeys B and C, marked by teacher, 422 ×2, cross-site 403, same-origin accepted, empty roster, owed), `PublicCheckInRefusalTest` (7 window refusals on GET+POST, 4 enrollment refusals), `PublicCheckInThrottleTest` 3, `PublicCheckInPrivacyTest` 1 (QR-7 over every body), `RosterLabellerTest` new cases for the suffix fix plus a pairwise-distinct check.
- Pages H1–H3 and the refusal message checked at 390 and 1440 px in iframes of exactly those widths (the Chrome window could not be resized): no horizontal scroll, 52 px tiles, visible focus, sticky footer, all pass states.
- JS off (sandboxed iframe without scripts): selection still visible, submit records and shows the result.
- Observed Chrome headers: a same-origin submit sends `Sec-Fetch-Site: same-origin` and `Origin: null` (because of `no-referrer`) → 200. A cross-site form from `127.0.0.1` sends `Sec-Fetch-Site: cross-site`, `Origin: http://127.0.0.1:8003` → 403, no row. The research's claim that a cross-site form sends `Origin: null` was wrong for Chrome.
- After manual checks: the footer status name is bold, as on the board (`<strong>` built by the JS from data attributes, text only).
- Review (2026-11-11), after rebasing onto `main` with #9: `make lint` pass, `make test` OK (463 tests, 4526 assertions); D-026, D-027, D-034, D-035, D-040, D-041, D-042, D-045 checked against the code; no finding.
- Pending for a human: real phone (G7); `Sec-Fetch-Site` on mobile Chrome, Safari and Firefox; JS turned off in the browser's settings.
