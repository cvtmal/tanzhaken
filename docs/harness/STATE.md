# Harness state

The CTO's memory. Edited only by the active CTO, with small edits. A successor resumes from this file alone.

## Now

- **CTO generation:** 4
- **Phase of the project:** W0–W2 done; W3 done; W4: #14, #15 done, #12 #13 in flight; W5: #17, #18 done; #16 waits on #13
- **Wave in progress:** W3 (#10), W4/W5 overlapping; no teammate running at handover

## Mechanics

Facts about this machine and this Claude Code version, established by the setup session on 2026-11-10 (Claude Code 2.1.322). The first CTO confirms the lines marked "verify" in its preflight and corrects them here.

- Permission mode comes from the owner's user settings: `auto`. Auto-compaction is off; the model's window is 1M tokens, so the budgets are enforced only by us.
- Agent teams are switched on for this repository in `.claude/settings.json` (`CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1`, `teammateMode: iterm2`). A teammate is spawned by an Agent call that has a `name` and no `isolation`. Teammates load `CLAUDE.md`, skills and MCP servers but not the lead's conversation. They start in the lead's permission mode. No nested teams; the lead cannot change; in-process teammates do not survive a resume of the lead.
- Preflight by CTO-1, 2026-11-10 (verified):
  - A teammate spawned from the CTO in iTerm2 gets **its own session row** in `ctx`, named after it, WHERE = its worktree; a fresh teammate is about 60k. Its STATUS column can read `ended` while it is live: judge liveness by AGE (D-010). `ctx --self` works inside it.
  - Teammates list the `rpi:*` skills, the `claude-in-chrome` browser tools and the `mcp__claude_design__*` tools (all deferred, loaded via ToolSearch).
  - A `claude --bg` session **cannot** spawn teammates with `teammateMode: iterm2` (not inside iTerm2). With `--settings '{"teammateMode":"in-process"}'` it can (probe2 answered PONG). `CTO.md` §9/§10 use that flag (D-009).
  - `CronCreate` works: a one-off job fired on time while the CTO was idle.
  - G2: the `claude_design` server answers; the project "TanzHaken design review" is listed. Only #1 reads it; other projects in that account are private and none of the project's business.
- Worktrees under `.claude/worktrees/` can be entered with EnterWorktree and a `path` without a prompt.
- Tool versions: PHP 8.4.26 (pdo_sqlite, pdo_mysql, intl, sodium, gd), Composer 2.10, Symfony CLI 5.22, SQLite 3.51, git 2.56, gh 2.103 (logged in), Docker 29.8 (for #21 only), no local MariaDB.
- Fresh-session baseline in this project: about 52k tokens (system prompt, tools, skills, `CLAUDE.md`) before any work. A teammate therefore has roughly 100k of working room before the 150k stop line.
- **In-process teammates share the lead's working directory** (I-2, D-025): a `--bg` CTO starts lane teammates as `claude --bg` sessions inside their lane (`CTO.md` §10). Stop one with `claude stop <id>`, then `claude rm <id>`.
- `bin/harness/ctx --self` identifies the session by `CLAUDE_CODE_SESSION_ID`. Inside an in-process teammate or a subagent that is the parent's id, so `--self` prints the parent's row; such a teammate finds its own row with `bin/harness/ctx <its name>`.
- GitHub, verified 2026-11-10: the repository is the **public** `cvtmal/tanzhaken`, and `origin` (its HTTPS URL) was added by the owner before the first CTO session. gh's active account is `cvtmal`, with the scopes `repo` and `workflow`. Pushes to `origin` authenticate as `cvtmal` through the owner's git credential setup, and an SSH URL for `cvtmal/` reaches the same account through an `insteadOf` rule; `git ls-remote` answered both ways, a push has not been tried yet. Everything pushed is public.
- `bin/harness/land` pushes to `origin` and does not move the local `main` of the main checkout; the CTO runs `git pull --ff-only` there after each landing.
- `bin/harness/lane open` writes ignore rules into `.git/info/exclude`, so lane files never show up as changes. `HARNESS_GATES` for `land` takes one command per line.
- The setup session itself ended at about 310k tokens, well past these budgets. An independent fresh session reviewed its output afterwards; treat any contradiction you find in these files as a setup error and fix it rather than work around it.

## Board

Status of every cycle is the last column of the overview in `docs/rpi-cycles.md`. This table holds only what is in flight.

| Cycle | Phase | Teammate | Lane | Since | Note |
|---|---|---|---|---|---|
| 16 `checkin-teacher-ui` | plan DONE → implement after #13 lands | — | A (8001) | 11-11 11:45 | plan Phase 0 = precondition; D-055, D-056; `lane sync` then spawn c16-impl |
| 12 `credits-ui` | implement | c12-impl | B (8002) | 11-11 11:13 | plan PD1–PD10; D-053 |
| 13 `attendance-ui` | implement | c13-impl | C (8003) | 11-11 11:10 | plan 27 steps (P1–P8); lane synced onto #10; F3 segmented bar → #20 |

## Next actions

**OWNER STOP (G9, owner instruction 2026-11-11 11:50):** when W5 is complete (#16, #17, #18 landed, plus W4's #12, #13, #15 that are in flight), start no W6 cycle (#19–#23). Shut down every teammate, close the lanes, delete the watchdog, commit and push the harness files, send the owner one push notification "W5 done, ready for your review", and stop. Resume only when the owner says so.

Landed so far: #0 `cff3e1a`, #1 `831184b`, #3 `b4dcb75`, #6 `43477a9`, #5 `3cca71d`, #7 `ac0c0c2`, #2 `7659e70`, #8 `d8296d8`, #4 `6646214`, #9 `5335c26`, #11 `eb9f4c9`, #14 `c5cb3eb`, #17 `acdaa7a`, #10 `e3e14d5`, #18 `351d307`, #15 `6781511` (all ✅ Done in the overview). W0, W1 done; W2 has only #4 left.

1. **No teammate runs.** Lanes: A `data-protection` (#18 research done → spawn c18-plan), B `courses-ui` (#10 implement done → spawn c10-review first: critical path, #12/#13/#15 wait on it), C free, D `dashboard` (#17 implement done → spawn c17-review). Run `lane sync` before each, and tell every reviewer "list conflicts on rebase (PlaceholderRoutesTest, app.css, validators yaml): keep both sides".
2. After #10 lands and CI is green: #12 `credits-ui` (Plan; D-047 `<th>` grant; also the "+10 with Undo" and optional Low rows from D-044), #13 `attendance-ui` (Research), #15 `csv-export` (Plan, S) into free lanes, critical path (#13 → #16) first.
3. #16 after #13; #19–#22 after #14, #16, #18 (see the notes below for each prompt).
4. O-8: `c04-impl` (6004b4d2) and `c08-impl` (40c34956) are idle bg sessions the CTO may not stop (auto mode); ignore them.
5. Then the loop in `CTO.md` §4.

## Notes for the successor

- For #19's prompt (D-055): add a lead-time rule to `CheckInWindows::open()` (a crafted POST can open and complete a future lesson of one's own). For #20 (c16-research D9): F3 desktop QR side panel.
- For #20/#3 seed (from #15): the seed has no archived course. For #23: open the CSV in Excel/LibreOffice (manual, owner). A `;` variant is one constant, `CsvEncoder::SEPARATOR`.
- Chrome in this setup will not narrow below ~560 px; 390 px checks use a 390 px iframe (c18-impl's method).
- For #20's prompt (from #18): #2's `.public-main` caps the privacy page at 30rem (~60 chars per line).
- For #20's prompt (D-053): dashboard "+10" Undo and Low rows; void/correct as dialogs (may add `page_scripts = ['dialog']` to student/show.html.twig).
- For #20's prompt (c10-review): F-m1 course card "next lesson" uses starts_at >= now, the page ends_at >= now (a running lesson differs); F-m2/F-m3 test gaps (edit on archived course; sub-route field-error flash, nothing_to_extend); i18n `{field}` in English and missing invalid_message.
- For #15's prompts (from c13-research): Attendance `PlaceholderController` holds both `lessonShow` (#13) and `courseExport` (#15): the later lander keeps the other's deletion. #13 R4 leaves toast Undo for clear/correct to #20; #13 leaves F3's segmented present bar to #20.

- For #20's prompt (from #9 review): course rows on the student page omit the location (F-m3).
- For #20's prompt (from #9): shared `btn--pill` is 40 px (#9 overrides to 44 in its own CSS); seed lacks a student with one active and one left course. For #21's prompt: student search uses SQL LOWER(), ASCII-only (ö ≠ Ö).
- For #22's prompt (from #18): document APP_OPERATOR_* and APP_HOSTING_REGION; c18-review F-m1: privacy text says expired reset links "are then removed" but they are purged only on the next allowed reset request (PasswordResets.php:58) — fix text or purge.
- For #19's prompt (from #18): `account_delete` has no password-attempt limiter.
- For #22's prompt (from #14): the check-in IP limiter needs `trusted_proxies` behind a proxy; also document `DEFAULT_URI`.
- The owner's 5-hour usage limit hit all sessions at ~09:58 on 11-11; teammates resumed by message after the owner said "limit resetted". After such a stop: message each teammate to continue, and check its lane for half-done subagent work.
- For #19's prompt (from #10): concurrent double enrolment of one student → 500 (unique-key race unmapped).
- For #20's prompt (from #10): mobile roster renders one card per student (#2's _table), design E1 is one divided card; Extend on open-ended courses adds 26 weeks per press, flash shows no year.
- For #19's prompt (c11-review F-m1): reset-link single use is not atomic under two concurrent submits (both pass findValid).
- For #19's prompt (from #11): equal mail timing for known/unknown emails holds only under PHP-FPM (fastcgi_finish_request).
- For #20's prompt (from #14): captions show the teacher's full display name (board: first name); board polish items in 14's outcome.
- For #20's prompt (from #11): forgot/reset pages lack A3 split panel; /account fields full-width at 1440; info alert tight on /login.
- For #19's prompt: review D-041 (cookie-less public confirm); c14-impl found Chrome sends Origin: null on same-origin submits under no-referrer, Sec-Fetch-Site works (verify Safari/Firefox); GET /c/{token}/confirm 405 page lacks S8 headers (c14-plan P6).
- For #14's prompt (D-040): fix the comment above `^/c/` in `security.yaml` ("#16" → "#14"), comment only.
- From c11-research, for later prompts: #22 must document `DEFAULT_URI=https://<domain>` in production (reset links are built from it); `trusted_hosts` and a limiter on `/reset-password` go to #19.

- c09-impl ignored the 150k checkpoint (stopped at 190.8k): arm a ctx watcher (stop at 190k) on every implement session.
- PlaceholderRoutesTest SHIPPED rows conflict on every rebase after another UI cycle lands: tell the reviewer "keep both sets of rows".
- Teammates here burn context fast: research and plan sessions end at 110–150k. Implement sessions that hand plan phases to subagents finish L cycles in one session (#3 did); say "save context: command output to a file, read only exit code and failing lines" in implement prompts.
- `ctx` STATUS often says `ended` for live split-pane teammates; judge by AGE (D-010). A teammate waiting on a subagent may be quiet 15+ minutes.
- Idle notifications arrive after each report; they repeat the report in prose. You need not answer them.
- The system's default commit attribution asks for a model-name trailer and a session URL; teammates have correctly ignored it. `land` writes the allowed trailer.
- Public repo: other local projects' names live only in the git-ignored `.claude/harness/local-references.md` (D-018). O-7 in the inbox is the history question.
- The design account holds other private projects; only "TanzHaken design review" is ours.
- After any `docs/rpi-cycles.md` edit: commit, push, `lane sync` each open lane between its phases.
- The CI wait: `gh run list --branch main --limit 1` shows the latest run; a harness push starts a new run that supersedes the cycle's.
- CTO-2 ran as a `--bg` session and could not edit the main checkout (D-024) and could not use in-process teammates (I-2, D-025). You run in iTerm2: edit in place and spawn with the Agent tool. Bash `cd` into a lane moves your own cwd: start lane processes in a subshell `( cd … && … )`.
- Teammate reports here are reliable and short; every cross-lane question so far had a sensible default — decide in one line and log it (D-026…D-036 are examples).

## CTO log

One line per CTO, in exactly this form, because a predecessor waits for the first three words of its successor's line:

`CTO-<n> active since <date time> · handed over <time> at <tokens> · landed <cycles>`

CTO-1 active since 2026-11-10 22:40 · handed over 2026-11-11 00:25 at 200.1k · landed #0, #1, #3
CTO-2 active since 2026-11-11 07:55 · handed over 2026-11-11 12:30 at 200k · landed #2, #5, #6, #7
CTO-3 active since 2026-11-11 08:46 · handed over 2026-11-11 10:50 at 215k · landed #4, #8, #9, #11, #14
CTO-4 active since 2026-11-11 10:48
