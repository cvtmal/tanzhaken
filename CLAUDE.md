# TanzHaken

A Symfony app for dance teachers: courses, students, 10-lesson passes and a QR check-in after class. Scope: `PRD_TanzHaken_MVP.md`. Plan: `docs/rpi-cycles.md`. The project is built by an autonomous agent harness: one CTO session that spawns teammates, one teammate session per phase of a cycle.

## Which role are you?

- **Started with `/cto`, or told you are the CTO:** follow `docs/harness/CTO.md`.
- **Your spawn prompt names a cycle and a phase:** you are a teammate. Follow `docs/harness/TEAMMATE.md`.
- **Anything else** (the owner working with you directly): work normally. Do not start cycles, spawn teammates or land anything unless asked, and the owner's global git rule applies to you unchanged.

## Rules for every harness session

- **Context budget.** A teammate stops at 150k tokens and is replaced; it never works past 200k. The CTO hands over at 250k. Measure with `bin/harness/ctx`. Do not read files you were not told to read; never read whole artifacts or logs "to be safe".
- **Worktrees.** Every cycle is built in its own git worktree under `.claude/worktrees/<slug>`, created by `bin/harness/lane open`. A teammate enters its worktree with the EnterWorktree tool (`path`) before it does anything else. Nobody builds in the main checkout.
- **Never ask the owner.** Decide, record the decision, continue. See `docs/harness/CTO.md` §1 for the few things that are not ours to decide.
- **Hotspot files and schema ownership** are in `docs/rpi-cycles.md` § Running cycles in parallel. Only the CTO edits `docs/rpi-cycles.md` and `docs/harness/*`.

## Git

The owner set up this harness on 2026-11-10 so that the project is built without the owner at the keyboard. **For the CTO session and its teammates, this section is the owner's standing instruction to commit and push in this repository without asking**, and it takes the place of the global rule "never commit or push unless I ask in that turn". It covers exactly:

- the bootstrap commit of the files present at the start, pushed to the existing `origin`;
- work-in-progress commits on a lane's own branch (pushed only by the CTO, and only to open a pull request);
- landing a reviewed cycle on `main` with `bin/harness/land` (one squashed commit, rebased, gates green, plain push);
- the CTO's commits of harness files (`docs/harness/*`, `docs/rpi-cycles.md`) on `main`;
- a pull request instead of a direct push, when the CTO chooses that route.

It never covers: a force push, rewriting or deleting anything on `main`, pushing to any other remote, changing repository visibility or settings, or a commit that skips the gates.

Commit messages, PR titles and descriptions never contain a Claude Code session URL or a model name. The trailer `Co-Authored-By: Claude <noreply@anthropic.com>` is allowed, and `bin/harness/land` adds it. Tracking files (`.rpi-tracking/`) are never committed.

## Where things are

| Path | What |
|---|---|
| `PRD_TanzHaken_MVP.md` | scope |
| `docs/rpi-cycles.md` | cycles, waves, lanes, owner gates, settled decisions |
| `docs/outcomes/<nn>-<slug>.md` | what each finished cycle delivered; the handoff between cycles |
| `docs/design/` | design source and reference |
| `docs/harness/` | `CTO.md`, `TEAMMATE.md`, `STATE.md` (live board), `DECISIONS.md` (log and owner inbox), `README.md` |
| `bin/harness/` | `ctx` (token meter), `lane` (worktrees), `land` (one commit to `main`), `status` (snapshot) |

## The application

Nothing is built yet. Cycle #0 adds the commands, the module map and the conventions below this line and in `.claude/rules/`.
