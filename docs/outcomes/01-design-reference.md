# #01 design-reference — outcome (2026-11-10)

## Delivered

The Claude Design project for TanzHaken is in the repository: the canvas and its two support files under `docs/design/source/`, one 1× image per board (22) under `docs/design/screens/`, and `docs/design/README.md` with the screen inventory, the tokens, the components, the breakpoints, the full list of gaps against the PRD and the settled decisions, and how the images were made. A UI cycle builds from this folder without the design tool. No file under `src/`, `templates/` or `assets/` changed.

## Interfaces

- `docs/design/source/TanzHaken.dc.html`, `ios-frame.jsx`, `support.js`: the design; sizes and SHA-256 in README §2.
- `docs/design/screens/<label-slug>.png`: 22 boards (intro, foundations, A1–A3, B1–B2, C1–C3, D1, E1–E3, F1–F3, G1–G2, H1–H3).
- `docs/design/README.md`: §1 what this is, §2 files, §3 viewing the source, §4 screen inventory (board → image → cycle), §5 tokens (with proposed `tokens.css` property names), §6 components, §7 breakpoints, §8 gaps, §9 demo content, §10 how the images were made.
- `docs/design/HANDOFF.md`: unchanged.

## How to use it

- #2 starts at README §5 and §6 and `screens/foundations.png`; it decides the final token names and the layout switch (§7).
- Every UI cycle finds its boards in README §4 (column "Builds it") and its gaps in §8 (column "Builds").
- Behaviour comes from the PRD and `docs/rpi-cycles.md`, never from the prototype's JS; §8 lists each place they differ.

## Decisions

- D1 Build the Tweaks defaults only (Plum accent, Paper QR stage). Undo: switch tokens later.
- D2 Tab label "Today", route `dashboard`. Undo: change the label in the `messages` catalogue.
- D3 The strip/capture/extract scripts are not committed (S1: no Node in the project); README §10 describes the method.
- D4 Images at 1×, named after the board label; Intro and Foundations included.
- D5 The project's `.thumbnail` is not imported.
- D6 Demo names are invented design content; seed data invents its own (README §9).
- D7 Recommended single layout switch at `64rem`; #2 decides.
- D8 Real personal data or a resolving email domain in the source is replaced in the committed copy by a same-shape invented value; recorded below.
- Gap actions in README §8 are this cycle's recommendations; the owning cycle may refine them within the PRD and the settled decisions.

## Deviations

- `TanzHaken.dc.html` is not byte-identical to the design project. On the CTO's override of D9, the demo email at `tanzraum.ch` (lines 165, 184, 203; boards A1, A2, A3) reads `lena@tanzraum.example`, because `tanzraum.ch` is a registered domain. Size 182,069 → 182,084 B; SHA-256 `78c35d64…76bf` → `0a73d195…0d3b`. The three PNGs were captured again. The incomplete `lena@tanzraum` in the Foundations error-field example (line 114) has no domain and stayed.
- The preview tool's injected style-and-script block was stripped; `support.js` keeps its own two `data-omelette-chrome` attributes (part of the original file).
- The local static server for the capture was built into the capture script instead of `python3 -m http.server`; same result.

## Carried forward

- Every gap in README §8 has an owning cycle: #2 (self-hosted fonts and icons, reduced motion, nav, S9 labels), #4 (A2 legal line), #8/#16 (60-minute window, S8 token, server SVG QR), #9 (students screens), #10 (course edit/archive, enrol picker, lesson list), #11 (account, password reset), #12 (void/correction, "Other" amount), #13 (cancel, clear with reason, always-visible "Mark absent"), #14 (failure states, no public undo, name search), #15 (export action), #18 (privacy, account deletion).
- "Tanzraum Wipkingen" and "Volkshaus" stay as room names in the design; seed data should invent its own (#3).
- Review finding (minor): the design has no timezone anywhere and README §8 has no row for it. PRD §6 gives `course.timezone` and `teacher.timezone`, §4.1 a default timezone in settings, S7 a visible zone where unclear. #10 adds the course field (or defaults it from the teacher), #11 the account setting, #2/#13/#17 show the zone where it could be unclear.

## Verification

- Source: sizes and SHA-256 of the three files match README §2; `grep` finds no preview host, no preview token and no injected block in anything committed; the one long base64 run is React's SRI hash in `support.js`.
- Identity scan of all names and emails: no match with the owner's identity; verdicts in the changes log.
- Images: 22 PNGs, 1.9 MB, widths 402 / 1280 / 354 / 2820; fonts, icons and QR codes rendered (all boards checked in an image pass, A1 and A3 viewed again after the recapture).
- Every README image link resolves (scripted check).
- No app pages exist in this cycle, so there is no 390/1440 page check.
- `make lint` and `make test` green after the lane was synced onto #0 (review: 29 tests, 107 assertions).
