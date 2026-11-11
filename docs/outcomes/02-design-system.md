# #02 design-system — outcome (2026-11-11)

## Delivered

The design system from `docs/design/` exists as code: tokens (with WCAG 2.2 AA fixes), self-hosted fonts and an SVG icon sprite, the three layouts (teacher with sidebar ≥ 64rem and tab bar below, auth, public), a flash region, a global form theme, 18 Twig components, three small JS modules plus one for alerts, error pages in the public layout, and a styleguide at `/_styleguide` (dev/test only) that shows every component in every state. All placeholder routes render inside the teacher layout with working navigation. A test fails the build on raw text, `style="` or inline `<script>` in any template.

## Interfaces

- **Tokens:** `assets/styles/tokens.css` (`--color-*`, `--space-*`, `--radius-*`, `--text-*`, `--control-*`, shadows, `--tabbar-height`, `--panel-width`). AA changes: Ink 3 `#766D71→#6E6569` (4.79–5.58:1 on all surfaces), field line `#D9D0C2→#8F8478` (3.63:1 on Card; old value kept as `--color-line-strong`, decorative), Low solid `#D9982B→#B57F1F` (3.46:1 on Card), focus = 2 px solid accent outline, offset 2 px (9.64:1 on Paper; Paper on the dark toast).
- **CSS:** `assets/styles/app.css` imports `base`, `layout`, `fonts` and one file per component under `assets/styles/components/`. Single breakpoint `64rem`. `[hidden]` is `display:none !important`.
- **JS (importmap entries):** `app`, `password-toggle`, `dialog` (opens `<dialog>` by `data-dialog-open="<id>"`, confirm via `data-dialog-confirm`), `toast` (dismiss, 6 s auto-hide, P7), `alert` (dismissible alerts).
- **Twig:** `icon(name, label = null, class = '')` (`src/Shared/Twig/IconExtension.php`, sprite `assets/icons/sprite.svg`, ids `^[a-z0-9-]+$`, `_`→`-`, filled `<id>-fill`); global `brand_name` from env `APP_BRAND_NAME` (`.env`); `config/packages/twig_design_system.yaml` (form theme `form/theme.html.twig`, globals).
- **Layouts:** `templates/layout/{teacher,auth,public}.html.twig`, partials `_sidebar`, `_footer`, `_flashes`. Base blocks: `title`, `body_class` (write as expression `{% block body_class 'x' %}`), `main`.
- **Components** (`templates/components/`, each with a doc comment listing its params; call with `include … only` / `embed … only`):
  - `_button` label, variant primary|soft|secondary|text|toast-action, size md|lg|pill|pill-sm|compact, icon, type, disabled, attr, data
  - `_link_button` like `_button` for `<a>` (href required); `_icon_button` icon, label (aria-label), href, variant ring|plain, type, attr, data
  - `_status_badge` label, variant today|open|present|absent|not-yet|low|danger|neutral, pulse, size; `_balance_badge` balance, show_label (S9 states)
  - `_pass_dots` left, total, variant dots|bar; `_avatar` initials, size sm|md|lg
  - `_card` variant default|hero|compact|stat, href, tag, block `card_body`; `_list` label, block `list_items`; `_list_row` title, href (stretched link), meta, meta_variant, lead, block `row_trailing`
  - `_page_header` title, subtitle, back_href/back_label, breadcrumbs, level, block `page_actions`; `_empty_state` title, text, action, level
  - `_alert` text, variant info|success|warning|danger, title, dismissible; `_toast` message, icon, id, block `toast_action`
  - `_table` caption, show_caption, variant default|compact, blocks `table_head`/`table_body` (cards below 64rem, `data-label` per cell); `_dialog` id, title, variant modal|sheet, blocks `dialog_body`/`dialog_actions`
  - `_tab_bar` items, current; `_wordmark` size, href
- **Routes:** `_styleguide` (`src/Shared/Controller/StyleguideController.php`, env dev/test; `?flashes=1` adds sample flashes); `/_error/{code}` preview (dev). Error templates in `templates/bundles/TwigBundle/Exception/` (403 lock, 404 search, 500 warning, generic error); strings in `messages` `error.*`.
- **Tests:** `tests/Support/Twig/RawTextScanner.php` + `tests/Integration/Shared/TemplatesHaveNoRawTextTest.php`, `tests/Functional/Shared/{StyleguideTest,LayoutTest,ErrorPagesTest}.php`, `tests/Unit/Shared/Twig/`.

## How to use it

- A page `{% extends 'layout/teacher.html.twig' %}` and fills `{% block main %}`; it builds from the components above, never with new colours or inline styles. A page that needs JS sets `{% set page_scripts = ['dialog'] %}` at top level (P2); the teacher layout already loads `toast` and `alert`. A page with a password field loads `password-toggle`; a dismissible alert outside the teacher layout loads `alert`.
- Flashes carry already-translated text (P3): `success` → toast (stacked in one fixed `.toast-stack`), `error` → danger alert, `warning`/`info` → alert of that variant.
- Raw-text rules: every visible word, `aria-label`, `title`, `placeholder` comes from a translation; literal class names between Twig tags must be expressions; booleans that default to true use `x ?? true`, not `|default(true)`. See `.claude/rules/module-shared.md`.

