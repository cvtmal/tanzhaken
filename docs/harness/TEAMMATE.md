# Teammate guide

You were spawned by the CTO session to run **one phase of one cycle**. Your spawn prompt names the cycle, the phase, the lane, the worktree and the port. Do that phase, report, stop. The CTO then ends your session; the next phase runs in a fresh one. Your artifact on disk is the only thing that survives you, so everything the next session needs must be in it.

## 1. Start

1. Enter your lane: `EnterWorktree` with `path: ".claude/worktrees/<slug>"`. Work only there. Never edit the main checkout or another lane. If `pwd` already is that worktree (a session started inside it), skip this step.
2. Read, in this order and nothing more:
   - `docs/rpi-cycles.md` from the top to the end of **Settled decisions**, then the **Requirements** rows for the IDs in your cycle's overview row, **Reference material**, and your cycle's section;
   - `docs/outcomes/<nn>-<slug>.md` of every cycle in your cycle's "Depends on";
   - the PRD sections those requirement rows name;
   - the upstream artifacts of your cycle (the RPI command finds them).
3. Run the RPI command for your phase with the Skill tool: `rpi:rpi-research`, `rpi:rpi-plan`, `rpi:rpi-implement` or `rpi:rpi-review`, with the slug as argument. If the Skill tool does not offer them, read `$CLAUDE_CONFIG_DIR/plugins/marketplaces/*/plugins/rpi/skills/<command>/SKILL.md` and `…/rpi-conventions/SKILL.md` and follow them by hand.

For research, the task description is your cycle's section plus the settled decisions.

**Fix lanes.** If your slug starts with `fix-`, there is no cycle section and no outcome file: your task is the lines in your spawn prompt, and of step 2 you read only the top of `docs/rpi-cycles.md` to the end of **Settled decisions** and the upstream artifacts. Skip §5 step 1 and §6.

## 2. How RPI's rules apply here

- **"The human reviews before `/clear`"** means the CTO. You do not wait for a human: finish the phase, pass its gate, report.
- **"Ask the user"** never applies. Where RPI would ask, decide on your own recommendation, record the decision and its reason in your artifact, and list it in your report. The owner gates in `docs/rpi-cycles.md` are the only open questions, and each has a default: use the default.
- **"The plan is wrong: stop"** still applies. Do not invent. Mark the step `[?]`, write the blocker into the changes log, and report `BLOCKED`.
- **"Never an autonomous commit"** is replaced by `CLAUDE.md` § Git: commits are authorised here. Keep RPI's other rule: never mark a manual criterion as passed without doing it.
- **Manual verification** is yours where an agent can do it: start the app on your lane's port (`make serve`), open the pages with the browser tools at 390 and 1440 px, compare with `docs/design/`, exercise the flow. Only what truly needs a person (a real phone) stays "pending human verification"; list it.
  - Stop the server before your final report (`symfony server:stop` in your worktree). If your port is taken when you start, it is a leftover of your own lane: stop it the same way.
  - If the browser tools are missing or wait for an approval, do not wait: verify with functional tests and `curl` on your port, list each page as "pending human verification" in the outcome file, and report it under Risks.
- The tracking directory is `.rpi-tracking/` in your worktree.

## 3. Rules of the lane

- Stay inside your cycle's scope. Something missing elsewhere goes under "Carried forward", not into your diff.
- Respect the hotspot table and the schema column of `docs/rpi-cycles.md`. Never edit `docs/rpi-cycles.md` or `docs/harness/*`; tell the CTO what should change.
- Use your lane's port only. Your databases live under your worktree's `var/`.
- During implementation, commit work in progress on your lane's branch whenever a plan phase is green (`wip: <slug> phase N`). These commits are local checkpoints; `land` squashes them. Never push the branch, never touch `main` directly.
- You may use subagents (the Agent tool) as RPI describes, in the foreground. You cannot spawn teammates.
- Do not message the owner. Message the CTO only with your final report, a `BLOCKED` report, or a question that no file answers.

## 4. Context limit

Run `bin/harness/ctx --self` when you start, after every large step in every phase (each subagent that returns, each RPI step, each plan phase during implementation), and before your final report. If it prints the CTO's row instead of yours, find your own row with `bin/harness/ctx <your name>`.

- Below 150k: carry on.
- **150k or more: finish the step you are in, then stop.** Make sure the artifact shows exactly where you are (plan checkboxes, changes log), commit work in progress, and report `CONTEXT-LIMIT` with the next step to take. A fresh session continues. Do not push on to finish "just the rest": past the limit your work gets worse and nobody can tell from outside.

## 5. Landing (review phase only)

If your spawn prompt says "PR route: do not run land", do step 1 only and report; the CTO lands the cycle through a pull request. Otherwise, if your verdict is `✅ Complete` with no critical and no major finding:

1. Check that `docs/outcomes/<nn>-<slug>.md` exists and matches what was built (the implementation session drafts it; correct it where it is wrong; add minor findings under "Carried forward").
2. `bin/harness/land -m "<slug>: <one line saying what the cycle delivers>"`.
3. A rebase conflict (exit 3): resolve it if it is mechanical (lock file, an import list, an appended line), `git add` the files and run the same `land` command again; it continues the rebase and runs the gates itself. Anything that needs judgement about another cycle's code: stop and report. Exit 7 (the gates fail after the rebase): change nothing and report `NEEDS-REWORK(implement)` with the failing gate. Any other exit code: change nothing and report `BLOCKED` with the code and the message `land` printed.
4. Report the landed commit.

With any other verdict, do not land. Report the verdict and the phase to re-enter.

## 6. The outcome file (implementation phase)

Before your report, write `docs/outcomes/<nn>-<slug>.md`, under 80 lines, for a reader who has not seen the code:

```
# #<nn> <slug> — outcome (<date>)
## Delivered            what exists now, in one paragraph
## Interfaces           routes, services and their public methods, read models, tables/columns/indexes, Twig components and slots, commands, config files, env variables
## How to use it        the two or three things a later cycle will call or extend, with the file path
## Decisions            each call you made that could have gone another way: what, why, how to undo
## Deviations           where you left the plan, the settled decisions or the design, and why
## Carried forward      what is not done, known weaknesses, minor review findings — each with the cycle that should pick it up
## Verification         commands run and their result; pages checked and at which widths; what is pending for a human
```

Then commit it on your lane's branch (`wip: <slug> outcome`), so a later `lane sync` meets a clean tree.

## 7. Final report

End with exactly this, as your last message to the CTO. Keep it under 25 lines; the artifact has the detail.

```
REPORT <name> · cycle #<nn> <slug> · phase <phase>
Status: DONE | BLOCKED | CONTEXT-LIMIT | NEEDS-REWORK(<phase to re-enter>)
Gate: <the phase's gate, met or not, in one line>
Artifact: <path>
Decisions: <each in one line, or "none">
Risks / open: <each in one line, or "none">
For the CTO: <what you need decided or changed in the plan, or "nothing">
Next: <the next phase, or the step to resume at>
Landed: <commit, review phase only>
Context: <tokens from ctx>
```

Save the same text as `.rpi-tracking/reports/<name>.md` in your lane before you send it. Then stop. Approve the shutdown request when it comes.
