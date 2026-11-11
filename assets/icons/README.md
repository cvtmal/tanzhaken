# Icons

`sprite.svg` is one external SVG sprite, used through the Twig function `icon()` (`src/Shared/Twig/IconExtension.php`):
`{{ icon('chevron-right') }}` (decorative, `aria-hidden`) or `{{ icon('close', 'layout.flash.dismiss'|trans) }}` (labelled, `role="img"`).

- Source: Material Symbols, `https://github.com/google/material-design-icons`, folder `symbols/web/`.
- URL pattern: `https://raw.githubusercontent.com/google/material-design-icons/master/symbols/web/<name>/materialsymbolsrounded/<name>_24px.svg` (outlined) and `<name>_fill1_24px.svg` (filled).
- Style Rounded, weight 400, optical size 24 px, `viewBox="0 -960 960 960"`, no `fill` attribute (paths inherit `currentColor`).
- Licence: Apache License 2.0, full text in `LICENSE`.
- Downloaded: 2026-11-10.
- Deviation from the design: the design sets outlined icons at weight 300; no verified SVG source for weight 300 exists, so outlined icons use weight 400.

## Ids

The symbol id is the Material name with `_` replaced by `-`; a filled variant adds `-fill`.

Outlined (32): `add arrow-back arrow-forward calendar-month calendar-today check check-circle chevron-right close confirmation-number edit error expand-more fullscreen group history home info location-on lock more-horiz person person-add qr-code-2 remove repeat schedule search timer undo visibility warning`

Filled (5): `home-fill calendar-month-fill group-fill person-fill check-circle-fill`

## Adding an icon

1. Download `<name>_24px.svg` (or `<name>_fill1_24px.svg`) from the URL pattern above.
2. Append `<symbol id="<id>" viewBox="0 -960 960 960">…the file's paths without fill attributes…</symbol>` to `sprite.svg`.
3. Add the id to `tests/Unit/Shared/Twig/IconSpriteTest.php` and to the icon list in `templates/styleguide/index.html.twig`.
