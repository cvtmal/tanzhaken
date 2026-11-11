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
| O-7 | Decide whether to scrub commit `08a164e` from public history | Its `docs/rpi-cycles.md` named two of the owner's other local projects (folder names only, no content); reworded on `main` since, but rewriting history needs a force push | Left in history; names removed from the current tree and moved to a git-ignored file | open |
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
| D-011 | 2026-11-10 | c01-research | #1 imports the design with the Tweaks defaults (Plum accent, Paper QR stage); route `dashboard` stays while its tab may read "Today"; fetch/capture scripts are not committed (no Node) | Matches the design as shipped and S1 | Re-run #1 with other Tweaks values |
| D-012 | 2026-11-10 | CTO-1 | #0 requires every S1 component up front (research D2) | Later lanes then add no S1 dependency, so `composer.lock` conflicts between parallel lanes become rare | Let each cycle require its own components |
| D-013 | 2026-11-10 | CTO-1 | Route → module map for placeholders: `privacy` in Account, `lesson_show` and `course_export` in Attendance, `_styleguide` registered by #2 (research D6) | Follows S2: the register and the CSV export are Attendance; privacy belongs with account data | Move the placeholder actions before their owner cycles start |
| D-014 | 2026-11-10 | CTO-1 | Docs-only #1 implements now but is reviewed and landed only after #0 has landed and the lane is synced, so `land` runs real gates | Landing without gates is outside the git authorisation | Land #1 first with a gate-less route, which needs the owner |
| D-015 | 2026-11-10 | c01-plan | Personal data or a credential found in the design source is redacted in the committed copy with same-shape invented values; the "verbatim" rule yields | The repository is public | Commit the source unredacted after the owner checks it |
| D-016 | 2026-11-10 | c00-plan | phpstan extension-installer, no phpstan-doctrine/-symfony; Entity layer rule is a deny-list; PHP timezone forced to UTC; `make serve` refuses a busy port | Keeps later cycles out of hotspot neon files and makes S3's UTC rule hold everywhere | Add the extensions or switch the rule in a fix lane |
| D-017 | 2026-11-10 | CTO-1 | Overrides c01-plan's D9: a demo email at a registered domain (`tanzraum.ch`) is rewritten to an invented `.example` domain in the committed design copy and its PNGs | An address at a real domain may reach a real person, and the repository is public | Restore the original address from the Claude Design project |
| D-018 | 2026-11-10 | CTO-1 | Reference-project names moved from `docs/rpi-cycles.md` to the git-ignored `.claude/harness/local-references.md` | The public repo must not name the owner's other work; found by c00-impl-3's grep | Put the list back into the plan |
| D-019 | 2026-11-10 | c03-research | UUIDs stored as 16 raw bytes; one migration branched SQLite/MySQL; every FK `ON DELETE CASCADE` so account deletion (#18) is one delete; "one active check-in window per lesson" enforced in code by #8, not by a partial index | Portable across SQLite and MariaDB; nothing else in the app hard-deletes | Change the FKs to RESTRICT in a new migration and give #18 an ordered delete |
| D-020 | 2026-11-10 | CTO-1 | Hotspot table: any cycle may append its variables to `.env`/`.env.example`; #2 may edit the placeholder template and the placeholder routes test | #2's done criterion needs placeholders inside the layouts and the `_styleguide` route; env files grow with every cycle | Remove the rows from `docs/rpi-cycles.md` |
| D-021 | 2026-11-10 | c02-research | Design tokens get AA contrast fixes (Ink 3, field border, Low, focus ring); one breakpoint at 64rem; self-hosted Fontsource fonts and Material Symbols SVG sprite committed; wordmark in one style | Accessibility (A11Y-1) and no third-party resources beat pixel parity | Restore the design values in `tokens.css` |
| D-022 | 2026-11-10 | c03-plan | `audit_event` → `teacher` FK through Doctrine `resolve_target_entities` (a Shared interface resolved to `Teacher`); demo login `demo@example.com` / `tanzhaken-demo` (dev seed only) | The schema-listener route uses a DBAL 4.5 deprecated call, and tests fail on deprecations | Fallback B/C in the #3 plan |
| D-023 | 2026-11-11 | CTO-1 | S3 gains #3's rules: no `repositoryClass` on entities, repositories injected by class; raw DBAL binds UUIDs as `'uuid'` | Four W2 lanes write DBAL queries at once and would each rediscover this | Remove the bullet from S3 |
| D-024 | 2026-11-11 | CTO-2 | A `--bg` CTO edits harness files in a private linked worktree (detached at `origin/main`), pushes `HEAD:main`, then `git pull --ff-only` in the main checkout | Background sessions refuse file edits in the shared checkout (bgIsolation guard); disabling the guard was not ours to change | Set `worktree.bgIsolation: none` in `.claude/settings.json` and edit in place |
