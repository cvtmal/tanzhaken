# Harness state

The CTO's memory. Edited only by the active CTO, with small edits. A successor resumes from this file alone.

## Now

- **CTO generation:** 3
- **Phase of the project:** W0, W1 done; W2 backend done, #4 left
- **Wave in progress:** W2 (#4) and W3 (#8 running; #9–#11 research/plan allowed, D-032)

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
| 4 `teacher-auth` | implement | c04-impl (bg 6004b4d2) | C (8003) | 11-11 11:45 | plan 6 phases; lane synced onto #2/#7; D-031, D-033; #11 must put logout on /account (phones) |
| 11 `account-and-reset` | research | c11-research | A (8001) | 11-11 08:57 | D-032: implement only after #4 lands; reads #4 plan read-only |
| 10 `courses-ui` | waiting for #4 | — | B (8002) | 11-11 12:25 | plan done (9 phases, L; from Phase 4 needs #4); next: `lane sync courses-ui`, then c10-impl "start at Step 0.1" |
| 9 `students-ui` | waiting for #4 | — | D (8004) | 11-11 11:55 | plan done (6 phases, phases 0, 3–4 need #4); next: `lane sync students-ui`, then c09-impl |

## Next actions

Landed so far: #0 `cff3e1a`, #1 `831184b`, #3 `b4dcb75`, #6 `43477a9`, #5 `3cca71d`, #7 `ac0c0c2`, #2 `7659e70`, #8 `d8296d8` (all ✅ Done in the overview). W0, W1 done; W2 has only #4 left.

1. **Adopt the two running background teammates (D-037).** `c04-impl` (`6004b4d2`, lane C) and `c08-impl` (`40c34956`, lane A) are `claude --bg` sessions; they are not your teammates and cannot be messaged reliably. Watch for `.claude/worktrees/teacher-auth/.rpi-tracking/reports/c04-impl.md` and `.claude/worktrees/checkin-core/.rpi-tracking/reports/c08-impl.md` with a background waiter (a loop over the files, `sleep 30`, time limit). When one reports: read the file, `claude stop <id>`, `claude rm <id>`, then spawn the next phase as usual (you are in iTerm2: Agent tool, §5). If one is silent 45 min by `ctx` AGE: `claude stop`/`rm` and continue from its changes log with a fresh teammate.
2. On `c04-impl` DONE: spawn `c04-review` (lane C, 8003). #4 is the critical path. After it lands and CI is green: `lane sync students-ui` → `c09-impl` (lane D, 8004); `lane sync courses-ui` → `c10-impl` (lane B, 8002, "start at Step 0.1; L cycle, WIP commit per phase"). Both plans are in their lanes' `.rpi-tracking/plans/2026-11-11/`.
3. On `c08-impl` DONE: spawn `c08-review` (lane A, 8001). After #8 lands, lane A goes to #11 `account-and-reset` (research; needs #2, #4: wait for #4) or, if #4 has not landed, stays free.
4. Then #11 (research; must include the logout partial on `/account` for phones, D-033), and W4 as dependencies land. Review prompts here have said: check D-026/D-027, "On Complete, land with bin/harness/land".
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
- CTO-2 ran as a `--bg` session and could not edit the main checkout (D-024) and could not use in-process teammates (I-2, D-025). You run in iTerm2: edit in place and spawn with the Agent tool. Bash `cd` into a lane moves your own cwd: start lane processes in a subshell `( cd … && … )`.
- Teammate reports here are reliable and short; every cross-lane question so far had a sensible default — decide in one line and log it (D-026…D-036 are examples).

## CTO log

One line per CTO, in exactly this form, because a predecessor waits for the first three words of its successor's line:

`CTO-<n> active since <date time> · handed over <time> at <tokens> · landed <cycles>`

CTO-1 active since 2026-11-10 22:40 · handed over 2026-11-11 00:25 at 200.1k · landed #0, #1, #3
CTO-2 active since 2026-11-11 07:55 · handed over 2026-11-11 12:30 at 200k · landed #2, #5, #6, #7
CTO-3 active since 2026-11-11 08:46
