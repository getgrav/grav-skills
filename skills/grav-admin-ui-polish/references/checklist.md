# Admin Next plugin-UI audit checklist

Walk it in this order. Each item is a thing to look for, not a thing to assume. Record every hit with `file:line`, a severity, and the concrete fix.

## 0. Setup

- [ ] Plugin loads at `https://<site>.test/admin/plugin/<slug>` with a clean console.
- [ ] Both themes available (the sun/moon control is top-right; the host puts `dark` on `<html>`).
- [ ] A narrow width is reachable — Chrome MCP will not resize below ~1400px, so use a same-origin iframe at 768px.
- [ ] Which lists are empty on the seed? Those need client-side demo rows (`view._rows = [...]; view.render()`) before their layout can be judged. Mark those screenshots as fixtures.
- [ ] Read-only rules stated: nothing saved, deleted or refunded; opening an editor and cancelling is fine.

## 1. Vocabulary

- [ ] Any raw enum on screen? (`na`, `unfulfilled`, `partially refunded`, lowercase `published`, `on`/`off`)
- [ ] Any raw slug on screen? (provider slugs, tax/shipping classes, section ids)
- [ ] Any SKU or internal id standing in for a human label?
- [ ] Is there more than one word for the same on/off concept across screens?
- [ ] Is every status pill produced by the registry helper, or are some written by hand? (`grep -n 'class="pill'`)
- [ ] Sentence case on pills; Title Case on blueprint labels — consistently, not per-screen.
- [ ] Identifiers formatted the same everywhere (`#1038`, not sometimes `1038`).

## 2. Zero-value noise

- [ ] Any column where every row has the same value?
- [ ] Any `$0.00` / `0` / `—` rendered as if it were information?
- [ ] Any empty section rendered with a full table header and a "No X" line?
- [ ] Any count of zero rendered on a parent row?
- [ ] Any column that duplicates a neighbouring column's fact? (a Type column beside a Cost pill that already says it)

## 3. Effective state

- [ ] Does any list show a stored flag where the storefront would compute something different? (an expired/exhausted/not-yet-started coupon showing "enabled")
- [ ] Do disabled rows look disabled? (`tr.disabled { opacity: .55 }`)
- [ ] Is a "default" marked where one exists, including the implicit fallback?
- [ ] Is test/sandbox mode visible where it exists?

## 4. Actions and hierarchy

- [ ] Count the buttons on the busiest list. More than one per row plus a ⋯ menu is too many.
- [ ] Is the row's title (or the row) the entry point, and does it look clickable?
- [ ] Is there exactly one primary on the screen?
- [ ] Is destructive neutral at rest, and does only the armed step escalate to a filled variant?
- [ ] Do action bars group state actions / destructive / documents, or is it one flat row?
- [ ] Do row-action clusters sit in a `<div>` inside the `<td>` (not `display:flex` on the cell)?
- [ ] Is there one confirm pattern in the whole plugin, using the host's dialog service?

## 5. Forms and editors

- [ ] Is the editor a route? Does reload land on it? Does browser Back work? Does clicking the same sub-nav item reset the view?
- [ ] Is there a dirty guard on Back, on sub-nav, on browser navigation, and on unload?
- [ ] Does Save sit where the eye ends up, or 1,300px below the fold?
- [ ] Do validation errors appear at the field, with `aria-invalid` and focus, not only as a banner at the top?
- [ ] Are creation forms collapsed until asked for?
- [ ] Are panels type-aware — does a digital product still get shipping fields?
- [ ] Does a summary form silently edit only one child record of many?
- [ ] Are free-text identifier fields backed by a `<select>` or `<datalist>` of values that exist?
- [ ] Do two-line captions push their inputs off the baseline?
- [ ] Do native `file`/`date`/`datetime-local` controls fit their column and draw in the right theme?

## 6. Empty and loading states

- [ ] Does every empty state carry the CTA for the thing the user came to do?
- [ ] Is there one empty treatment, or three? (a centred empty state, an in-table `<td class="empty">`, and a `<p class="hint">`)
- [ ] Is the loading state a skeleton, everywhere, including widgets?

