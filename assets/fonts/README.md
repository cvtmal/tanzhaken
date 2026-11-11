# Fonts

Self-hosted web fonts (S1, S8: no Node, no third-party request at runtime). Declared in `assets/styles/fonts.css`.

| File | Family | Axes | Size |
|---|---|---|---|
| `figtree-latin-wght-normal.woff2` | Figtree | wght 300–900 | 20,156 B |
| `newsreader-latin-opsz-normal.woff2` | Newsreader | opsz, wght 200–800 | 132,000 B |
| `newsreader-latin-opsz-italic.woff2` | Newsreader italic | opsz, wght 200–800 | 146,872 B |

- Source: Fontsource variable packages, version 5.3.0, latin subset.
  - `https://cdn.jsdelivr.net/npm/@fontsource-variable/figtree@5.3.0/files/figtree-latin-wght-normal.woff2`
  - `https://cdn.jsdelivr.net/npm/@fontsource-variable/newsreader@5.3.0/files/newsreader-latin-opsz-normal.woff2`
  - `https://cdn.jsdelivr.net/npm/@fontsource-variable/newsreader@5.3.0/files/newsreader-latin-opsz-italic.woff2`
  - `@font-face` reference: `…/figtree@5.3.0/wght.css`, `…/newsreader@5.3.0/opsz.css` (latin blocks; family names shortened to `Figtree` / `Newsreader`)
- Licence: SIL Open Font License 1.1, full text in `OFL.txt` (from `…/newsreader@5.3.0/LICENSE`, with the Figtree copyright line added at the top).
- Downloaded: 2026-11-10.
- To update: download the same file names from a newer Fontsource version, update this table and keep `OFL.txt`.
