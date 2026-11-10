# Harness state

The CTO's memory. Edited only by the active CTO, with small edits. A successor resumes from this file alone.

## Now

- **CTO generation:** 1
- **Phase of the project:** set up, nothing built
- **Wave in progress:** —

## Mechanics

Facts about this machine and this Claude Code version, established by the setup session on 2026-11-10 (Claude Code 2.1.322). The first CTO confirms the lines marked "verify" in its preflight and corrects them here.

- Permission mode comes from the owner's user settings: `auto`. Auto-compaction is off; the model's window is 1M tokens, so the budgets are enforced only by us.
- Agent teams are switched on for this repository in `.claude/settings.json` (`CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1`, `teammateMode: iterm2`). A teammate is spawned by an Agent call that has a `name` and no `isolation`. Teammates load `CLAUDE.md`, skills and MCP servers but not the lead's conversation. They start in the lead's permission mode. No nested teams; the lead cannot change; in-process teammates do not survive a resume of the lead.
- Verify: how a teammate appears in `bin/harness/ctx` (own session row in split-pane mode, agent row in in-process mode) and whether `ctx --self` works inside it.
- Verify: teammates can run the `rpi:*` skills through the Skill tool.
- Verify: a session started with `claude --bg` can itself spawn teammates (preflight step 6). Also verify `CronCreate` (step 7) and whether teammates have the browser tools.
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
| — | — | — | — | — | — |

## Next actions

Bootstrap, in order (generation 0 → 1):

0. Set **CTO generation** under § Now to 1 and add your line to § CTO log, before anything else.
1. `git status`: `origin` is `cvtmal/tanzhaken` and local `main` sits on its first commit (README only). Commit everything present on top of it (`chore: project plan and agent harness`).
2. Gate G1: `gh api user -q .login` must print `cvtmal`. The repository is **public**, so before the push go through `git ls-files` once and make sure no committed file holds anything private: credentials, real personal data, names or paths of the owner's other work. Then `git push origin HEAD:main`. Create no repository and add no remote. If the push is refused, put G1 in the Owner inbox, send one push notification and stop: this has no default.
3. Preflight as in `CTO.md` §3. Record the results under § Mechanics.
4. Gate G2: check that the `claude_design` MCP server answers (list its tools or resources). If it needs a login, send the owner one push notification ("run /design-login in the CTO session") and note the time; the default in G2 says how long #1 waits.
5. Open the lane for #0 `project-skeleton` and start its research phase. Open #1 `design-reference` as well only if step 4 found the design readable. Otherwise start #1 when access arrives, or with G2's default once #3 has landed.
6. From here on: the loop in `CTO.md` §4.

## Notes for the successor

—

## CTO log

One line per CTO, in exactly this form, because a predecessor waits for the first three words of its successor's line:

`CTO-<n> active since <date time> · handed over <time> at <tokens> · landed <cycles>`

CTO-1 active since 2026-11-10 22:40 · handed over — at — · landed —
