# UI theme — Deep Blue & Navy Blue

The ERP's brand palette. Adopted 2026-10-27 in place of the earlier indigo
accents (shell, forms, lists) and the forest-green sidebar/dashboard.

## The palette

| Role | Token | Value |
| --- | --- | --- |
| Primary (buttons, links, active menu, focus) | `--erp-color-primary` | `#1D4ED8` |
| Primary hover / pressed, accent text | `--erp-color-primary-hover` | `#1E40AF` |
| Accent as type on a soft surface | `--erp-color-primary-deep` | `#172554` |
| Soft accent surface / hover background | `--erp-color-primary-soft` / `--erp-color-hover` | `#EFF6FF` |
| Accent ring / soft border | `--erp-color-primary-ring` | `#BFDBFE` |
| Sidebar, auth shell | `--erp-color-navy` | `#0F172A` |
| Sidebar raised surface | `--erp-color-navy-raise` | `#17233B` |
| Sidebar hover | `--erp-color-navy-hover` | `#1E293B` |
| Page background | `--erp-color-bg` | `#F8FAFC` |
| Cards, forms, tables | `--erp-color-surface` | `#FFFFFF` |
| Primary text | `--erp-color-text` | `#1E293B` |
| Secondary text | `--erp-color-muted` | `#64748B` |
| Borders | `--erp-color-border` | `#E2E8F0` |

Status colours stay semantically separate and are **not** brand-tinted:
success `#047857`, warning `#B45309`, information `#0369A1`, danger `#BE123C`
(`--erp-color-success|warning|info|danger`, each with a `-soft` surface).
A red row must never look like a blue link, and an amber warning must never look
like a primary action.

## Where the tokens live

`public/css/erp-theme.css` — custom properties only, no selectors, no layout.
It is linked **first** by `resources/views/layouts/app.blade.php` and
`resources/views/layouts/guest.blade.php`, so every later static sheet and the
Vite bundle see the values before they are used.

Consumers:

| Stylesheet | How it consumes the palette |
| --- | --- |
| `public/css/erp-sidebar.css` | `--erp-nav-*` map onto the navy/primary tokens |
| `public/css/erp-user-menu.css` | `--erp-user-*` accent/navy/hover tokens |
| `public/css/erp-dropdown.css` | `--erp-list-dropdown-*` accent/hover tokens |
| `public/css/erp-list.css` | filter, date-picker and pagination accents |
| `public/css/erp-ui.css` | skip link, auth shell, top-bar fallbacks |
| `public/css/erp-student-form.css` | section rules, focus borders, badges |
| `public/css/erp-dashboard.css` | `--dashboard-*` (names kept, values retinted) |
| `resources/css/app.css` | Tailwind `@theme` mirror (see below) |

Every consumer writes a literal fallback — `var(--erp-color-primary, #1d4ed8)` —
so a stale-cached or missing token sheet can never leave a declaration invalid.

## Why the Tailwind `indigo` ramp is remapped

Around 220 Blade views spell the accent as `indigo-600`, `bg-indigo-50`,
`hover:border-indigo-400`, and three UI tests assert those literal class names in
the markup. Renaming them would be a large, risk-only refactor of screens this
pass must not touch, and the class name would still describe an indigo that is no
longer painted.

So `resources/css/app.css` overrides `--color-indigo-*` (and adds `--color-brand*`
/ `--color-navy`) to the Deep Blue family. One change repaints every existing
utility, the markup stays byte-identical, and the brand has a single place to be
retuned. Contrast pairs are preserved:

| Pair | Ratio |
| --- | --- |
| white on `indigo-600` (`#1D4ED8`) | 6.7:1 |
| `indigo-700` on `indigo-50` | 8.4:1 |
| `indigo-900` on `indigo-50` | 14:1 |
| sidebar nav text on navy | 12.0:1 |
| rail tooltip label on pill (`#F8FAFC` on `#1E293B`) | 14.0:1 |

## Changing the brand later

1. Edit the values in `public/css/erp-theme.css`.
2. Edit the mirrored ramp in the `@theme` block of `resources/css/app.css`.
3. Nothing else: no Blade view, no component, no static sheet needs to be touched.

Asset cache-busting is unchanged — the static sheets are versioned by content
hash in `layouts/app.blade.php`, so a token edit invalidates the cached URL
automatically.

## Deliberately untouched

- Module-specific semantic action colours (`Approve` green, `Mark Absent` rose,
  warning buttons amber, communication report status chips) — these encode
  meaning, not brand.
- Laravel's default `welcome.blade.php`, which is not part of the ERP screens.
