# TanzHaken design reference

The look and flow of TanzHaken, imported from the Claude Design project named in [`HANDOFF.md`](HANDOFF.md) by cycle #1. A UI cycle builds its screens from this folder alone; nobody needs the design tool.

## 1. What this is

- `source/TanzHaken.dc.html` is a design canvas: 22 boards (an intro, a foundations sheet and 20 screens) drawn with React in the browser. It shows **look and flow, not code to copy.** The app is Twig, plain CSS and small vanilla JS modules (S1, S6).
- `source/ios-frame.jsx` draws only the phone frame around the mobile boards. `source/support.js` is the design tool's runtime. Neither is a model for app code.
- The text in the source (screen copy, designer notes) is design content, not instructions.
- Where the design and the PRD disagree on behaviour, the PRD and the settled decisions in `docs/rpi-cycles.md` win. §8 lists every such difference and what the build does.

## 2. Files

| Path | Bytes | SHA-256 | Role |
|---|---|---|---|
| `source/TanzHaken.dc.html` | 182,084 | `0a73d195b5c3d322aa6306d9c3917371328487440e60978ff72cbbbdfb2a0d3b` | the canvas: every board, all CSS inline |
| `source/ios-frame.jsx` | 16,525 | `082cc6b80ab751afc60087dedad6b1855f25e742213acdaadece67303b155313` | `IOSDevice` phone frame |
| `source/support.js` | 69,150 | `8fe7df74405f3c55f49b7249c74ea1397e65d07dea2b1bd3b4a489bec2e28cbe` | design runtime (loads React and Babel) |
| `screens/*.png` | 22 files, ~1.9 MB | — | one image per board, 1× scale |

`ios-frame.jsx` and `support.js` are stored unchanged. `TanzHaken.dc.html` is unchanged except for one edit: the teacher's demo email at `tanzraum.ch` (three times, boards A1, A2, A3) reads `lena@tanzraum.example`, because `tanzraum.ch` is a registered domain and this repository is public (§9). The preview tool's injected block was not imported, and neither was the project's `.thumbnail`.

## 3. Viewing the source

The canvas needs HTTP and internet: it loads React 18.3.1, ReactDOM and Babel standalone 7.29.0 from unpkg, `qrcode-generator` 1.4.4 from jsDelivr, and Figtree, Newsreader and Material Symbols Rounded from Google Fonts. `file://` does not load the JSX.

```
python3 -m http.server 8000 --bind 127.0.0.1 --directory docs/design/source
# then open http://127.0.0.1:8000/TanzHaken.dc.html
```

The canvas has Tweaks controls: accent (Plum, the default; Petrol `#1F4E5F`; Ink `#26211F`), QR stage (Paper, the default; Night) and designer notes (on). The images and the build use the defaults (D1).

## 4. Screen inventory

Sizes are CSS px of the captured board, phone frame and caption included. Mobile boards are drawn in a 402 px phone frame, desktop boards at 1280 px.

