---
paths:
  - "templates/**"
  - "assets/**"
---

# Frontend (S6)

- Server-rendered Twig. Every page works without JavaScript; the QR code is server-rendered SVG, and polling only enhances.
- Plain CSS with custom properties (`assets/styles/tokens.css`), one file per component, served by AssetMapper. No Node, no preprocessor, no UI kit.
- **No inline styles and no inline scripts**, so a strict CSP is possible (#19).
- JS: small vanilla ES modules, one per behaviour, loaded only by the pages that need them.
- Components are Twig partials in `templates/components/`. Layouts: `layout/teacher.html.twig`, `layout/auth.html.twig`, `layout/public.html.twig`. `base.html.twig`, `layout/*`, `components/*` and `tokens.css` belong to #2.
- Touch targets at least 44 px, no hover-only action, visible focus, `prefers-reduced-motion` respected.
- The look comes from `docs/design/`; where the design and the PRD disagree on behaviour, the PRD wins and the difference is noted.
- Check pages at 390 and 1440 px.