## 7. Navigation and connection

- [ ] Does every "see X" / "in the plugin settings" phrase link to X?
- [ ] Is there a Settings entry in the plugin's own nav, and a link back from the settings page?
- [ ] Do counts link to a filtered list, and does that filter exist?
- [ ] Can you reach a record's public/storefront page from its editor?
- [ ] Is the sub-nav grouped, or a flat list of twelve?
- [ ] Do two unrelated nav items share a fallback icon?
- [ ] Is the plugin's name printed twice — once by the host header, once by the plugin's own brand row?
- [ ] Does `document.title` name what is open?

## 8. Numbers, dates, counts

- [ ] One date format, no seconds in a list. Seconds only in a `title`.
- [ ] One number format; `tabular-nums` and `nowrap` on numeric cells.
- [ ] Windows read out ("until 25 Aug 2026"), not printed as expressions ("— → 8/25/2026").
- [ ] Units converted where the reader thinks in the other one (grams → kg past 1,000).
- [ ] Are filter-chip counts for the whole result set, or for the loaded page?
- [ ] Do chart axes have a scale, and does one outlier flatten everything else?

## 9. Prose

- [ ] Any explanation over two lines that the reader will see on every visit?
- [ ] Any blueprint help over ~160 characters? (`grep -n '_HELP' languages/en.yaml | awk 'length > 200'`)
- [ ] Any CLI command in merchant-facing help?
- [ ] Any sentence that documents an inconsistency instead of fixing it? ("Uploading takes effect straight away, not on Save.")

## 10. Host fit (code-side pass)

- [ ] `grep -nE '#[0-9a-fA-F]{3,8}\b'` on the stylesheet — every hit is a token that should exist.
- [ ] How many distinct `border-radius` values? The host has four.
- [ ] How many distinct `font-size` values? The host has four plus a badge size.
- [ ] Are `--input` and `--ring` used on inputs, or `--border` and a `--primary` outline?
- [ ] Are `--success` / `--warning` / `--destructive` used, or reinvented?
- [ ] Is any text colour fixed rather than mixed toward `--foreground`? (check every warning/info colour in dark mode)
- [ ] Is `font-family` inherited?
- [ ] Are table cells the host's density, with a head background and an `overflow-x` wrapper?
- [ ] Do tabs render as underline tabs, or as a segmented control the host never uses there?
- [ ] Is the stylesheet adopted (`adoptedStyleSheets`) or re-inlined on every render?
- [ ] How many `style="…"` attributes are in the templates, and how many are dead?

## 11. Primitive inventory

Build the table. One row per CSS class and helper: where it is defined, which views use it, and which views write the markup by hand instead.

- [ ] Every helper that exists but is bypassed — that count is the size of the drift.
- [ ] Every helper re-implemented in a second file (a widget, an add-on section) — check argument order, it will have moved.
- [ ] Every pair of screens whose markup is byte-identical apart from placeholders.

## 12. Add-on sections

- [ ] Does the host hand over a stylesheet and a UI namespace, or only an API client?
- [ ] Does the section adopt `[ctx.sheet, OWN_SHEET]`, and is its own sheet only what is genuinely its own?
- [ ] Two headings stacked? (host prints one, section prints another)
- [ ] Are the section's sub-views routed, and can the section link back into the host's views?
- [ ] Does the section's status vocabulary come from the host's registry?
- [ ] Does the standalone harness still boot with the contract in place?
- [ ] Does the section release its document-level listeners on disconnect?

## 13. Responsive

- [ ] Does the plugin's own nav collapse at or before the width the host collapses at?
- [ ] Does every table scroll rather than clipping?
- [ ] Do the least useful columns hide at the narrowest width?
- [ ] Do action clusters stay on one line inside the scroller?

## 14. Dark mode

- [ ] Walk every screen again. Note every colour that did not change.
- [ ] Check warning and info text specifically — those are the two that fail.
- [ ] Check native controls (date pickers, file buttons) for light chrome on a dark field.
- [ ] Check chart ticks and any small muted text for contrast.
