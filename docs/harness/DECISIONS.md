# Decisions and owner inbox

Edited only by the CTO. The owner reads this file to check in: every row in the log is a call that was made without asking and can be reversed.

## Owner inbox

Things only the owner can do or approve. The project keeps moving on the default meanwhile.

| # | What is needed | Why | Default we run on | Status |
|---|---|---|---|---|
| O-1 | Run `/design-login` once in the CTO session | The design import (#1) reads the Claude Design project through the `claude_design` MCP server | Gate G2 in `docs/rpi-cycles.md` | done 2026-11-10: CTO-1 read the project list |
| O-2 | Confirm product name and domain | `tanzhaken.ch` is unverified (PRD §11) | Gate G3: name from `APP_BRAND_NAME`, no domain in code | open |
| O-3 | Hosting account and go-live | A production deployment is not ours to do | Gate G4: documented and rehearsed locally only | open |
| O-4 | Data controller details for the privacy page | Legal content | Gate G5: placeholders from the environment | open |
| O-5 | Test on a real iPhone and Android phone | An agent cannot hold a phone | Gate G7: desktop browser at 390 px | open |
| O-6 | SMTP account that sends the password-reset mails in production | The app has to mail reset links | Gate G6: `MAILER_DSN` from the environment; nothing real is sent in development | open |

## Log

Newest last. "Undo" says what it would take to reverse the call.

| ID | Date | By | Decision | Why | Undo |
|---|---|---|---|---|---|
| D-001 | 2026-11-10 | setup | One teammate session per RPI phase; phase boundary = session boundary | RPI needs a clean context between phases and a teammate cannot clear itself; it also keeps every session far below 200k | Change `CTO.md` §5 |
| D-002 | 2026-11-10 | setup | Worktrees live in `.claude/worktrees/<slug>`, not in sibling folders as in earlier projects | It is the only place a spawned agent can enter with EnterWorktree without a prompt, and file access stays inside the project | Change `bin/harness/lane` and the docs |
| D-003 | 2026-11-10 | setup | Trunk-based: one squashed commit per cycle pushed to `main` by `bin/harness/land`; pull requests only when the CTO wants CI before the merge | Fewest moving parts for one machine and disjoint lanes | `CTO.md` §6 |
| D-004 | 2026-11-10 | setup | Outcomes go to `docs/outcomes/<nn>-<slug>.md`, not into `docs/rpi-cycles.md` | Parallel lanes would conflict on one file, and the plan file would grow past what a session may read | Move the files' content into the plan |
| D-005 | 2026-11-10 | setup | 24 cycles in 8 waves; all nine tables created by one cycle (#3); domain rules built as backend-only cycles before the pages | Gives three to four lanes real independence; the PRD's data model is explicit enough to build first | Edit the overview |
| D-006 | 2026-11-10 | setup | PHP 8.4 only, UUIDv7 keys, plain CSS, no Node, module-first folders without a command bus (S1–S3, S6) | PRD §7 asks for the smallest maintainable stack; matches the tools on this machine | Edit the settled decisions before #0 lands |
| D-007 | 2026-11-10 | setup | Teammates use the default agent type, no custom agent definition | In split-pane mode a definition's body replaces the system prompt | Add `.claude/agents/*.md` and name it at spawn |
| D-008 | 2026-11-10 | owner | The repository is the existing public `cvtmal/tanzhaken` on GitHub, set up by the owner; the CTO creates nothing and pushes `main` there | Public repositories get unlimited GitHub Actions minutes | The owner changes the visibility (`gh repo edit --visibility private`); what was public may already have been copied |
| D-009 | 2026-11-10 | CTO-1 | Background sessions (successor CTO, §10 fallback) start with `--settings '{"teammateMode":"in-process"}'` | Preflight: a `claude --bg` session is not inside iTerm2, so with the repository's `teammateMode: iterm2` it cannot spawn; with the override probe2 spawned and answered | Drop the flag from `CTO.md` §9/§10 |
| D-010 | 2026-11-10 | CTO-1 | Liveness of a split-pane teammate is judged by AGE in `ctx`, not by STATUS | Preflight: `ctx` showed the live probe as `ended` | Fix the status detection in `bin/harness/ctx` |
