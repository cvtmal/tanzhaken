# #16 checkin-teacher-ui — outcome (2026-11-11)

## Delivered
The teacher's side of the QR check-in, on top of #8's `CheckInWindows` and #14's public flow. #13's lesson page gets the check-in slot: from 15 minutes before the start an "Open check-in" button (caption "Shows a QR code for 60 minutes"); while a window is open an "Open until 7:42 PM" badge (course zone), "Show QR code" and "Close check-in"; nothing on a cancelled lesson. `/lessons/{id}/check-in` is a presentation page without navigation: a large server-rendered SVG QR on a white card, the copyable check-in link, "n of m checked in", "Closes at …" with a live mm:ss countdown, dots per enrolled student (≥ 56rem), "Present full screen" and "Close check-in"; one column on phones, two from 56rem (projector/tablet). The page polls a counts-only JSON every 5 s. When the session has no plain token for the open window (another device), the page explains it and offers "Reopen with a new code" (rotates; the old URL dies). Other states: offer, not yet, cancelled, archived. Works without JS (refresh). The CheckIn `PlaceholderController` is deleted: no placeholder route is left in the app. No migration, no config change.

## Interfaces
- **Routes** (`Controller\CheckInManageController`, `ROLE_TEACHER`, `{id}` = lesson, `Requirement::UUID`): `checkin_manage` GET `/lessons/{id}/check-in`; `checkin_status` GET `/lessons/{id}/check-in/status`; `checkin_open` POST `/lessons/{id}/check-in/open` (CSRF id `checkin_open`, field `_token`) → 303 to `checkin_manage`; `checkin_close` POST `/lessons/{id}/check-in/close` (CSRF id `checkin_close`) → 303 to `lesson_show`. Foreign / unknown lesson → 404; GET on open/close → 405; anonymous or invalid CSRF → 302 `/login`. Page and JSON send `Cache-Control: no-store, private`; the page also `Referrer-Policy: no-referrer`.
- **Status JSON:** `{state: "open"|"closed", expiresAt: ATOM|null, secondsLeft: int|null, checkedIn: int, enrolled: int}` — no names, ids or token (S4).
- **Page variables** (`checkin/manage/show.html.twig`, class docblock): `lesson`, `course`, `state` (open | token_missing | offer | not_yet | cancelled | archived), `status_url`; in state `open` only `expires_at`, `counts`, `public_url` (absolute `checkin_public` URL), `qr_svg`.
- **Services:** `Service\QrCodeRenderer::svg(string $url): string` (inline SVG, viewBox, no XML declaration or root size; 400 px, margin 4, EC L). `Service\CheckInTokenStore`: `remember(OpenedCheckIn)`, `tokenFor(Uuid $lessonId, Uuid $activeWindowId): ?string`, `forget(Uuid $lessonId)` (session key `checkin.token.<lesson>`, value `StoredCheckInToken{windowId, token}`). `Service\CheckInOffer::isOfferable(\DateTimeImmutable $startsAtUtc, \DateTimeImmutable $now): bool`, `LEAD_MINUTES = 15`.
- **Read model** `ReadModel\CheckInProgress`: `counts(Uuid $lessonId, Uuid $courseId): CheckInCounts{checkedIn, enrolled}` (present marks of active enrollments / active enrollments, archived students excluded), `openUntil(Uuid $teacherId, Uuid $lessonId, \DateTimeImmutable $now): ?\DateTimeImmutable`; one DBAL query each.
- **Twig functions** (`Twig\CheckInExtension`): `checkin_open_until(Teacher, string $lessonId): ?\DateTimeImmutable`, `checkin_offerable(\DateTimeImmutable $startsAtUtc): bool`.
- **Slot:** `templates/lesson/_checkin_action.html.twig` fills #13's slot with `{course: {id, name, timezone}, lesson: {id, status, startsAtUtc}}`; uses `app.user`. Contract in its docblock.
- **Templates:** `checkin/manage/{_shell,show,_qr_panel,_stats}.html.twig`. **Assets:** `assets/styles/checkin/manage.css` (imported in `app.css`), `assets/js/checkin-live.js` (importmap entry `checkin-live`, `page_scripts`).
- **Translations:** domain `checkin`, `manage.*` (`title`, `back`, `when`, `with_timezone`, `badge.*`, `scan`, `qr_label`, `url.*`, `stats.*`, `open`, `open_caption`, `close`, `reopen`, `fullscreen`, `token_missing.*`, `not_yet`, `cancelled`, `archived`, `closed`, `slot.*`, `flash.opened|closed|not_open|cannot_open`).
- **Dependency:** `bacon/bacon-qr-code` ^3.1 (v3.1.1) — server-side SVG QR without Node or GD (TEC-5, S6); BSD-2-Clause.

## How to use it
- **#19 (CSP, lead time):** the QR is inline SVG markup and the page JS is `checkin-live` via `page_scripts` (no inline script or style); allow both in the CSP. Add the lead-time refusal in `CheckInWindows::open()`, reusing `CheckInOffer::LEAD_MINUTES`, and map it to `manage.flash.cannot_open` in `CheckInManageController::open()`.
- **#20 (polish):** the slot `templates/lesson/_checkin_action.html.twig` and `checkin-live.js` (data attributes `data-checkin-live`, `data-checkin-status-url`, `data-count-template`) are the places for a ticking badge, the F3 panel and a Close dialog.
- **#22 (HTTPS, proxies):** `public_url` is `generateUrl(..., ABSOLUTE_URL)` from the request host; behind a proxy it needs trusted proxies/headers, or the QR encodes the wrong host or scheme.