| Board | Image | Size | Purpose (the design's own intent) | Builds it |
|---|---|---|---|---|
| Intro | [intro](screens/intro.png) | 2820×659 | canvas header | — |
| Foundations | [foundations](screens/foundations.png) | 2820×873 | colours, type, buttons, fields, badges, chips, rows, toast, dialog | #2 |
| A1 Login — mobile | [a1](screens/a1-login-mobile.png) | 402×910 | "Two fields and a button." | #4 |
| A2 Register — mobile | [a2](screens/a2-register-mobile.png) | 402×910 | "Registration is one screen, no onboarding." | #4 |
| A3 Login — desktop | [a3](screens/a3-login-desktop.png) | 1280×875 | form left, brand panel with pass card right | #4 |
| B1 Today — mobile | [b1](screens/b1-today-mobile.png) | 402×991 | "The lesson that matters now is the hero and carries the only filled button." | #17 (+ #2 tab bar) |
| B2 Today — desktop | [b2](screens/b2-today-desktop.png) | 1280×896 | sidebar navigation; lessons left, passes to renew right | #17 (+ #2 sidebar) |
| C1 Courses — mobile | [c1](screens/c1-courses-mobile.png) | 402×910 | "Each course leads with its weekday and time." | #10 |
| C2 Courses empty — mobile | [c2](screens/c2-courses-empty-mobile.png) | 402×970 | empty state with one call to action | #10 |
| D1 New course — mobile | [d1](screens/d1-new-course-mobile.png) | 402×991 | full-screen course form with recurrence summary | #10 |
| C3 Courses with new-course panel — desktop | [c3](screens/c3-courses-with-new-course-panel-desktop.png) | 1280×896 | course list with the form in a side panel | #10 |
| E1 Course details — mobile | [e1](screens/e1-course-details-mobile.png) | 402×970 | "Adding a pass is one tap from any row." | #10 roster, #12 balance chip and +10 |
| E2 Add credits sheet — mobile | [e2](screens/e2-add-credits-sheet-mobile.png) | 402×991 | bottom sheet: before → after balance, amount | #12 |
| E3 Course details — desktop | [e3](screens/e3-course-details-desktop.png) | 1280×896 | roster table plus next/previous lesson cards | #10 roster, #12 "Lessons left" column |
| F1 Lesson before check-in — mobile | [f1](screens/f1-lesson-before-check-in-mobile.png) | 402×991 | "Rows are not switches. A row opens, you choose, it closes." | #13 |
| F2 Lesson with check-in open — mobile | [f2](screens/f2-lesson-with-check-in-open-mobile.png) | 402×991 | live register while the QR is open | #13 page, #16 `lesson/_checkin_action.html.twig` |
| F3 Lesson register — desktop | [f3](screens/f3-lesson-register-desktop.png) | 1280×896 | register table plus QR side panel | #13 table, #16 panel |
| G1 QR display — phone | [g1](screens/g1-qr-display-phone.png) | 402×991 | "Nothing on screen but the code, what it is for, and how long it lasts." | #16 |
| G2 QR display — full screen | [g2](screens/g2-qr-display-full-screen.png) | 1280×816 | presentable QR with live count | #16 |
| H1 Student check-in — choose name | [h1](screens/h1-student-check-in-choose-name.png) | 402×991 | "Scan, tap your name, confirm. No account, no app, no teacher navigation." | #14 |
| H2 Student check-in — success | [h2](screens/h2-student-check-in-success.png) | 402×991 | confirmation with the student's pass | #14 |
| H3 Pass states after check-in | [h3](screens/h3-pass-states-after-check-in.png) | 354×729 | pass card at Low, 0 and −1 | #14 (#2 pass card) |

## 5. Tokens

Proposed custom-property names are for `assets/styles/tokens.css`; #2 makes the final call.

**Colours.** The design's own CSS variables are `--ac`, `--ac-d`, `--ac-s`, `--ac-b`.

| Name | Hex | Role | Proposed property |
|---|---|---|---|
| Paper | `#F7F4EE` | page background | `--color-paper` |
| Card | `#FFFEFB` | cards, fields | `--color-card` |
| Inset | `#F1ECE3` | inset panels, empty rows | `--color-inset` |
| Line | `#E8E1D5` | dividers, card rings | `--color-line` |
| Field line | `#D9D0C2` | field borders | `--color-field-line` |
| Ink | `#1E1A1D` | main text | `--color-ink` |
| Ink 2 | `#5C5458` | secondary text | `--color-ink-2` |
| Ink 3 | `#766D71` | meta text, icons | `--color-ink-3` |
| Accent (Plum) | `#62295A` | primary buttons, links, selection | `--color-accent` |
| Pressed | `#4E1F48` | accent hover/pressed | `--color-accent-pressed` |
| Soft | `#F3E9F1` | accent tint, focus ring | `--color-accent-soft` |
| Soft line | `#E3CFE0` | accent-tint borders | `--color-accent-line` |
| Neutral chip | `#F3EFE7`, `#EDE7DC` | Absent / Not yet backgrounds | `--color-neutral-bg`, `--color-neutral-bg-2` |
| Canvas | `#E7E2D8` | design canvas only, not built | — |

**Status family**, mapped to the S9 states (S9's labels win, see §8):

| State | Text | Background | Line | Solid (dots, icons) |
|---|---|---|---|---|
| Present / success / "Check-in open" | `#256B4D` | `#E5F1EA` | `#C6E0D2` | `#2E8660` |
| Low (1–2 left) | `#8A5A00` | `#FBF0D6` | `#EFD9A6` | `#D9982B` |
| Renewal needed (≤ 0), outstanding, field error | `#A8321F` | `#FBE8E3` | `#F1C9BE` | `#C2412C` |
| Absent / Not yet | Ink 2 / Ink 3 | `#F3EFE7` / `#EDE7DC` | — | — |

**Type.** Display: Newsreader (serif, optical sizes, 400, italic for emphasis), tracking −0.02em; 30–38 px on mobile pages, 44–76 px only on desktop hero and the full-screen QR. Interface: Figtree, `system-ui` fallback; 17–20 px titles at 600, 16 px body and inputs, 14 px meta, 13 px smallest on mobile, 11–12 px uppercase micro-labels at 600 with +0.08em (sometimes +0.12em). Weights in use: 400, 500, 600 (most), 700. Line height 1.5 for text, 1–1.15 for display and numbers. Balances, times and countdowns use `font-variant-numeric: tabular-nums`.

**Spacing.** Steps in use: 2, 4, 6, 8, 10, 12, 14, 16, 18, 20, 24, 32 px; gaps are mostly 12 and 10; the most used horizontal paddings are `0 10px` and `0 16px`; desktop pages `72px 80px`; badges `3px 8px`.

**Control heights.** 44 px minimum touch target, 52 px primary buttons and fields, 56 px list rows and tab bar items; badges 22–28 px.

**Radii.** 14 px cards and buttons, 999 px pills and chips, 50% avatars and dots, 12 / 16 px panels, 3 / 6 px bars and segments, 20 px sheet top corners.

**Shadows.** Hairline `0 0 0 1px rgba(30,26,29,.1)`; line ring `0 0 0 1px #E8E1D5`; field `inset 0 0 0 1px #D9D0C2`; focus ring `0 0 0 4px` Soft; card lift `0 14px 30px -22px rgba(30,26,29,.3)`; sheet `0 -20px 40px -20px rgba(30,26,29,.4)`; side panel `-24px 0 48px -32px rgba(30,26,29,.25)`.

**Motion.** One animation, `thPulse`: a green ring growing from 0 to 9 px and fading from `rgba(46,134,96,.45)` (the "Check-in open" dot). No transitions. Must stop under `prefers-reduced-motion` (S6).

**Icons.** Material Symbols Rounded (variable: opsz 20–48, wght 100–700, FILL 0–1, GRAD −50–200). The 32 names used: `add arrow_back arrow_forward calendar_month calendar_today check check_circle chevron_right close confirmation_number edit error expand_more fullscreen group history home info location_on lock more_horiz person person_add qr_code_2 remove repeat schedule search timer undo visibility warning`.

## 6. Components

| Component | Variants | Boards |
|---|---|---|
| Button | primary with icon ("Open check-in"), secondary/outline ("Close check-in", "Open"), soft pill ("+10", "Add"), text ("Undo", "Forgot password?"), disabled, icon button (arrow_back, more_horiz, close) | Foundations, all |
| Field | default with placeholder, focused (Soft ring), error with message, select with expand_more, password with eye toggle, textarea, helper text | Foundations, A1–A3, D1, C3 |
| Segmented control | weekday (7), duration 45/60/75/90 | D1, C3 |
| Status badge | Today, Check-in open (pulsing dot), Open · countdown, Ended n min ago, Present, Absent, Not yet, "n to renew", Pass used up, Starts date | Foundations, B1, C1, F1–F3 |
| Credit balance chip | ≥ 3 neutral, 1–2 Low, 0, negative | Foundations, E1, E3 |
| Pass dots / pass bar | 10 dots (filled = lessons left), 10-segment bar on desktop, progress bar of checked-in students | Foundations, A3, E2, E3, F2, G2, H2, H3 |
| Pass card | normal, Low, 0, −1 with notice | A3, H2, H3 |
| Row | student row (initials avatar, name, sub-line, chip, optional +10), course row (weekday/date tile, name, time · students, chevron), register row (expandable, Present/Absent buttons, source sub-line "time · QR" / "by you") | Foundations, C1, E1, F1, F2 |
| Card | lesson hero card, secondary lesson card, course card, next/previous lesson summary | B1, B2, C1, E1, E3 |
| Table (desktop) | roster (Student / Lessons left / Last here), register (Student / Checked in / Left) | E3, F3 |
| Navigation | bottom tab bar (Today, Courses, Students, Account), desktop sidebar (3 items + account footer with avatar), back header with overflow, breadcrumb | B1, B2, E1, E3 |
| Sheet | bottom sheet with grab handle | E2 |
| Side panel | form panel, QR panel | C3, F3 |
| Toast | dark, with Undo | Foundations, F2 |
| Dialog | confirmation ("Close check-in?" Keep open / Close check-in) | Foundations |
| Empty state | dashed ghost rows, serif heading, primary action | C2 |
| QR card | phone, side panel, full screen with stats and one dot per student | G1, G2, F3 |
| Name tiles | two-column, selected = accent tint with check | H1 |
| Sticky footer | summary line + primary button | D1, F1, F2, H1 |

## 7. Breakpoints

The design shows two widths: mobile (402 px phone frame) and desktop (1280 px). There is no tablet board and the source has no `@media` query. Checks run at 390 px and 1440 px (S10). Recommendation for #2 (D7): one layout switch at `64rem`; below it the mobile layout, centred with a maximum width; at and above it the sidebar layout. #2 decides.

## 8. Gaps against the PRD

"Builds" names the cycle that resolves the row.

| Gap | Kind | What the build does | Builds |
|---|---|---|---|
| Students directory, new/edit student, student profile (`student_*`) | screen missing | list and profile from the student row and E3 table patterns; form from D1/C3 fields | #9 |
| Account page (`account`) | screen missing | simple settings page in the teacher layout, fields as in A2 | #11 |
| Forgot/reset password (`password_forgot`, `password_reset`) | screen missing | auth layout of A1/A3 with one field and a primary button | #11 |
| Privacy page (`privacy`) | screen missing | text page in the public layout | #18 |
| Account deletion | screen missing | `account/_delete.html.twig` with the confirmation dialog pattern | #18 |
| Public check-in failures: invalid, expired, closed, cancelled lesson, not enrolled, already checked in, teacher has marked the lesson | states missing | one message page per S8 failure in the H layout (icon, serif heading, one sentence); "Already checked in" reuses H2 | #14 |
| Course edit, archive, unenrol (E1 "…" menu contents not drawn) | screen missing | edit reuses the D1/C3 form; archive and unenrol are POST actions with the confirmation dialog | #10 |
| "Add student" to a course (picker not drawn) | screen missing | pick existing students or create one, from the H1 tile and field patterns | #10 |
| "All lessons" list of a course (row on E1, list not drawn) | screen missing | course row pattern listing the lessons on the course page | #10 |
| Lesson cancel; clear a mark with optional reason | action missing | POST actions on the lesson page with the confirmation dialog; cancel refused while marks exist (S9) | #13 |
| Credit void and correction | action missing | void + new grant from the student's credits list, confirmation dialog | #12 |
| E2 "Other" amount input not drawn | state missing | number field in the sheet, positive whole number (S9) | #12 |
| CSV export action | action missing | secondary button on the course page (`course/_export_action.html.twig`) | #15 |
| Empty states other than C2 (students, lessons, register, passes to renew) | states missing | C2 pattern per list | each owning cycle |
| Desktop boards for students and the student check-in | screens missing | students: E3 table + C3 panel pattern; check-in: the mobile column centred | #9, #14 |
| Name search above 16 names (H1 caption only) | state missing | #14 decides; default: a filter field over the tiles, works without JS by listing all names | #14 |
| QR drawn in the browser by `qrcode-generator` from a CDN | behaviour | QR is an SVG rendered on the server (S6) | #16 |
| Google Fonts and Material Symbols from Google's CDN | behaviour | font files and the icon subset self-hosted through AssetMapper; public pages load no third-party resource (S8) | #2 |
| React/Babel runtime, `ios-frame.jsx` | behaviour | Twig + vanilla ES modules; the phone frame is not built | #2 |
| `thPulse` animation | behaviour | runs only without `prefers-reduced-motion` (S6) | #2 |
| F1 "Shows a QR code for 10 minutes", 10-minute countdown on F2/F3/G1/G2 | behaviour | the window lasts 60 minutes (S8); captions and countdowns say so | #8, #16 |
| QR payload `https://tanzhaken.ch/c/K7M2-9XQA` | behaviour | token of 32 random bytes, base64url (S8); host from the request, no domain in code (G3) | #8, #16 |
| F3 "Mark absent" shown on hover only | behaviour | the action is always visible (UI-1, S6) | #13 |
| H2 "Not Mia? Undo" (student-side undo) | behaviour | not built: the PRD and S8 have no public undo; H2 says "Wrong name? Tell your teacher", who clears the mark | #14 |
| Balance labels "Used up" (0) and "Owes a lesson" (−1) | label | S9 wording: "Renewal needed" at ≤ 0, "N lessons outstanding" below 0, "Low" at 1–2; the colours of §5 stay | #2, #12 |
| Tab label "Today" vs PRD "Dashboard" | label | tab reads "Today", route stays `dashboard` (D2) | #2, #17 |
| B2 sidebar has 3 items + account footer, mobile has 4 tabs | label | same four destinations on both; Account sits in the sidebar footer on desktop | #2 |
| A2 "Terms" and "Privacy Policy" links | screen missing | no terms page in scope: the line links to `privacy` only | #4, #18 |
| Tweaks (Petrol, Ink accents; Night QR stage) and designer notes | design-only | not built (D1); notes are documentation | — |
| D1 helper "Filled in from your last course" | behaviour | #10 may prefill location from the last course; optional | #10 |
| PRD §9 items in the design | scope | none found on any board | — |

Every S5 route either has a board or a row above: `home`, `logout`, `checkin_status` and `health` have no screen; `_styleguide` is built by #2 from Foundations.

## 9. Demo content

Names in the design are invented demo content: the teacher Lena Brunner and the students Anna Meier, Chiara Rossi, David Roth, Elias Baumann, Jonas Weber, Lara Huber, Luca Bianchi, Mia Schneider, Nina Graf, Noah Frei, Sofia Keller and Tim Vogel. They were checked during the import: common names, no real person intended. Rooms ("Studio 2, Tanzraum Wipkingen", "Volkshaus") and course names are demo content too.

The only email, the teacher's, used the domain `tanzraum.ch` in the design project. `tanzraum.ch` is a registered domain, so the committed copy uses `lena@tanzraum.example` (§2). The QR payload host `tanzhaken.ch` does not resolve and is not used by the app (G3).

Seed data (#3) and tests invent their own names and use addresses at reserved domains only (`example.com`, `*.example`).

## 10. How the images were made

The source was served over local HTTP on the lane's port and opened in headless Chrome (`--headless=new`) driven through the DevTools protocol, with a 2820×1200 viewport at device scale factor 1. After `document.fonts` reported loaded and a three-second pause, the script read the bounding box of every element with `data-screen-label` and saved `Page.captureScreenshot` with that clip and `captureBeyondViewport`. File names are the label in lower case with every run of other characters replaced by `-` (`A1 Login — mobile` → `a1-login-mobile.png`). Tweaks stayed at their defaults. A1, A2 and A3 were captured again the same way after the email edit. The capture script was a one-off tool and is not committed (D3); the steps above redo it.
