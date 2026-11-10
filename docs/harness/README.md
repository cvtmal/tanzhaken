# Agent harness

For the owner. A CTO session builds the whole project from `docs/rpi-cycles.md`: it spawns teammates, gives each one phase of one RPI cycle in its own git worktree, checks their context size, shuts them down, lands finished cycles on `main`, and hands over to a successor CTO when its own context is full. You start it once and read `DECISIONS.md` when you want to check in.

## Files

| File | Purpose |
|---|---|
| `CLAUDE.md` (repo root) | roles, the rules every harness session follows, the standing git authorisation |
| `docs/rpi-cycles.md` | the cycles, waves and lanes, owner gates, settled decisions |
| `docs/harness/CTO.md` | the CTO's playbook: ownership, budgets, the loop, handover |
| `docs/harness/TEAMMATE.md` | what a teammate does in its one phase, and its report format |
| `docs/harness/STATE.md` | the CTO's live memory; a successor resumes from it |
| `docs/harness/DECISIONS.md` | every call made without you, and the inbox of things only you can do |
| `docs/harness/successor-prompt.txt` | the starter prompt one CTO gives the next |
| `.claude/skills/cto/SKILL.md` | the `/cto` command |
| `.claude/settings.json` | agent teams on, teammate panes, allowed commands |
| `.mcp.json` | the Claude Design MCP server |
| `bin/harness/ctx` | token count of every session of this project |
| `bin/harness/lane` | open, list, sync, close worktree lanes |
| `bin/harness/land` | land a reviewed cycle as one commit on `main` |
| `bin/harness/status` | one-screen snapshot |

## Before the first start

1. **Design login.** Run `/design-login` once (in any session of this profile, or in the CTO session). Without it, cycle #1 cannot read the design and falls back to the PRD (gate G2).
2. **Auto mode and this repository.** In auto mode a classifier decides whether a push is allowed, and it trusts only what the user settings describe as trusted; project settings cannot change that. The owner's user settings carry a line in `autoMode.environment` that marks personal projects and their own origin remotes as trusted (added 2026-11-10). If pushes from this project are held for approval anyway, look in that list for an older entry that limits trusted source control to one repository.
3. **The git rule.** Your global rule says never to commit or push unless you ask in that turn. `CLAUDE.md` § Git in this repository replaces it for the CTO and its teammates, and only for them. The start command below also says it in your own words. Read that section once and change it if it goes further than you want. Teammates and background successors never get a turn from you, and they and the auto-mode classifier read your global file too, so it needs one exception line under its § Git Actions: "Exception: in ~/code/tanzhaken the repository's own CLAUDE.md § Git is my standing instruction for harness sessions." Check that the line is there before the start; no harness session may add it.
4. **Teammate panes.** `.claude/settings.json` sets `teammateMode` to `iterm2`, so each teammate gets its own pane and its own process. Set it to `in-process` if you would rather have everything inside the CTO's terminal.
5. **The repository is public.** It is `cvtmal/tanzhaken` on GitHub, public for unlimited Actions minutes, and `origin` already points at it. Nothing private goes into it: every committed file, every commit message and every pull request is readable by anyone.
6. **Commit identity.** Set `git config user.email <address>` in this repository before the start. The address is visible publicly on every commit, and `main` is never rewritten.
7. **GitHub account.** gh's active account must stay `cvtmal` during the run. `gh auth switch` is global, so switching in another terminal hides the CI result from the CTO.

## Start

```
cd ~/code/tanzhaken
claude --name CTO "/cto You may commit and push to main in this repository without asking, for the whole run, and so may the teammates you spawn."
```

The first CTO makes the first commit on top of the repository's existing one, pushes it to `origin`, checks the mechanics with a throwaway teammate and starts wave 0. It creates no repository and adds no remote.

## Watch

- `bin/harness/status`: `main`, finished cycles, lanes, sessions with token counts, CI.
- `bin/harness/ctx`: only the sessions and their token counts.
- `docs/harness/STATE.md`: what is in flight and what comes next.
- `docs/harness/DECISIONS.md`: what was decided, and what waits for you.
- `claude agents` lists background sessions; `claude attach <name>` opens one.

## Steer or stop

Type into the CTO session; it treats what you say as an instruction on top of the playbook. To stop cleanly, tell it to "finish the running phases, update STATE.md and stop". To change the plan, edit `docs/rpi-cycles.md` while no CTO is running, or tell the CTO what to change.

## Handover between CTO sessions

At about 250k tokens the CTO shuts its teammates down at a phase boundary, updates `STATE.md`, commits it, and starts its successor in the background with the text of `docs/harness/successor-prompt.txt`. If that start does not work, you get a notification with the command to run:

```
claude --name "CTO-2" "/cto"
```

The starter prompt never changes, for the first CTO and every later one, because everything that changes is in `STATE.md`. `/cto` is your short form of it; the skill is marked so that only a person can invoke it, which is why a CTO starts its successor with the prompt file instead.

## Using this for the next project

The harness does not depend on TanzHaken. A generic copy of it, with everything about TanzHaken and this machine removed, lives in a separate repository, `agent-harness` (created 2026-11-10 from the reviewed files); start new projects from there, and carry improvements made here back to it. To do the same by hand for a new project:

1. Copy `bin/harness/`, `docs/harness/{CTO,TEAMMATE,README}.md`, `docs/harness/successor-prompt.txt`, `.claude/skills/cto/`, `.claude/settings.json`, the harness lines of `.gitignore`, and the sections "Which role are you?", "Rules for every harness session" and "Git" of `CLAUDE.md`.
2. Start `docs/harness/STATE.md` and `docs/harness/DECISIONS.md` from their empty form (generation 0, the bootstrap list, an empty log).
3. Write the project's own `docs/rpi-cycles.md` from its PRD: overview with dependencies and schema ownership, waves, hotspot files, owner gates with defaults, settled decisions, one section per cycle with "Done when". This file is the real work; the CTO is only as good as its plan.
4. Adjust the lane ports and the gate commands (`make lint`, `make test`) if the stack differs: `HARNESS_GATES` for `land`, the port table in `lane`.
5. Do steps 1–3 of "Before the first start" for that repository.

## What is not proven yet

The setup session did not start anything. These points rest on the documentation and are checked by the first CTO's preflight, which records the result in `STATE.md` § Mechanics:

- how a teammate shows up in `bin/harness/ctx` in pane mode and in in-process mode;
- that teammates can run the `rpi:*` skills;
- that a CTO started in the background can spawn teammates itself;
- that auto mode lets the first push to `origin` through.