## Decisions
- **P1** Two Twig functions taking the teacher explicitly (no `Security` in the extension). Undo: one function returning a view object.
- **P2** Static slot badge "Open until …", no JS on #13's page. Undo: #20 adds `checkin-live` there.
- **P3** Lead time in `CheckInOffer` (CheckIn may not depend on Dashboard), UI only. **D-055:** no server-side rule in #16; F3 side panel → #20.
- **P4** One read model, two one-query methods. Undo: split into two classes.
- **P5** `InvalidCheckInWindow` on open → flash `manage.flash.cannot_open`, the page explains cancelled / archived; `CheckInNotFound` → 404.
- **P6** Close redirects to `lesson_show`, flash `closed` or `not_open` (info).
- **P7** Plain session CSRF tokens `checkin_open` / `checkin_close`, no form type.
- **P8** applied (CTO D-056): `PlaceholderRoutesTest` lost its now-empty provider and both provider tests; `test_router_holds_exactly_the_s5_routes` uses `SHIPPED` + `health`.

## Deviations
- Slot sits in #13's page-header actions (the slot contract), not F1/F2's sticky bottom bar; badge is static (P2); F3 side panel → #20 (D-055).
- `.page-header__actions:has(.checkin-slot) { max-width: 100%; }` in `manage.css`: #2's `flex: none` made the slot overflow at 390 px (474 px); #2's and #13's files untouched.
- Design: no Close confirmation; count as one sentence, not big "9 of 12" over a label; wordmark in the top bar, no "Esc" hint; copy link under the QR (QR-1); dots only ≥ 56rem; slot says "Close check-in"; "Present full screen" also on phones; white card is `#fff` (no pure-white token).
- `manage.stats.checked_in` uses plain ICU args (`{checked}`), so the same string serves as the JS count template. Extra keys `manage.when`, `manage.with_timezone`.
- The shell sets `layout_scripts = ['toast', 'alert']` like the teacher layout, so flashes behave as elsewhere.
- Invalid CSRF → 302 `/login` (Symfony's `InvalidCsrfTokenException` is an authentication exception), not 403; no state change.
- `templates/placeholder.html.twig` is now rendered by nothing; left as #0's file.

## Carried forward
- **#19:** server-side lead time in `CheckInWindows::open()`; CSP for the inline SVG and the page JS.
- **#20:** F3 desktop side panel; ticking slot badge; Close confirmation dialog; feedback when full screen is refused (silently swallowed now); hide "Present full screen" on phones; the 2nd skipped test (#13's `LessonPageTest` slot-absent test, now always skipped) — delete it.
- **#20 (review F-m1):** the slot offers "Open check-in" on started lessons of an archived course, and the POST always flashes `cannot_open`; let `checkin_offerable` also check `course.archived_at` (`templates/lesson/_checkin_action.html.twig`, `Twig/CheckInExtension.php`). **(F-m2):** `checkin-live.js` does not rebuild the dots when `enrolled` passes 40 or grows from 0 after render (the count text stays right).
- **#19 / #20 (review note):** cancelling a lesson or archiving a course does not close its open window, so `checkin_manage` keeps showing a live QR that every scan refuses (until expiry); the slot hides Close on a cancelled lesson.
- **#21:** concurrent `open()` on MariaDB. **#22:** trusted proxies / HTTPS so the absolute QR URL is right behind a proxy.
- **#0 / CTO:** delete the unused `templates/placeholder.html.twig`.
- **Pending human (G7):** real phone scan from the screen in the LAN; "Present full screen" by hand (automation cannot grant it).

## Verification
- Phase 6: `make cs-fix` 0 of 315 fixed; `make lint` pass; `make test` pass (776 tests, 7404 assertions, 2 skipped by design); `make db-fresh && make seed` pass. Logs `var/harness-logs/p6-*.log`.
- New tests (+41): Unit `QrCodeRendererTest` 3, `CheckInTokenStoreTest` 5, `CheckInOfferTest` 6 cases; Integration `CheckInProgressTest` 8; Functional `CheckInManageTest` 14 (open, rotate with old URL dead, close, token missing + reopen, status JSON keys, not yet, cancelled, archived, isolation 404/405/login, CSRF), `CheckInLessonSlotTest` 5.
- Browser (Chrome tools, port 8001, demo teacher; 390 and 1440 px as iframes): lesson slot offer/open and `checkin_manage` open at both widths — no horizontal scroll after the fix, targets ≥ 44 px, focus outline visible. Polling gaps ≈ 5.0 s, countdown ticks, Copy writes the link, a check-in from a second tab raises the count without reload, Close returns to the lesson and kills `/c/<token>`; no console errors. The LAN host check (curl with the LAN `Host`) puts that host in the link and QR.
- Pending human: G7 phone scan; full screen by hand.
- Review (c16-review, 2026-11-11, lane head 7fab8c5): `make lint` pass; `make test` pass (776 tests, 7404 assertions, 2 skipped); browser re-check at 390/1440 px of the slot (offer, open) and `checkin_manage` (no horizontal scroll, targets ≥ 44 px, polling, live count, Copy, Close kills the old URL). Verdict Complete, 2 minors (carried to #20 above).
