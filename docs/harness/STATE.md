# Harness state

The CTO's memory. Edited only by the active CTO, with small edits. A successor resumes from this file alone.

## Now

- **CTO generation:** 1
- **Phase of the project:** bootstrap done, W0 running
- **Wave in progress:** W0 ending, W1 started (#3)

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
- `bin/harness/ctx --self` identifies the session by `CLAUDE_CODE_SESSION_ID`. Inside an in-process teammate or a subagent that is the parent's id, so `--self` prints the parent's row; such a teammate finds its own row with `bin/harness/ctx <its name>`.
- GitHub, verified 2026-11-10: the repository is the **public** `cvtmal/tanzhaken`, and `origin` (its HTTPS URL) was added by the owner before the first CTO session. gh's active account is `cvtmal`, with the scopes `repo` and `workflow`. Pushes to `origin` authenticate as `cvtmal` through the owner's git credential setup, and an SSH URL for `cvtmal/` reaches the same account through an `insteadOf` rule; `git ls-remote` answered both ways, a push has not been tried yet. Everything pushed is public.
- `bin/harness/land` pushes to `origin` and does not move the local `main` of the main checkout; the CTO runs `git pull --ff-only` there after each landing.
- `bin/harness/lane open` writes ignore rules into `.git/info/exclude`, so lane files never show up as changes. `HARNESS_GATES` for `land` takes one command per line.
- The setup session itself ended at about 310k tokens, well past these budgets. An independent fresh session reviewed its output afterwards; treat any contradiction you find in these files as a setup error and fix it rather than work around it.

## Board

Status of every cycle is the last column of the overview in `docs/rpi-cycles.md`. This table holds only what is in flight.

| Cycle | Phase | Teammate | Lane | Since | Note |
|---|---|---|---|---|---|
| 0 `project-skeleton` | review | c00-review | A (8001) | 23:34 | plan 8 phases, OD-1–OD-7; may need impl-2 |
| 3 `domain-model` | research | c03-research | A (8001) | 23:45 | |

## Next actions

Bootstrap done by CTO-1 (commit, G1 push, preflight, G2 met).

1. #0 landed `cff3e1a`, CI green. #1 landed `831184b`. #3 in research. When CI for 831184b is green: open #2 `design-system`. Continue with the loop in `CTO.md` §4.
2. W1 opens when #0 has landed (#3) and when #0 and #1 have landed (#2).

## Notes for the successor

—

## CTO log

One line per CTO, in exactly this form, because a predecessor waits for the first three words of its successor's line:

`CTO-<n> active since <date time> · handed over <time> at <tokens> · landed <cycles>`

CTO-1 active since 2026-11-10 22:40 · handed over — at — · landed —
