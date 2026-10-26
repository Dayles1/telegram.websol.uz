---
name: ui-console-design
description: Design system for this panel's web UI ("Console" language, ported from Postix driver-check). Load before writing or changing any Blade view, Blade component, CSS or Alpine page script in resources/.
---

# UI: the "Console" design language

The panel uses one design, ported from the Postix driver-check module
(`resources/css/driver-check.css` + `resources/views/components/driver-check/*` there).
Do not reintroduce Bootstrap, Font Awesome, CDN links, inline `<style>` or inline `onclick`.

## Stack

- Blade + Blade components (`resources/views/components/ui/*`, used as `<x-ui.button>`).
- Alpine 3 for behaviour. One page = one factory in `resources/js/pages/<page>.js`,
  mounted as `x-data="sendPage({ endpoints: @js([...]), t: @js(__('send')) })"`.
- Tailwind v4 via `@tailwindcss/vite`, tokens in `resources/css/app.css` `@theme`.
- Everything goes through Vite (`@vite([...])`). No CDN.
- Shared JS helpers: `resources/js/lib/http.js` (`requestJson` with CSRF from the meta tag,
  JSON errors to a single shape), `resources/js/lib/list-page.js` (search, filters, pagination, polling).

## Language

- A dark ink top bar, a light work surface, **one** teal signal colour.
- Hairline borders instead of shadows, small radii, dense tables.
- Numbers in tabular figures (`tabular-nums`), labels small and uppercase.
- Colour carries meaning only (status, danger). Decoration is grey.

## Tokens

Fonts: sans `'Segoe UI Variable Text','Segoe UI',Inter,system-ui,sans-serif`;
mono `'Cascadia Mono','SF Mono',ui-monospace,Consolas,monospace`.
Body 14px / 1.5. Page title 22px (24px from `sm`), semibold, tracking -0.015em.
Secondary text 13px gray-500. Badges 11px semibold.

Radii: xs 2, sm 3, md 4, lg 5, xl 6, 2xl 8, 3xl 12 (px).

| Scale | 25 | 50 | 100 | 200 | 300 | 400 | 500 | 600 | 700 | 800 | 900 | 950 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| gray (warm graphite) | #fbfbfa | #f5f5f2 | #ecece8 | #dfdfda | #c9c9c3 | #9d9d96 | #71716b | #54544e | #3c3c37 | #262623 | #181816 | #0f0f0d |

Brand (deep teal): 50 #e2f5f7, 100 #bfe9ee, 200 #8fd7e0, 300 #52bfcc, 400 #1fa2b3,
500 #0b8797, 600 #086c7a, 700 #085663, 900 #0a3841.

Status: success 500 #1f8f4e / 600 #177640; error 500 #d93a25 / 600 #b82d1b;
warning 500 #cf8c06; info 500 #2f6fde; orange 500 #e0600f.

Surfaces: `--ink #11120f`, `--ink-2 #1a1b18`, `--ink-text #d7d8d2`, `--ink-dim #8d8e86`,
`--surface #fff`, `--canvas #f3f3ef`, `--line #e3e3de`, `--line-strong #cfcfc8`.
Dark mode (class `html.dark`): surface #171715, canvas #0e0e0c, line #272724.
Top bar 52px, sub-bar 42px. Focus ring 2px brand-500/60. Selection brand-200.

## Components (`x-ui.*`)

Port from Postix and keep their contracts: `button`, `badge`, `page-header`, `surface`,
`field`, `input`, `select`, `search`, `filters`, `list-toolbar`, `pagination`, `modal`,
`empty-state`, `error-alert`, `copy-button`, `kv`, `stat-card`, `skeleton-rows`, `icon`
(inline SVG set), `avatar`, `row-action`.

- **Button** variants: `primary` (ink: gray-900 bg, white text; inverted in dark) is the single
  "do it" of a view; `secondary` (hairline) is the everyday action; `ghost` for quiet/repeated;
  `danger` / `success` only where the colour is the message.
  Sizes: 40px on touch, 36px from `sm` up; `sm` = 32px.
- **Page header**: title + one quiet line; actions on the right, secondary first, primary last.
- **Status badges** map a status enum to a tone in one place (an enum method `tone()` or a
  single PHP/JS map), never per view.

## Rules

- Every user-visible string goes through `__()` (`lang/{uz,ru,en}/*.php`); pass the page's
  group to Alpine with `@js(__('group'))`. No hardcoded Uzbek/Russian/English in views or JS.
- Escape everything: `{{ }}` only. Never put server data inside `onclick="...('{{ }}')"`;
  pass data through `@js()` into `x-data`. No `innerHTML` with server strings; use `x-text`.
- Polling updates data, not markup: fetch JSON and let Alpine re-render. Never replace a
  table's HTML (it destroys open modals).
- Confirm destructive actions with `x-ui.modal`, not `confirm()`. Copy feedback with a toast,
  not `alert()`.
- Pagination keeps the query string (`->withQueryString()`).
- Mobile: works at 375px; tables collapse to cards or scroll inside their surface, never the page.
- Before calling a page done, open it in light and dark mode and at phone width.
