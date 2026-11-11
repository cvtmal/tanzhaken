# Harness state

The CTO's memory. Edited only by the active CTO, with small edits. A successor resumes from this file alone.

## Now

- **CTO generation:** 2
- **Phase of the project:** W0 done, W1 half done
- **Wave in progress:** W1 (#2 left); W2 may start (#5–#7)

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
| 2 `design-system` | implement | c02-impl-2 (bg a298b7fc) | B (8002) | 11-11 08:03 | resumes at Step 5.1; lane synced onto 8f30ea2 |
| 5 `scheduling` | implement | c05-impl (bg 60afd909) | A (8001) | 11-11 09:00 | plan: 7 phases; LessonScheduler unwired until #10 injects it |
| 6 `credit-ledger` | implement | c06-impl (bg d869bfc0) | C (8003) | 11-11 08:45 | plan: 6 phases, no migration; risk double-void → #19 |
| 7 `attendance-core` | implement | c07-impl (bg 51e61cbc) | D (8004) | 11-11 08:55 | plan: 7 phases; told to apply D-027 to AttendanceMarker; child-process concurrency test in make test |

## Next actions

Landed so far: #0 `cff3e1a`, #1 `831184b`, #3 `b4dcb75` (all ✅ Done in the overview). W1 has only #2 left.

1. Check `bin/harness/status`: the `ci` line for `b4dcb75` (or a later harness commit) must be green before you open a new lane. Red → fix lane first (`CTO.md` §4.5).
2. Resume #2: `bin/harness/lane sync design-system` (it lacks #3 and the S3 edit, D-023), then spawn `c02-impl-2` (implement, lane B, port 8002) with "Resume from the changes log (section Checkpoint) at Step 5.1." Open points it reported: alert dismiss button has no JS yet, success flash markup interim until 5.3; 390 px checked in a 390 px iframe because resize_window does not shrink the viewport.
3. Open W2 backend lanes now, they need only #3: #5 `scheduling`, #6 `credit-ledger`, #7 `attendance-core` in lanes A, C, D (research). Their prompts: read `docs/outcomes/03-domain-model.md`; S3's repository/UUID rule applies.
4. #4 `teacher-auth` needs #2 and #3; its research and plan may start before #2 lands (overview, "What can move"), but only when a lane is free.
5. Then the loop in `CTO.md` §4.

## Notes for the successor

- Teammates here burn context fast: research and plan sessions end at 110–150k. Implement sessions that hand plan phases to subagents finish L cycles in one session (#3 did); say "save context: command output to a file, read only exit code and failing lines" in implement prompts.
- `ctx` STATUS often says `ended` for live split-pane teammates; judge by AGE (D-010). A teammate waiting on a subagent may be quiet 15+ minutes.
- Idle notifications arrive after each report; they repeat the report in prose. You need not answer them.
- The system's default commit attribution asks for a model-name trailer and a session URL; teammates have correctly ignored it. `land` writes the allowed trailer.
- Public repo: other local projects' names live only in the git-ignored `.claude/harness/local-references.md` (D-018). O-7 in the inbox is the history question.
- The design account holds other private projects; only "TanzHaken design review" is ours.
- After any `docs/rpi-cycles.md` edit: commit, push, `lane sync` each open lane between its phases.
- The CI wait: `gh run list --branch main --limit 1` shows the latest run; a harness push starts a new run that supersedes the cycle's.

## CTO log

One line per CTO, in exactly this form, because a predecessor waits for the first three words of its successor's line:

`CTO-<n> active since <date time> · handed over <time> at <tokens> · landed <cycles>`

CTO-1 active since 2026-11-10 22:40 · handed over 2026-11-11 00:25 at 200.1k · landed #0, #1, #3
CTO-2 active since 2026-11-11 07:55