## Decisions

- P4 sidebar account item shows avatar + `app.user.displayName` when present, else just "Account" (#4 adds the getter; no layout edit needed). P6 one `_dialog` for modal and sheet (sheet = right panel 432 px at ≥ 64rem). P7 toast: no auto-hide with an action, else 6 s, paused on hover/focus, always a dismiss button.
- Progressive enhancement: password toggle, toast dismiss and alert dismiss buttons are printed `hidden` and un-hidden by their script; dialogs close natively via `<form method="dialog">`. Undo: print them visible and drop `hidden`.
- Alert dismiss moved from `toast.js` to its own `alert` module (7.3), so it works on any layout. Undo: merge back.
- Success toasts render inside one fixed `.toast-stack` (column, 8 px gap) so several stack instead of overlapping (7.3). A lone `_toast` outside the stack stays fixed itself.
- Error icons lock/search/warning/error (plan named none). Password toggle has a constant label + `aria-pressed`.
- Sidebar list gap 4 px (44 px hit areas don't overlap); segment tiles 44 px min so 7 weekdays fit one row at 390 px.

## Deviations

- Icons at weight 400, not the design's 300 (no verified 300 SVG source). Wordmark in one style, not serif "Tanz" + italic "Haken" as in the boards. Tab bar uses safe-area padding instead of a fixed 30 px bottom.
- Table desktop cells get 12 px right padding (design: none). Toast padding 0 8 0 18 to fit the 44 px dismiss button the design does not have.
- `fonts.css` uses `format("woff2")` instead of `woff2-variations`.
- The sidebar account item shows only "Account" until #4 provides `displayName` (F3 shows avatar + name).

## Carried forward

- Icon weight 300 → #20. CSP nonce for `importmap()`'s inline script → #19. Timezone label/component → #10/#11/#13/#17. A3 brand panel (desktop login) → #4.
- QR card → #14/#16; name tiles → #14; pass card → #12; sticky footer actions → #13 (built from the primitives here).
- A `debug: false` test client reuses `var/cache/test` without freshness checks: after changing catalogues or templates, clear it locally once (CI starts clean).
- Real-phone check (safe-area inset, iOS Safari font rendering) → human, gate G7.
- Review minors: the raw-text scanner checks template text and double-quoted `alt/title/aria-label/placeholder` only; copy inside Twig strings (`label: 'Save'`, `{% block title 'X' %}`) and single-quoted attributes pass, so translate component params at the call site → scanner heuristics #20. Stale comment in `layout/teacher.html.twig:3` (alerts are handled by `alert.js`) → #4. Alerts (`role="alert"`) sit inside the `role="status"` flash region → #20.
- `auth` and `public` layouts have no flash region; a flash set before a redirect to `/login` is not shown → #4/#11 (layout is #2's hotspot: needs a CTO exception to add `{% include 'layout/_flashes.html.twig' %}`).
- `_dialog` body is outside any form (actions are in `<form method="dialog">`): a sheet with fields needs its own `<form id>` around the body and `form="<id>"` on the submit button → first user (#10 enrol / #13 clear with reason).

## Verification

- `make cs-fix`, `make lint` exit 0; `make test` OK (150 tests, 869 assertions). `grep -rnE 'style="|<script>' templates` and `grep -rn 'tanzhaken\.' templates assets src config` empty; prod `debug:router` has no `_styleguide`.
- Browser on 8002 (390 px and 1440 px via same-origin iframes, since the window cannot be resized): `/_styleguide`, `/_styleguide?flashes=1`, `/dashboard`, `/courses`, `/students/x`, `/login`, `/c/abc`, `/_error/403|404|500` — no horizontal scroll at either width. Compared with foundations, a1, b1/b2, d1, e1 (phases 2–4), e2 (sheet), f2 (toast above the tab bar), f3 (table, sidebar shell): match apart from the deviations above. The modal dialog (drawn on `foundations.png`, not e3) was compared in review: order, colours, 44 px/r12 actions and r22 card match; ours adds a close button and a 24 px title (board ≈ 20 px).
- Focus: all 95 focusable elements of `/_styleguide` show a visible outline (scripted focus with `focusVisible`); dialog and sheet open modal with focus on Close. Hit areas of `pill-sm`, `compact`, `toast-action` are 44 px high. Two toasts in the stack: no overlap.
- Reduced motion: the `prefers-reduced-motion: reduce` rule sets the pulse's animation to none (applied in the page and read back); no DevTools emulation was available.
- Pending human: real-phone check (G7).
