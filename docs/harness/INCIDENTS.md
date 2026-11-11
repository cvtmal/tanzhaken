# Incidents

What went wrong in a harness run, why, and what changed so it does not happen again. Newest last.

## I-1 · 2026-11-11 · Handover stalled for 7½ hours

**What happened.** CTO-1 handed over at about 200k tokens. It pushed the handover commit (`5fef838`) at 00:25, sent the last teammate (`c02-impl`) its shutdown request, and ended its turn to wait for the shutdown confirmation before starting CTO-2. Nothing woke it. The owner noticed at 07:55 and asked; CTO-1 then saw that `c02-impl` had been inactive since 00:25, started CTO-2, and CTO-2 was active within a minute (`8f30ea2`, 07:55). The lanes did no work for about 7½ hours. No work was lost: #2's phases 1–4 were committed in its lane.

**Why.**
1. CTO-1 had already deleted its watchdog cron (handover step 1), so ending the turn left no wake-up at all.
2. It waited on a message (the teammate's shutdown confirmation) instead of on a state it could check. Either the confirmation never arrived or it arrived without starting a new turn. This was not verified.
3. `CTO.md` §5 says to `TaskStop` a teammate that has not exited within a minute. That cannot happen if the CTO is not awake a minute later.

**What changed.**
- `CTO.md` §4.6 and §9: never end a turn while waiting for anything, unless a wake-up is armed. That means a watchdog cron, or a background command that ends when the awaited state is reached (or after a time limit).
- `CTO.md` §9 step 1: the watchdog is deleted only after every teammate's `ctx` row is idle or ended. Shutdown is confirmed by checking `ctx` with a background wait of at most 2 minutes, then `TaskStop`. A message is not enough. The successor is then started in the same turn.

**Sign for next time.** A CTO turn that ends with "waiting for X" and no armed timer or background command is this incident about to repeat.
