# Admin Next design tokens and components

The reference for "does this plugin fit its host". Everything below is from `grav-admin-next/src/routes/layout.css` and `grav-admin-next/src/lib/components/ui/*`. Verify against the checkout in the workspace before quoting values — the `admin2` symlink in a site's `user/plugins/` carries only the built bundle, not the source.

## Colour tokens

Declared on `:root` and swapped on `.dark`. They cross the shadow boundary, so a web component inherits all of them.

| Token | Light | Dark |
|---|---|---|
| `--background` | `hsl(0 0% 100%)` | `hsl(240 6% 10.6%)` |
| `--foreground` | `hsl(240 10% 3.9%)` | `hsl(0 0% 98%)` |
| `--card` / `--card-foreground` | `hsl(0 0% 100%)` / `hsl(240 10% 3.9%)` | `hsl(240 4.5% 13%)` / `hsl(0 0% 98%)` |
| `--popover` / `--popover-foreground` | `hsl(0 0% 100%)` / `hsl(240 10% 3.9%)` | `hsl(240 4.5% 14.5%)` / `hsl(0 0% 98%)` |
| `--primary` / `--primary-foreground` | `hsl(221 83% 53%)` / white | `hsl(217 91% 60%)` / white |
| `--secondary` / `--secondary-foreground` | `hsl(240 4.8% 95.9%)` / `hsl(240 5.9% 10%)` | `hsl(240 3.7% 15.9%)` / `hsl(0 0% 98%)` |
| `--muted` / `--muted-foreground` | `hsl(240 4.8% 95.9%)` / `hsl(240 3.8% 46.1%)` | `hsl(240 3.7% 15.9%)` / `hsl(240 5% 64.9%)` |
| `--accent` / `--accent-foreground` | `hsl(240 4.8% 95.9%)` / `hsl(240 5.9% 10%)` | `hsl(240 3.7% 15.9%)` / `hsl(0 0% 98%)` |
| `--destructive` / `--destructive-foreground` | `hsl(0 84.2% 60.2%)` / `hsl(0 0% 98%)` | `hsl(0 62.8% 30.6%)` / `hsl(0 0% 98%)` |
| `--success` / `--success-foreground` | `hsl(142 71% 40%)` / white | `hsl(142 60% 45%)` / white |
| `--warning` / `--warning-foreground` | `hsl(38 92% 48%)` / `hsl(38 92% 12%)` | `hsl(38 90% 55%)` / `hsl(38 92% 10%)` |
| `--border` | `hsl(240 5.9% 90%)` | `hsl(240 4% 20%)` |
| `--input` | `hsl(240 5.9% 90%)` | `hsl(240 4% 22%)` — deliberately lighter than `--border` |
| `--ring` | `hsl(221 83% 53%)` | `hsl(217 91% 60%)` |
| `--sidebar` + `--sidebar-foreground/-border/-accent/-accent-foreground` | — | — |

Note that `--destructive` in dark mode (`hsl(0 62.8% 30.6%)`) is a *fill* colour and much darker than the light-mode one. Never use it directly as text; mix toward `--foreground`.

## Scale tokens

| Concern | Values |
|---|---|
| Radius | `--radius: 0.5rem`, mapped to `--radius-sm: calc(--radius - 4px)` = 4px, `--radius-md` = 6px, `--radius-lg` = 8px (`--radius`), `--radius-xl` = 12px |
| Spacing | `--spacing: .25rem` (Tailwind v4). `h-8` is `calc(var(--spacing) * 8)` = 2rem |
| Type | `--text-xs .75rem`, `--text-sm .875rem`, `--text-base 1rem`, `--text-lg 1.125rem`; badge text is a literal `0.6875rem`; page `h1` is `text-xl font-semibold tracking-tight` |
| Font | `--font-sans` = Google Sans (self-hosted variable), falling back to Inter Variable / system. A plugin should `font-family: inherit` |
| Root size | `html { font-size: var(--app-font-size, 16px) }` — the user's Font Size preference. Anything sized in `rem` scales with it; anything in `px` does not |

## Components

