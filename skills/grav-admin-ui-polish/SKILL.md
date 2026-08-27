---
name: grav-admin-ui-polish
description: >-
  Use when reviewing, auditing or polishing the admin UI of a Grav 2.0 plugin that renders inside Admin Next (admin2) — a plugin page in component mode, an add-on section, a dashboard widget, a custom field, or a blueprint settings form. Covers the review method (walk both themes plus a tablet width via the same-origin iframe trick, read-only, demo rows injected client-side when the seed is empty, then one code-side consistency pass against the host's own tokens), the ten design rules that repeat across every view (status registry instead of raw enums, the zero-value rule, one primary per screen with destructive neutral at rest, one entry point per row with a ⋯ menu, collapsed creation forms, effective state instead of stored flags, one date and number format, counts for the whole result set, prose into `<details>`, every "see X" a link, every editor a route with a dirty guard, sub-nav groups, empty states with a CTA), host fit against Admin Next's `--background/--muted/--border/--input/--ring/--success/--warning/--destructive` custom properties and its badge/button/card/table/tabs/segmented/input conventions, the `--kc-*`-style token block and constructable-stylesheet pattern, the primitives a plugin admin needs (`status()`, `rowActions()`, `emptyState()`, `.table-scroll`, route + dirty-guard helpers, formatting helpers), the `ctx.ui` / `ctx.sheet` / `ctx.route` / `ctx.navigate` / `ctx.setTitle` contract an add-on section is handed, blueprint label and help-text rules, a twelve-item rendering-defect checklist, and the host/API limitations to plan around. Trigger when the user says "review the admin UI", "polish this plugin page", "make it feel native", "make it look premium", "bring it in line with KahunaCart", or mentions Admin Next, admin2, plugin page, kc-view, web component admin UI, blueprint labels/help, status pills, dark mode contrast, rendering defects, or a UI review of a Kahuna add-on (subscriptions, licenses, payment providers).
---

# Polishing a Grav 2.0 plugin's Admin Next UI

This skill is the method and the standards from a full review-and-fix pass over the KahunaCart admin (13,000 lines of web-component UI across nineteen screens, plus a separate add-on section in its own plugin). Everything here generalises to any Grav 2.0 plugin that draws its own UI inside Admin Next — a component-mode plugin page, an add-on section hosted by another plugin, a floating widget, a custom field.

Use `grav-api-admin-next-integration` for *how* to register a plugin page, widget, field or section. This skill is about what that UI should look like once it exists, and how to tell whether it does.

The reference material is split out to keep this file readable:

- `references/checklist.md` — the audit checklist, one line per thing to look for, in the order a review walks them.
- `references/host-tokens.md` — Admin Next's tokens and components, with the "how far may a plugin diverge" verdict on each.

## TL;DR — the ten rules

1. **Labels, not stored values.** One status registry is the only route from an enum to a pill. A raw `na` or `partially_refunded` on screen means somebody wrote the pill by hand.
2. **Don't render a fact whose value is the default.** `$0.00`, an empty table with a full header row, a column that says `completed` on all 36 rows, `Priority 0` everywhere — hide the column when it has one value, hide the section when it is empty.
3. **One primary per screen; destructive is neutral at rest.** Only the armed "Confirm delete" step earns the filled destructive variant.
4. **One entry point per row.** The title opens the record; one quick action stays on the row; everything else folds into a ⋯ menu.
5. **Collapse what is empty, and let empty states carry their own CTA.** Creation forms render as a button until asked for.
6. **Show the effective state, not the stored flag.** A coupon whose window closed last week does not say "enabled".
7. **One date format, one number format, one identifier format.** `27 Aug 2026, 14:39`, never seconds, never three dialects; `#1038` everywhere an order number appears.
8. **A count is for the whole result set, or it says otherwise.** Filter chips that count the 25 rows on the current page are lying quietly.
9. **Good prose, wrong place.** Keep one line; the rest goes into a `<details>`, a `title`, or the docs with a "See docs/x.md".
10. **Every "see X" is a link, and every editor is a route with a dirty guard.** Including the link to the plugin's own settings page, and back again.

## 1. How to run a review

The method below is what produced findings dense enough to fix in one pass. It is deliberately read-only: nothing is saved, deleted or refunded, and no seed data is written.

### Walk the real thing, in both themes, at three widths

Load the plugin page in Chrome as a logged-in admin and walk every screen. Toggle the theme (Admin Next puts `dark` on `<html>`; the sun/moon control is top-right) and walk them again — a hard-coded hex that looks fine in light mode is the single most common defect and it is invisible until you switch.

For a tablet width, **Chrome MCP will not shrink the window below about 1400px**. The way around it is a same-origin iframe: open a blank tab on the same host, inject an `<iframe src="/admin/plugin/<slug>" width="768" height="1000">`, and screenshot that. The iframe is same-origin so the SPA boots normally and you can read its DOM.

```js
// in a tab already on https://<site>.test/
document.body.innerHTML =
  '<iframe src="/admin/plugin/kahunacart#/orders" style="inline-size:768px;block-size:1000px;border:0"></iframe>';
```

### Inject demo rows when the seed is empty

Half of a settings-side UI (coupons, tax zones, shipping zones) is usually empty on a demo store, and an empty list tells you nothing about how the populated one reads. Push rows into the view's own state from the console and re-render — client-side only, nothing posted:

```js
const app = document.querySelector('kahunacart-app');      // the page element
const view = app.shadowRoot.querySelector('kc-view-shipping');
view._zones = [ /* fixtures matching the API's payload */ ];
view.render();
```

Note in the report which screenshots are client-side fixtures, so nobody later mistakes them for real data.

### One code-side pass, against the host

Separately from the browser walk, read the plugin's stylesheet and its view classes against Admin Next's own `src/routes/layout.css` and `src/lib/components/ui/*`. This is the pass that finds the things a screenshot cannot: fourteen Tailwind hex literals where the host has tokens, five radii where the host has four, nineteen font sizes where the host has four, a helper that exists but which twenty-six call sites bypass. Build a **primitive inventory** — every CSS class and helper, where it is defined, and every place a view writes the markup by hand instead — and a **token comparison table** (see `references/host-tokens.md`). Those two tables are where the "conventions, not contracts" problem becomes visible.

### Findings

Every finding gets a severity, a `file:line`, and a concrete fix. Group by view, then a **cross-cutting patterns** section at the end of each report — that section is the valuable one, because a pattern that appears in three views is a rule, not a bug. Split the walk across parallel agents by view group and give each one the same brief; then reconcile their cross-cutting sections into one list.

### Fix order

Fix in this order, and commit each phase. The point of the order is that the site keeps working after every phase, and each phase makes the next one smaller:

- **Phase 0 — Foundation.** The token block, the constructable stylesheet, and the twelve rendering defects. Mechanical, touches every screen, no design decisions.
- **Phase 1 — Vocabulary.** The status registry, provider/label lookups, one date format, the zero-value rule, the blueprint and language pass. Still mechanical; now every screen *reads* right.
- **Phase 2 — Navigation.** Routes for every editor and detail, the dirty guard, cross-links, the settings entry, sub-nav groups. Now every screen is *reachable* and nothing is lost.
- **Phase 3 — Editors & lists.** Action hierarchy, ⋯ menus, empty-state CTAs, type-aware and variant-aware editors, sticky save bars, validation at the field. This is the design work, and it is much easier once phases 0–2 gave you helpers to compose.
- **Phase 4 — Section contract.** Export the primitives to add-on sections and de-fork whatever copied them.

Doing vocabulary before navigation matters: routing work touches the same functions as the label work, and doing labels first means the routing diffs stay small enough to read.

### Commit scoping

Scope every commit to `admin-next/`, `blueprints.yaml` and `languages/`. Never stage PHP you did not write — on a shared working tree another session may well be editing `classes/**` at the same time, and a `git add -A` will take it with you. `git add admin-next blueprints.yaml languages` is the habit. If the UI needs a server change (a `by-number` lookup, a facet count, an extra field on a payload), work around it client-side for now and list the API gap in the handoff rather than reaching into the PHP.

## 2. The design rules, with reasoning

### Labels, not stored values

A `label()` helper that only does `replace(/_/g, ' ')` is how `na`, `partially refunded` and lowercase `published` reach the screen. The fix is one table and one door.

```js
const STATUS_KINDS = {
    payment: {
        tones: { paid: 'ok', pending: 'warn', partially_refunded: 'info', refunded: 'muted', failed: 'bad' },
        labels: { paid: 'Paid', pending: 'Awaiting payment', partially_refunded: 'Partly refunded',
                  refunded: 'Refunded', failed: 'Failed' },
    },
    fulfillment: {
        tones: { fulfilled: 'ok', partial: 'warn', unfulfilled: 'muted', na: 'muted' },
        // `na` is an order with nothing to ship. It is a fact about the order, not a
        // missing value, so it says what it is rather than showing the enum.
        labels: { fulfilled: 'Shipped', partial: 'Partly shipped', unfulfilled: 'Not shipped', na: 'Digital' },
    },
    /** The generic on/off, so no screen invents its own word for it again. */
    toggle: { tones: { enabled: 'ok', disabled: 'muted' }, labels: { enabled: 'Enabled', disabled: 'Disabled' } },
};

status(kind, value, extra = {}) {
    const table = STATUS_KINDS[kind] || { tones: {}, labels: {} };
    const key = String(value ?? '');
    const tone = extra.tone || table.tones[key] || 'muted';
    const text = extra.label || this.statusLabel(kind, key);
    const title = extra.title ? ` title="${esc(extra.title)}"` : '';
    return `<span class="pill ${esc(tone)}"${title}>${esc(text)}</span>`;
}

/** The same words without the pill — for a filter chip, a select option, a sentence. */
statusLabel(kind, value) {
    const table = STATUS_KINDS[kind] || { tones: {}, labels: {} };
    const key = String(value ?? '');
    return table.labels[key] || this.label(key) || '—';
}
```

Three properties make this work. A value the backend grows that nobody has named yet still renders — as its own name, sentence-cased, rather than as nothing. Where the storefront already has words for a state, these are those words, so a merchant reading the admin beside the customer's copy sees one story. And add-on kinds live in the *core* table, not the add-on's, so "Suspended" in a section is the same word in the same tone as every other state in the admin.

**Before:** `<span class="pill ok">enabled</span>` written by hand in twenty-six places; four casing conventions for the same on/off concept (`enabled`/`disabled`, `on`/`off`, `Active`/`Disabled`, `Published`/`Draft`).
**After:** `${this.status('toggle', m.enabled ? 'enabled' : 'disabled')}` — and `grep -n 'class="pill' ` returns only the helper.

The same rule covers identifiers. A payment provider prints through `providerName(slug)`, never `slug`. An order number prints through `orderNo(order)` so it is always `#1038` and never a bare `1038` that reads as a count.

### The zero-value rule

Don't render a fact whose value is the default. Concretely: hide a column when every row has the same value in it; hide a section when its collection is empty; drop a `$0.00` chip rather than printing it; don't show `0 here` on a category that has no products directly in it.

**Before:** an order pane with `Items $68 · Adjustments $0.00 · Total $68 · Refunded $0.00` as chips, then three empty tables each with a full header row and a "No transactions" line.
**After:** a right-aligned totals `<dl>` with only the rows that have a value, and no Adjustments/Downloads sections at all when they are empty.

Chips are for tags, not for a totals ledger. Money that has to be reconciled goes in a `<dl>` with the label on the left and a tabular-nums figure on the right.

### One primary per screen; destructive neutral at rest

`button.danger { color: #dc2626 }` paints every Delete permanently, and a products list with eleven rows then has eleven of the most saturated things on the page competing with the one button that matters.

```css
/* Neutral until it is aimed at. */
button.danger { color: var(--muted-foreground); }
button.danger:hover, button.danger:focus-visible { color: var(--kc-bad-fg); border-color: color-mix(in srgb, var(--kc-bad) 45%, var(--border)); }
/* Only the armed second step escalates to the filled variant. */
button.primary.danger { background: var(--kc-bad); color: var(--destructive-foreground, #fff); }
```

The host has no outline-destructive variant — it only has filled `destructive`. Outline-red at rest for row-level deletes is a defensible divergence; permanent saturated red is not.

### One entry point per row, and a ⋯ menu for the rest

Eleven rows × (Edit + Unpublish + Delete) is thirty-three buttons, and they wrap to two lines at 1288px. The title is already the entry point; "Edit" beside it is redundant.

```js
rowActions(items, menuLabel = 'More actions') {
    const list = (items || []).filter(Boolean);
    if (list.length === 0) return '';
    const button = (item) => `<button type="button" class="${esc(item.class || '')}"
        ${item.attrs || ''} ${item.disabled ? 'disabled' : ''}>${esc(item.label)}</button>`;
    const quick = list.filter((i) => i.quick).map(button).join('');
    const rest = list.filter((i) => !i.quick);
    const menu = rest.length === 0 ? '' : `
        <details class="rowmenu">
            <summary role="button" aria-label="${esc(menuLabel)}" title="${esc(menuLabel)}">⋯</summary>
            <div class="rowmenu-panel" role="menu">${rest.map(button).join('')}</div>
        </details>`;
    return `<div class="row-actions">${quick}${menu}</div>`;
}
```

A `<details>` is the entire mechanism — it opens on click and on Enter, its summary is in the tab order, and it needs no library. Two details matter: pass the armed second step of a delete as `quick`, so the confirmation never hides inside something that has just closed; and position the panel `fixed` with coordinates written on open, because every list table sits inside a `.table-scroll` and a scroll container clips anything that tries to float out of it. Close it on outside click, on Escape, and on scroll or resize.

Rows that open something must also *look* clickable — `.xlink` on the title, a hover background on the row.

### Collapse creation forms; empty states carry a CTA

A "New release" form, an image uploader, a file uploader and an "Add option" form all rendering open and empty is four forms asking to be filled in on a screen the merchant opened to change a price. Render a button when the collection is empty and the form when it is asked for.

```js
emptyState(title, hint, action = null) {
    const cta = action
        ? `<div class="cta"><button type="button" class="primary" ${action.attrs || ''}>${esc(action.label)}</button></div>`
        : '';
    return `<div class="empty"><strong>${esc(title)}</strong>${hint ? esc(hint) : ''}${cta}</div>`;
}
```

`action.attrs` are the same attributes the header's own button carries, so the CTA costs no extra binding — `{ label: 'New coupon', attrs: 'data-new' }`. An empty list that only explains itself makes the reader go looking for a button 300px away.

### Show the effective state, not the stored flag

A coupon list that reads `c.enabled ? 'enabled' : 'disabled'` will show a green "enabled" pill on a code that expired two days ago and hit its 100/100 usage cap. The merchant's one question is "will this work at checkout right now?", and the column has to answer it — computed the same way the storefront computes it.

```js
couponStatus(c) {
    const now = Math.floor(Date.now() / 1000);
    if (!c.enabled) return 'disabled';
    if (c.usage_limit && c.usage_count >= c.usage_limit) return 'exhausted';
    if (c.starts_at && c.starts_at > now) return 'scheduled';
    if (c.ends_at && c.ends_at < now) return 'expired';
    return 'live';
}
```

Same rule elsewhere: a disabled shipping method gets `tr.disabled { opacity: .55 }` so the eye skips it rather than having to read the pill; a payments screen states which provider is the effective default, including the "first registered when nothing is set" fallback, rather than offering "Make default" on all of them.

### One date format, one number format

```js
const DATE_TIME_FORMAT = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const DATE_FORMAT = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });

function formatEpoch(epoch, withTime = true) {
    if (epoch === null || epoch === undefined || epoch === '') return '—';
    const d = new Date(Number(epoch) * 1000);
    if (Number.isNaN(d.getTime())) return '—';
    return withTime ? DATE_TIME_FORMAT.format(d) : DATE_FORMAT.format(d);
}
```

`toLocaleString()` prints seconds, which nobody reads off a list and which pushes the column to three lines at 768px. `dateStyle: 'medium'` also spells the month, so a store selling to both sides of the Atlantic never has to guess which half of `8/9/2026` is the day. Build the formatters once at module scope — constructing an `Intl` formatter per table row is the expensive part. Seconds belong only in a `title` on a transaction row.

Ranges get read out rather than printed as an expression:

```js
/** "always", "from 26 Oct", "until 25 Aug 2026", "28 Jul – 6 Sep 2026". */
windowText(start, end) {
    // The year is dropped only when every date in the window sits in the current one —
    // a window that crosses new year has to say so.
    if (!start && !end) return 'always';
    if (start && !end) return `from ${day(start)}`;
    if (!start && end) return `until ${day(end)}`;
    return `${day(start)} – ${day(end)}`;
}
```

Numbers follow the same instinct. A chart gridline is a magnitude to hold a bar against, not a figure to reconcile, so `formatAxisMoney()` drops the minor units on a whole amount and keeps them only when they are the difference between two lines. A stored rate of `8.8750` prints as `8.875%` and `19.0000` prints as `19%` (`formatPercent()`). Grams become kilograms past 1,000. `td.num` gets `font-variant-numeric: tabular-nums` and `white-space: nowrap` so "143 / 500" never breaks after the slash.

### Counts are for the whole result set

Filter chips computed client-side over the 25 loaded rows say `pending 5` on page 1 and `pending 3` on page 2. Either send the filter to the API and read true counts back from a `facets` field, or label the chips "on this page". Anywhere a count is a link — a category's product count, a tag's — it should navigate to the filtered list (`#/products?category=<id>`), which means the list needs that filter to exist in the first place.

### Prose to `<details>`

Instructional copy under Tags, Releases, Options, sales precedence, shipping bands and key formats is usually well written and read exactly once. Keep the one line that changes what the merchant does — "First matching band wins; blank upper weight = no limit." — and put the rest in a `<details>`, a `title`, or the docs.

### Every "see X" is a link

"…the flat-rate fallback from the plugin settings is active" appearing five times with no link is the single most common dead end in a plugin admin. One helper, used everywhere the phrase appears:

```js
settingsLink(text = 'plugin settings') {
    // A real anchor so it can be opened in a new tab; the click is handled here so the
    // admin's router moves rather than the browser reloading the whole SPA.
    return `<a href="${esc(settingsUrl())}" data-settings-link>${esc(text)}</a>`;
}
```

And state the value while you are there: "every order is charged the flat rate of 0%" beats "the fallback is active". The same rule builds the rest of the graph — category → its products, tag → its products, product → its storefront page, licence → its order and customer, order → its keys.

### Every editor is a route with a dirty guard

An editor behind a `_mode` flag has no URL. Consequences, all observed: reload lands on the list; browser Back leaves the plugin page entirely; clicking the sub-nav item for the view you are already in does nothing; and nothing can deep-link to a record.

```js
/**
 * Coupons, sales, tax zones and shipping zones are one screen written four times: a list,
 * a create form, and an editor addressed by id. A view opts in by setting `_routeBase`,
 * calling this at the end of load(), and taking a `push` argument on startCreate/startEdit.
 *
 * Idempotent: opening an editor pushes its own route and the change comes straight back
 * here, where arriving at where we already are does nothing.
 */
async applyEditorRoute() {
    const param = this.param;
    this.param = null;
    if (param === 'new') {
        if (this._mode !== 'create') this.startCreate(false); else this.retitle();
        return;
    }
    const id = Number(param);
    if (id) {
        if (this._mode === 'edit' && Number(this.editorId()) === id) { this.retitle(); return; }
        this.startEdit(id, false);
        return;
    }
    if (this._mode !== 'list') { this.closeEditor(false); return; }
    if (this._resetting) await this.load();
}
```

A sub-nav click on the view already on screen is a *request to start it over*, not a no-op — pass a `reset` flag through `routeTo()` and reload. Back and forward are requests to *be* somewhere, so the list sitting behind the pane you just closed is still the right one and refetching it there only makes the button feel slow.

The guard sits on top:

```js
/** What "unsaved" is measured against — the form, plus whatever a view keeps beside it. */
dirtyState() { return this._form; }

/** This is what saved looks like: called when an editor opens, and after a save. */
snapshot() { this._clean = stableJson(this.dirtyState()); }

isDirty() {
    if (this._clean === null || this._clean === undefined) return false;
    return stableJson(this.dirtyState()) !== this._clean;
}

async confirmLeave() {
    if (!this.isDirty()) return true;
    const ok = await askConfirm({
        title: 'Discard changes?',
        message: 'This editor has changes that have not been saved. Leaving now throws them away.',
        confirmLabel: 'Discard changes',
    });
    if (ok) this.forgetSnapshot();
    return !!ok;
}
```

The shell calls `canLeave()` before every navigation — a Back button, a sub-nav click, a hashchange — and a `beforeunload` covers the tab closing. For editors that keep variable-length rows in the DOM rather than in `_form` (a rates table, a rules list), override `dirtyState()` to return `domFields()`: every input, select and textarea keyed by its index, because adding or removing a row is a change too.

Route by the identifier the UI actually shows. If every visible number is the order number, `#/orders/1038` must work; routing by the DB id means a pasted link from a support ticket shows "Order not found".

### Sub-nav groups and a Settings entry

A flat list of twelve items with Reports between Shipping and Payments is a list nobody scans. Group them, and give the last group a bare divider rather than a label when it has nothing useful to call itself:

```js
const VIEWS = [
    { id: 'dashboard', label: 'Dashboard', tag: 'kc-view-dashboard', group: 'Store' },
    { id: 'orders',    label: 'Orders',    tag: 'kc-view-orders' },
    { id: 'products',  label: 'Products',  tag: 'kc-view-products', group: 'Catalogue' },
    { id: 'coupons',   label: 'Coupons',   tag: 'kc-view-coupons',  group: 'Promotions' },
    { id: 'tax',       label: 'Tax',       tag: 'kc-view-tax',      group: 'Setup' },
    { id: 'reports',   label: 'Reports',   tag: 'kc-view-reports',  group: '' },   // bare divider
];
```

The Settings entry belongs in that Setup group, as a real anchor to `/admin/plugins/<slug>` — it navigates the host, not the hash. And the plugin's settings page should link back. A plugin whose two halves cannot reach each other is the "where do I…" dead end merchants report most.

## 3. Host fit

Admin Next defines everything a plugin needs as CSS custom properties on `:root` and `.dark` in `src/routes/layout.css`. They cross the shadow boundary, so a web component inherits all of them for free. **Use them; hard-code nothing.**

- **Base:** `--background --foreground --card --card-foreground --popover --muted --muted-foreground --accent --accent-foreground --border --input --ring --primary --primary-foreground --secondary`
- **Semantic:** `--success --warning --destructive` and each one's `-foreground`, defined for *both* themes
- **Radius:** `--radius: 0.5rem` with `--radius-sm 4px / --radius-md 6px / --radius-lg 8px / --radius-xl 12px`
- **Type:** `--text-xs .75rem / --text-sm .875rem / --text-base 1rem / --text-lg 1.125rem`; badges are `0.6875rem`; a page `h1` is `text-xl font-semibold tracking-tight`
- **Spacing:** `--spacing: .25rem` (Tailwind v4), which is how to express control heights that match the host's: `calc(var(--spacing) * 8)` is the host's `h-8`

### The token block

Declare a plugin-prefixed block at the top of the stylesheet that derives from the host's tokens and falls back only for a host that is somehow missing them. Everything downstream refers to the plugin tokens, so a later change is one line:

```css
:host {
    --kc-ok:   var(--success, #16a34a);
    --kc-warn: var(--warning, #d97706);
    --kc-bad:  var(--destructive, #dc2626);
    --kc-info: var(--primary, #2563eb);
    /* Text shade: the raw token is a fill colour, and amber-on-dark at full strength is
       unreadable. Mixing toward the theme's own --foreground in oklab gives a shade that
       is legible in both themes from one declaration — no .dark override anywhere. */
    --kc-ok-fg:   color-mix(in oklab, var(--kc-ok) 74%, var(--foreground));
    --kc-warn-fg: color-mix(in oklab, var(--kc-warn) 62%, var(--foreground));
    --kc-bad-fg:  color-mix(in oklab, var(--kc-bad) 62%, var(--foreground));
    --kc-info-fg: color-mix(in oklab, var(--kc-info) 82%, var(--foreground));
    --kc-radius-card: var(--radius-lg, 8px);
    --kc-radius-ctl:  var(--radius-md, 6px);
    --kc-text-2xs: 0.6875rem;
    --kc-text-xs:  var(--text-xs, 0.75rem);
    --kc-text-sm:  var(--text-sm, 0.875rem);
    --kc-cell-x: 0.75rem;
    --kc-cell-y: 0.5rem;
}
```

Then every wash is `color-mix(in srgb, var(--kc-*) N%, transparent)` and every text colour is the `-fg` variant:

```css
.pill.warn  { background: color-mix(in srgb, var(--kc-warn) 20%, transparent); color: var(--kc-warn-fg); }
.notice     { background: color-mix(in srgb, var(--kc-warn) 16%, transparent); color: var(--kc-warn-fg);
              border: 1px solid color-mix(in srgb, var(--kc-warn) 35%, transparent); }
.timeline li.ok::before { background: var(--kc-ok); }
```

### What not to hard-code

Tailwind hex literals (`#22c55e`, `#f59e0b`, `#dc2626`, `#3b82f6` and their `-600`/`-700` shades) are all *light-mode* values. Nothing about them changes under `.dark`, which is why an amber `#b45309` warning stays amber-700 on a `hsl(240 6% 10.6%)` background. Also don't hard-code: a `10px` radius (the host has no such step), font sizes outside the four-step scale, `font-family` (inherit — the host is on Google Sans), or a border colour that isn't `--border`/`--input`.

### Dark mode

You need **no** `.dark` rules at all if every colour is a token or a `color-mix` of one. The one thing tokens cannot reach is native form-control chrome — a `datetime-local` field draws its own calendar glyph from `color-scheme`, and Admin Next sets none. Mirror the class onto the host element and let inheritance cross the shadow boundary:

```js
const apply = () => {
    const dark = document.documentElement.classList.contains('dark') || document.body.classList.contains('dark');
    // Unset rather than 'light' when the class is absent: the host sets no color-scheme of
    // its own, so inheriting comes out light there anyway, and a standalone harness — which
    // follows the OS preference instead of a class — keeps working in dark mode.
    this.style.colorScheme = dark ? 'dark' : '';
};
apply();
this._themeWatch = new MutationObserver(apply);
this._themeWatch.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
```

### How far may a plugin diverge

The token move is non-negotiable. Beyond that, a plugin may keep its own voice as long as it is consistent — see `references/host-tokens.md` for the full comparison, and the short version here:

- **Tables.** Host: `px-4 py-2` cells, head `bg-muted/30`, hover `bg-muted/30`, wrapped in `overflow-x-auto`. Match the density and the head background; the overflow wrapper is mandatory, not stylistic.
- **Inputs.** Host: `h-8 border-input rounded-md text-sm focus-visible:ring-1 ring-ring`. Use `--input` for the border and `--ring` for focus rather than a 2px `--primary` outline.
- **Buttons.** The host's `sm` (h-8, text-xs, rounded-md) is the right base for a dense plugin UI. `.primary` = host `default`, `.ghost` = host `ghost`, `.link` should be `--primary` like the host's. Filled `destructive` for the armed step only.
- **Badges.** Host badges are `rounded-md` 6px, `0.6875rem`, `font-medium`, with theme-aware text (`text-amber-700 dark:text-amber-400`). A 999px status lozenge is a defensible commerce-app divergence *once the colours are tokenised*; a 999px lozenge with a fixed light-mode text colour is not.
- **Eyebrows.** The host's table heads are `text-xs tracking-wide text-muted-foreground` with **no** uppercase, and card titles are `text-sm font-semibold`. Uppercasing `h3`, `h4`, `legend`, `th` and stat-tile keys all at once puts six shouted treatments on one screen and makes the plugin read louder than its host. Keep uppercase for `th` and stat keys at most.
- **Tabs and segmented controls.** The host's `Tabs` are underline (`h-0.5 bg-primary` under a `px-4 py-2.5 text-sm` item); its `SegmentedToggle` is `rounded-lg border-input bg-muted/30 p-0.5` with a sliding `bg-primary` pill. If a plugin draws tabs, draw them as underline tabs — a segmented control where the host uses tabs is the single loudest "different app" signal.
- **Page header.** The host already prints the plugin's name in its `StickyHeader`. A second brand row in the plugin's own sub-nav is two logos 60px apart; drop it.

## 4. Primitives a plugin admin should have

These are the things the twelve views stopped writing by hand. Name them, put them on the base class, and the drift stops being possible rather than being policed.

### The constructable stylesheet

```js
const SHEET = new CSSStyleSheet();
SHEET.replaceSync(STYLES);

class KcView extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this.shadowRoot.adoptedStyleSheets = [SHEET];
        // …
    }
}
```

An inline `<style>` in each view's `innerHTML` makes the browser re-parse the whole stylesheet on every keystroke-driven re-render, across every view. `adoptedStyleSheets` parses it once, is supported in every browser Admin Next targets, and — the reason it matters most — makes "hand the same sheet to an add-on section" a one-liner. The app shell adopts `[SHEET, APP_SHEET]`; a section adopts `[ctx.sheet, OWN_SHEET]`.

### `viewHead`-style header

One header structure for every screen: title and sub-line left, actions right, back link first-left on a detail screen.

```html
<div class="view-head">
  <div><h2>Order #1038</h2><div class="sub">Paid · 3 items · Ada Lovelace</div></div>
  <div class="head-actions">…</div>
</div>
```

```css
.view-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-block-end: 1rem; }
.view-head .sub { color: var(--muted-foreground); font-size: var(--kc-text-xs); }
```

Five header variants across twelve screens is what happens without it — one with a segmented control in the head, one with a bare back button outside `.head-actions`, one with an intro paragraph instead of a `.sub`, one with a Save in the header nobody else has. Pick one and make the detail screens use it too. Set `document.title` from the same place (`setTitle(detail)`), so the browser tab names what is open.

### `.table-scroll`

```css
/* A list is the widest thing this section draws, and the pane it sits in is already
   narrower than the window. Without a scroller the seven-column Orders table pushes the
   whole admin sideways at tablet widths, so every list table is wrapped in one. */
.table-scroll { overflow-x: auto; }
table { inline-size: 100%; border-collapse: collapse; font-size: var(--kc-text-sm); }
th { text-align: start; color: var(--muted-foreground); font-weight: 500; font-size: var(--kc-text-2xs);
     border-block-end: 1px solid var(--border); padding: var(--kc-cell-y) var(--kc-cell-x); white-space: nowrap; }
thead th { background: color-mix(in srgb, var(--muted) 30%, transparent); }
td { border-block-end: 1px solid color-mix(in srgb, var(--border) 60%, transparent);
     padding: var(--kc-cell-y) var(--kc-cell-x); vertical-align: middle; }
td.num, th.num { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }
/* A row for something switched off. The pill says so, but a pill is a word the eye has to
   read; fading the row lets it skip past. */
tbody tr.disabled { opacity: 0.55; }
```

Wrap every list table. The companion rule is `.oneline` on a name cell:

```css
/* A name in a table cell holds one line. Without this a squeezed column buys room for its
   neighbours by folding "Tide Chart 2027" onto three lines, and the columns past it get
   pushed off the edge with nothing to say so; with it the name truncates at the width it is
   given and the table scrolls sideways, which is what .table-scroll is there for. */
.oneline, button.xlink.oneline {
    display: block; max-inline-size: 18rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
```

Anything that is an identifier — a licence key, an SKU, a `143 / 500` usage figure — gets `white-space: nowrap` unconditionally. A key that wraps at every hyphen onto three lines is the defect people notice first.

### Form-grid label/qualifier rule

```css
/* align-items:start, not end: a two-line caption used to drag its neighbours' captions
   down with it. Checkboxes are the exception — they have no caption above them. */
.field-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 0.65rem; align-items: start; }
.field-grid > label.check { align-self: end; }
label { display: grid; gap: 0.2rem; font-size: var(--kc-text-xs); color: var(--muted-foreground); }
/* A qualifier ("optional", "0 = unlimited") belongs beside the caption it qualifies, not on
   a line of its own: stacked, it pushed the control down and left the field a row taller
   than the one next to it. */
label:has(> .meta) { grid-template-columns: auto 1fr; column-gap: 0.35rem; }
/* Grid properties only, deliberately: the same selector also matches the checkbox rows in a
   .checklist, which stay flex — anything that works on a flex item too would knock their
   counts out of line. */
label:has(> .meta) > .meta { grid-row: 1; grid-column: 2; justify-self: start; }
label:has(> .meta) > input, label:has(> .meta) > select, label:has(> .meta) > textarea,
label:has(> .meta) > .checklist { grid-column: 1 / -1; }
/* One-line fields (a date range, "move an existing file in") keep the caption beside the
   control rather than above it — and replace nine dead inline style="inline-size:auto". */
label.inline { display: flex; align-items: center; gap: 0.35rem; }
label.inline > input, label.inline > select { inline-size: auto; flex: none; }
```

### Formatting helpers

Module-scope functions, exposed on the base class as short methods (`money()`, `date()`, `day()`) and re-exported to sections: `formatMoney`, `formatAxisMoney`, `formatPercent`, `formatEpoch`, `formatDate`, `epochToLocalInput`, `localInputToEpoch`, `currencySymbol`, `niceCeil`, `decimalPattern`, `windowText`, `orderNo`, `orderRef`, `orderRoute`, `providerName`. The rule is that no view does its own arithmetic on a stored value — if a second view needs the same kind of number, the helper moves up.

### One confirm dialog

Admin Next exposes `window.__GRAV_DIALOGS`. Use it, with an in-page fallback for a host that hasn't got it (and for a standalone harness):

```js
async function askConfirm({ title, message, confirmLabel, cancelLabel }) {
    const api = dialogs();
    if (api) {
        try {
            return await api.confirm({ title, message, confirmLabel, cancelLabel, variant: 'destructive' });
        } catch (e) {
            return false;   // a dialog that could not be shown is not a yes
        }
    }
    return askFallback({ title, message, confirmLabel, cancelLabel });
}
```

Never `window.confirm()`. And never leave two confirm patterns coexisting — an inline armed two-step in the core views and a modal dialog in an add-on is two apps.

## 5. Add-on sections: the `ctx` contract

A host plugin that lets other plugins contribute a section must hand over its *UI*, not just its API client. Handing over only `apiFetch` is how an add-on ends up carrying a 240-line copy of the host's stylesheet and seven re-implemented helpers — one of which had its arguments in the other order.

Document the contract in a comment block right above the object that implements it:

```
 SECTION CONTRACT v1 — what a section can rely on

 ctx.apiFetch(method, path, body)  the host's authenticated client; resolves the unwrapped
                                   payload, throws with .status
 ctx.apiBlob(path)                 the same, for a file download
 ctx.currency / ctx.exponent       the store's money settings
 ctx.providers                     registered payment providers, by slug
 ctx.languages / ctx.defaultLanguage
 ctx.base                          the API base URL
 ctx.route                         this section's own sub-route
 ctx.version / ctx.settingsUrl
 ctx.sectionId                     the id this section was registered under
 ctx.navigate(route)               move the shell — '/orders/1038'
 ctx.sectionRoute(sub)             write this section's own sub-route
 ctx.setTitle(text)                name the browser tab after what is open
 ctx.sheet                         the core stylesheet, for adoptedStyleSheets
 ctx.ui.*                          plain functions and string builders, safe to destructure

 Anything printed goes through ctx.ui.esc(). Anything a merchant reads as a state goes
 through ctx.ui.status(kind, value) so an add-on's vocabulary and the core's are the same
 words in the same tones. A section that draws its own pill, its own pager or its own empty
 state is a section that will drift.
```

The implementation borrows from the base class rather than copying it, and lists the borrowed names explicitly so what a section is promised is a decision rather than an accident of what the class happens to have on it this month:

```js
class KcUi {
    constructor(host, ctx) {
        this.host = host;
        this.shadowRoot = host.shadowRoot;   // the binders need a root to query
        this._ctx = ctx || {};
        this.onNotice = null;                // where a message goes with no host toaster
        this.esc = esc; this.num = num; this.icon = icon;
        this.formatMoney = formatMoney; this.formatEpoch = formatEpoch; this.formatDate = formatDate;
        this.confirm = askConfirm; this.form = askForm;
        this.toast = { ok: (m) => this.notifyOk(m), fail: (m) => this.notifyFail(m) };
    }
    release() { /* drop the document-level listeners the row menus install */ }
}

[
    '$', '$$', 'on',
    'label', 'money', 'dec', 'date', 'day', 'windowText',
    'status', 'statusLabel', 'provider', 'providerName', 'orderNo', 'orderRef', 'orderRoute',
    'emptyState', 'pager', 'skeleton', 'rowActions',
    'copyable', 'copyButton', 'bindCopyButtons', 'bindRowMenus', 'placeRowMenu',
].forEach((name) => { KcUi.prototype[name] = KcView.prototype[name]; });
```

What a section cannot borrow is the two things those helpers reach for on `this` — a shadow root to query, and somewhere to put a message when there is no toaster — so the contract object supplies both.

### How a section adopts it

```js
/** The UI contract, held at module scope so the templates read the way the core's own do —
 *  `esc(value)`, not `this._ctx.ui.esc(value)` three hundred times over. These are bindings,
 *  not copies: each forwards to the identical function the core views call. */
let UI = null;
const esc = (value) => UI.esc(value);
const num = (value) => UI.num(value);
const formatEpoch = (epoch, withTime = true) => UI.formatEpoch(epoch, withTime);

set ctx(value) {
    this._ctx = value;
    UI = value && value.ui ? value.ui : null;
    if (UI) {
        // Core first, own second, so a section-specific rule can refine a core one and
        // never has to fight it.
        this.shadowRoot.adoptedStyleSheets = [value.sheet, LICENSES_SHEET];
        UI.onNotice = (kind, message) => {
            if (kind === 'ok') this._notice = message; else this._error = message;
            this.render();
        };
    }
    this._boot();
}

disconnectedCallback() {
    // The row menus put listeners on the document; the contract takes them off again,
    // the same way a core view does on its way out.
    if (this._ctx && this._ctx.ui) this._ctx.ui.release();
}
```

**What belongs in a section's own sheet:** only what is genuinely about the add-on's domain. After de-forking, the Licenses section went from ~240 copied rules to about 45 of its own — `.key`, `.legend`, `.files`, `.secret`, `details.key-format` — and `licenses.js` lost 616 lines. If a rule would be useful to a second section, it belongs in the core sheet.

**The host prints the heading only while loading.** A host that always renders `<div class="view-head"><h2>{label}</h2></div>` and a section that renders its own head gives the user two identical titles with a tab strip sandwiched between them.

```js
// The heading is the host's only while there is nothing else to look at. A mounted section
// writes its own — with the sub-line, the tabs and the actions that belong to whichever of
// its screens is open.
const head = this._state === 'ready'
    ? ''
    : `<div class="view-head"><h2>${esc(this.sectionLabel || this.sectionId)}</h2></div>`;
```

The host's `sectionLabel` setter should patch the existing `h2` in place rather than re-rendering, because a full render would tear down the mounted section element. Likewise, keep the section's element and re-parent it into the slot on each host render, so a host redraw never loses the section's internal state.

**Routing.** The host owns the hash; it hands the section whatever follows `#/section/<id>` on mount and again on every change via a `sectionRoute` setter → the child's `routeTo()`. The section writes back through `ctx.sectionRoute(sub)`, setting its own `_route` first so the change coming back is recognised as its own echo and does nothing. `ctx.navigate('/orders/1038')` is how a section links out into the host's other views — a licence detail that can't reach its order is a dead end.

### The harness.html gotcha

A section usually ships a standalone `harness.html` with a fake `ctx` and fixture data, so it can be developed without a Grav install. The moment the section starts depending on `ctx.ui` and `ctx.sheet`, **the harness's fake ctx must grow them too** — otherwise `UI` stays `null`, `render()` gets past its `if (!this._ctx) return` guard, and the first `UI.skeleton(4)` throws. Either import the host's UI module into the harness, or stub the handful of methods the section actually calls. Two related harness notes worth keeping in the file's comments: load both scripts as `type="module"` so the element is defined before `.ctx` is set (setting `.ctx` on an element that has not been upgraded defines an own property that shadows the class setter, and the section then sits waiting for a context it already had), and `await customElements.whenDefined(tag)` before assigning.

## 6. Blueprint and language rules

Everything here is `blueprints.yaml` plus `languages/en.yaml` — see the `grav-translations` skill for where the strings live and how lookup resolves.

- **Title Case for labels**, consistently. Admin Next's own blueprints are Title Case ("Plugin Status", "Admin Route"). Mixing "Send Order Copies To" with "Send notifications" inside one form is visible.
- **The unit goes in the label**, not buried in the help: `Stock Hold Window (minutes)`, `Purge Inactive Carts After (days)`, `Abandoned After (hours)`, `Maximum Image Size (MB)`. A merchant scanning a form should never have to read help text to know what the box wants.
- **Enabled/Disabled** for every toggle. Not "Active" on one and "Enabled" on the next.
- **Help is ≤ ~160 characters, about two rendered lines.** The voice can be good and the place still be wrong: a 462-character help string renders nine lines and leaves the whole right half of the two-column layout empty. Trim to what changes the merchant's decision and point at the doc that owns the detail — `See docs/tax.md`. Twelve strings over 300 characters is a normal finding on a mature plugin.

  ```yaml
  # before
  BUILTIN_CSS_HELP: "Loads the shipped storefront stylesheet on KahunaCart's own routes and nowhere else. It is theme-agnostic and driven by <code>--kahunacart-*</code> custom properties, so a theme can restyle it without turning it off. Disable when your theme styles the <code>kahunacart-*</code> classes itself."
  # after
  BUILTIN_CSS_HELP: "Loads the shipped storefront stylesheet on KahunaCart's routes only. A theme can restyle it through the <code>--kahunacart-*</code> properties. See docs/theming.md."
  ```

- **Cross-link both ways.** If the app says "the flat-rate fallback from the plugin settings is active", the settings help should say where the zones live, and the app's notice should be a link with the current value in it. Any place two screens can set the same value, say so in both.
- **No CLI in merchant help.** `bin/plugin kahunacart status lists the slugs` is a developer instruction in a form a shop owner uses. Point at the admin screen that already does it: "or pick one on KahunaCart → Payments". Better still, retire the free-text field in favour of the screen, so there is one place to set it.
- **Put settings where people look for them.** A "Payments" section under a **Database** tab is where nobody will look. Group by the merchant's task (Checkout & Payments / Tax & Shipping / Catalogue / Customers / Emails & Notifications / Advanced), not by the code's layering. Demo/dev sections go last.
- **`type: conditional` is currently mis-keyed by the api plugin.** In `grav-plugin-api/classes/Api/Controllers/BlueprintController.php:1570`, `$layoutTypes` is `['tabs', 'tab', 'section', 'fieldset', 'columns', 'column', 'page-exists', 'elements', 'element']` — `conditional` is missing, so a conditional container's children get the container's own path prefixed onto their names and the SPA's dirty/save check never matches them. **Do not ship a `type: conditional` container with editable children until that array includes it.** This is why a database tab still shows all ten MySQL *and* PostgreSQL fields for a SQLite store: the fix is one word in the api plugin, and the blueprint change is ready to land behind it.

## 7. The twelve rendering defects

Greppable symptoms, and the fix. All CSS, all visible on nearly every screen, roughly an hour in total. Do these first — they cost nothing and they are what "looks broken" means.

| Symptom | Cause | Fix |
|---|---|---|
| A legend shouts its own hint: "RESTRICT TO PRODUCTS LEAVE EMPTY FOR THE WHOLE CATALOGUE" | `legend { text-transform: uppercase; letter-spacing }` and the `<span class="meta">` inside it inherits both | `legend .meta { text-transform: none; letter-spacing: 0; font-weight: 400; margin-inline-start: .4rem }`, and prefix the hint with an em dash |
| `<select>` truncates to "Every", "Categ", "Percent o" | `select { inline-size: 100% }` inside an auto-layout `<td>` whose width is set by a short `<th>` | `min-inline-size` per select, `table-layout: fixed` with `<col>` widths, or render the row as a `.field-grid` instead of a table |
| Card titles render as uppercase muted column headers | a scoped `h3` rule that only sets margin/size, inheriting a global uppercase `h3` | `.provider-head h3 { text-transform: none; letter-spacing: 0; font-size: 1rem; color: var(--foreground); font-weight: 600 }` |
| The row divider stops mid-row and restarts under the buttons | `.row-actions { display: flex }` applied to the `<td>` itself, which drops the cell out of table layout | `<td><div class="row-actions">…</div></td>` |
| Identifiers wrap onto three lines; row actions clipped off-screen | no `white-space: nowrap` on key/num cells; table wider than its card | `td.key, td.num { white-space: nowrap }`, `.table-scroll` wrapper, and drop the columns that belong in the detail |
| Sub-nav stays open at tablet width and the table clips with no scroll | plugin breakpoint set lower than the host's (720px vs the host collapsing at ~900px) | collapse at `max-width: 900px`, wrap every table, hide the least useful columns below 640px |
| Native `<input type=file>` overflows its card | a file control's large intrinsic width inside `minmax(12rem, 1fr)` | `input[type=file] { max-inline-size: 100%; min-inline-size: 0 }`, or a styled "Choose file" button + filename |
| Ragged form rows: two-line captions push their input off the baseline | `.field-grid { align-items: end }` plus qualifiers on their own line | `align-items: start` and the `label:has(> .meta)` rule above |
| A segmented control shows no active state | one copy of the control sets `.active` from state and the other never did | derive `.active` from the same state on both |
| A hover-only copy button leaves a floating `·` separator | `.copy-btn { opacity: 0 }` inside a meta line whose separator sits outside the copyable span | put the separator inside the copyable span, or reorder so nothing dangles |
| Dead inline `style="inline-size:auto"` and hand-faked text links | inline styles the base rule already covers; `class="link" style="padding:0"` where an `.xlink` class exists | delete them; use the class |
| Widget chart bars grow to ~500px tall | `.chart { flex: 1 1 auto }` in a tile the host stretches | `max-height: 160px` and let the stats sit under the plot |

## 8. Known host and API limitations to plan around

- **The sidebar badge has no tooltip.** A bare `8` beside a plugin name reads as "8 notifications" and nothing in the plugin names it. There is no `badgeTitle` on the sidebar item today. Work around it by naming the number somewhere the user will see it — a "needs attention" strip on the dashboard, a pre-selected filter chip — and file the host request.
- **There is no breadcrumb hook for a plugin page.** The host's `StickyHeader` keeps saying the plugin's name however deep you are. Set `document.title` per route yourself (`setTitle(detail)` off a `_viewTitle` handed over by the shell) so at least the browser tab and history are useful, and put the record's name in the plugin's own `view-head`.
- **`scrollIntoView({ behavior: 'smooth' })` is a no-op** from inside a plugin page. The pane draws into a nested scroller inside the host shell and Chrome silently declines to animate one from in there — the link does nothing at all. Use `scrollIntoView({ block: 'center' })` and follow with `el.focus({ preventScroll: true })`. An instant jump is a jump.
- **Chrome MCP will not resize the window below ~1400px.** `resize_window` has a floor. Use the same-origin iframe trick from §1 for every narrow-width check.
- **`bin/grav clear-cache` can hang for minutes on a local site with a large cache** (it did on the KahunaCart demo). Do not run it during a UI pass — nothing under `admin-next/`, `blueprints.yaml` or `languages/` needs it. JS and CSS under `admin-next/` are picked up on a hard reload (cmd+shift+r); Chrome otherwise keeps serving the old bundle, which reads as "the change did not apply".
- **Plan the API gaps rather than reaching into the PHP.** The ones this pass worked around client-side, as a checklist of what a list-and-detail admin usually needs from its own API: a by-identifier lookup (`GET /<plugin>/orders/by-number/{n}`), filter params *and* facet counts on every list endpoint, the config values the UI reports on (`tax.rate`, `shipping.flat_rate`), per-provider `enabled` / `test_mode` / `settings_url`, and denormalised display names on list rows so the list doesn't need a second request per row.

## References

- `references/checklist.md` — the audit checklist in walk order.
- `references/host-tokens.md` — Admin Next tokens and components, with divergence verdicts.
- Host design language (read-only): `grav-admin-next/src/routes/layout.css`, `grav-admin-next/src/lib/components/ui/{badge,button,card,input}`, and `SegmentedToggle.svelte`, `Tabs.svelte`, `StickyHeader.svelte`, `ConfirmModal.svelte`, `UnsavedChangesModal.svelte`.
- Worked example: `grav-plugin-kahunacart/admin-next/pages/kahunacart.js` (token block, `STATUS_KINDS`, `KcView`, `KcUi` + the section contract block, `KcSectionHost`) and `grav-plugin-kahunacart-licenses/admin-next/sections/licenses.js` (a section built on that contract) with its `sections/harness.html`.
- `grav-api-admin-next-integration` — how to register the page, widget, field or section in the first place.
- `grav-translations` — where `label:` / `help:` strings live and how they resolve.