| Component | Classes it renders |
|---|---|
| `Button` | base `inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md font-medium transition-colors focus-visible:ring-1 focus-visible:ring-ring disabled:opacity-50`. Variants: `default` `bg-primary text-primary-foreground shadow-sm`, `destructive` `bg-destructive text-destructive-foreground shadow-sm`, `outline` `border border-border bg-background shadow-sm hover:bg-accent`, `secondary`, `ghost` `hover:bg-accent`, `link` `text-primary underline-offset-4 hover:underline`. Sizes: `default h-9 px-4 py-2 text-sm`, `sm h-8 rounded-md px-3 text-xs`, `lg h-10 px-8 text-sm`, `icon h-8 w-8`. `type` defaults to `button`, not `submit` |
| `Badge` | `inline-flex items-center rounded-md px-2 py-0.5 text-[0.6875rem] font-medium`. Variants are token-pair washes with **theme-aware text**: `success` `bg-emerald-600/10 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300`, `warning` `bg-amber-500/10 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400`, `destructive` red equivalents, `default` primary equivalents, `secondary`, `outline` |
| `Card` | `rounded-lg border border-border bg-card text-card-foreground shadow-sm`; header `p-4`; title `text-sm font-semibold` |
| `Input` | `flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:opacity-50`. Dense list toolbars in the app use the `h-8 bg-muted/50` variant |
| `Tabs` | `flex border-b border-border`; item `inline-flex items-center px-4 py-2.5 text-sm font-medium`; active marker `absolute inset-x-0 -bottom-px h-0.5 bg-primary` — **underline, not segmented** |
| `SegmentedToggle` | wrapper `relative isolate inline-grid rounded-lg border border-input bg-muted/30 p-0.5`; sliding indicator `absolute top-0.5 bottom-0.5 rounded-md bg-primary shadow-sm transition-all`; item `relative z-10 rounded-md px-4 py-2 text-sm font-medium` — active is a **primary fill**, not a muted tint |
| `StickyHeader` | `sticky top-0 z-10 bg-background transition-[border-color,box-shadow]` with a sentinel above it; the page `h1` lives here, actions right, back chevron left |
| Tables (page-level, e.g. `PluginsTableView.svelte`) | `w-full text-sm`; head `bg-muted/30 text-xs tracking-wide text-muted-foreground` — **no uppercase**; cells `px-4 py-2`; row hover `bg-muted/30`; wrapped in `overflow-x-auto` |
| Empty state | `px-4 py-8 text-center text-sm text-muted-foreground` |
| Pagination | `text-xs` "x of y" plus `Button size=icon h-7` chevrons |
| Dialogs | `ConfirmModal.svelte`, `UnsavedChangesModal.svelte`, `MarkdownModal.svelte`, exposed to plugins as `window.__GRAV_DIALOGS.confirm()` / `.form()` / `.open()` |

## Divergence verdicts

Judged against the KahunaCart pass. "Reinvented" means fix it; "diverges" means make a decision and hold it everywhere.

| Concern | Typical plugin | Verdict |
|---|---|---|
| Base colour tokens | usually good — most plugins reach for `--background`, `--foreground`, `--card`, `--muted`, `--border`, `--primary` | **Good.** Add `--input`, `--ring`, `--secondary`, `--card-foreground`, which almost nobody uses |
| Semantic colours | Tailwind hex literals (`#22c55e`, `#f59e0b`, `#dc2626`, `#3b82f6` and their -600/-700 shades) — all light-mode values, none of which change under `.dark` | **Reinvented.** Non-negotiable fix. Derive `--<p>-ok/warn/bad/info` from `--success/--warning/--destructive/--primary` and mix text shades toward `--foreground` in oklab |
| Radius | five literals plus two tokens; `10px` is common and has no host equivalent | **Partly reinvented.** Cards/panels/editors → `--radius-lg`; fieldsets/banners/controls → `--radius-md`; skeletons → `--radius-sm` |
| Type scale | 19 distinct sizes is a real number from a real plugin | **Reinvented.** Collapse to `0.6875rem` (badges, eyebrows), `--text-xs`, `--text-sm`, `--text-base`, and `1.25rem` for the view title |
| Eyebrows / uppercase | `h3`, `h4`, `legend`, `th`, stat keys all uppercase + letter-spaced | **Louder than the host.** Host table heads and card titles are not uppercase. Keep it for `th` and stat keys at most |
| Buttons | a 32px `text-xs` base — which is exactly the host's `outline sm` | **Close.** Two divergences worth keeping if deliberate: a quieter `.link` (host's is `text-primary`), and an outline-destructive variant the host does not have |
| Badges / pills | `999px`, `0.72rem`, weight 600, fixed text colour | **Diverges** in radius, size, weight and theme handling. The theme handling must be fixed; the 999px lozenge is a defensible commerce voice once it is |
| Tables | tighter cells, no head background, no overflow wrapper | **Diverges.** Match density and head background; the wrapper is mandatory |
| Inputs | `--border` + a 2px `--primary` outline | **Diverges.** Use `--input` and `focus-visible` `box-shadow: 0 0 0 1px var(--ring)` |
| Segmented / tabs | a segmented control used where the host uses underline tabs | **Diverges**, and it is the loudest "different app" signal in a section |
| Cards | no shadow | **Diverges.** Host cards carry `shadow-sm` |
| Page header | plugin prints its own brand row under the host's header | **Drop it.** The host already names the page |
| Empty state | title + hint, richer than the host's one-liner | **Fine** — richer is an improvement, and a CTA makes it better |
| Pagination | "Page x of y · N total" with Previous/Next buttons | **Diverges.** The host's compact chevrons are tidier; either is defensible |
| Dark mode | token colours follow, hex colours do not | **Partial** until the semantic tokens land, then complete with no `.dark` rules at all |
| Font | `inherit` | **Correct** |
