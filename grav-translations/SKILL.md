---
name: grav-translations
description: Use when working with translation strings in a Grav 2.0 plugin — editing `languages/<lang>.yaml` or `languages.yaml`, adding blueprint fields with `label:` / `help:` / `title:` / `text:` / `description:` props, debugging missing or humanized labels in admin2 (e.g. a label rendering as "Xss Security" instead of "XSS Security for Content"), porting a Grav 1.7 plugin's lang file to support admin2, or auditing a plugin/core for translation gaps. Covers the ICU-vs-flat lookup chain on both admin2's client and the api plugin's server, the canonical key namespaces (`PLUGIN_ADMIN.*` shared vocabulary vs `PLUGIN_<MYPLUGIN>.*` plugin-private), the dual-target (Grav 1.7 + 2.0) lang yaml shape, HTML rendering in help text, the disabled-plugin filter that keeps stale strings out of admin2, and the `i18n-blueprint-audit.mjs` script. Trigger when the user mentions translation, i18n, ICU, lang yaml, `PLUGIN_ADMIN`, or asks why a blueprint label renders wrong.
---

# Grav 2.0 translation handling

This skill captures how translation lookup actually works in Grav 2.0 with the new `api` plugin and `admin2` (admin-next) SPA. The pipeline differs from Grav 1.7's flat-key world in non-obvious ways. Use it when writing or debugging plugin lang files, blueprint references, or translation gaps.

The official docs at <https://learn.getgrav.org/20/plugins/admin-translations> cover the high-level "ICU-first" rule. This skill goes deeper: the layers that actually do the work, the gotchas that bit Grav itself, and the audit tooling.

## TL;DR

- **Plugins for Grav 2.0 only**: ship strings under `ICU.PLUGIN_<NAME>:` in `languages/en.yaml`. Blueprints reference `PLUGIN_<NAME>.SOME_KEY` (no `ICU.` prefix). Both the admin2 client and the api server check `ICU.<key>` first, so it just works.
- **Plugins targeting Grav 1.7 + 2.0**: ship parallel blocks. Top-level `PLUGIN_<NAME>:` for Grav 1 / admin classic, plus `ICU.PLUGIN_<NAME>:` for admin2. Same keys, same English.
- **Use `PLUGIN_ADMIN.*`** only for shared vocabulary that admin2 already provides (YES / NO / SAVE / DELETE / ENABLED / pages / accounts / etc.). admin2 ships ~600+ such keys under `ICU.PLUGIN_ADMIN.*`; reuse them. Don't reinvent what's already there.
- **Never hardcode English** in `label:` / `help:` / `title:` / `text:` / `description:` blueprint props. Always reference a key. Run the audit (below) to find leaks.
- **Help text supports HTML** in admin2 — bare `<code>foo</code>`, `<strong>`, etc. render as HTML (server-controlled trust model). Same precedent as admin classic's `|raw`.

## The three layers

Translation flows through three independent layers in Grav 2.0. Understanding which layer answers a given lookup is essential for debugging.

### Layer 1 — Grav core: `Languages::flattenByLang()`

Grav core walks every plugin's `languages/<lang>.yaml` and `languages.yaml` (the single-file multi-lang format), plus `system/languages/<lang>.yaml`, `user/languages/<lang>.yaml`, and theme lang files. It produces a flat dot-notation map per language. **Plugin enabled state is ignored** — every plugin's lang file gets read, even if the plugin is disabled. This is the source of the most common debugging confusion: an installed-but-disabled plugin's strings still appear in `flattenByLang()` output.

`$lang->translate('SOME.KEY')` walks this map directly. It does **not** know about ICU vs flat; it just looks up the dotted path you give it. So `$lang->translate('PLUGIN_FOO.BAR')` finds `PLUGIN_FOO.BAR` in the flat map, and `$lang->translate('ICU.PLUGIN_FOO.BAR')` finds the ICU-namespaced version. Both work; neither is preferred at this layer.

### Layer 2 — api plugin server-side: `BlueprintController::translateLabel()`

When admin2 fetches a blueprint via `GET /api/v1/blueprints/...`, the api plugin **pre-translates every string prop** (`label`, `help`, `title`, `text`, `description`, `placeholder`, `success_msg`, `error_msg`) before sending the JSON to admin2.

Lookup order:
1. `ICU.<key>` — admin2's authoritative namespace
2. `<key>` — flat lookup, **but only if the key isn't contributed exclusively by a disabled plugin** (see "Disabled-plugin filter" below)
3. `PLUGIN_API.<last-segment>` — last-resort fallback for keys the api plugin itself provides
4. Humanizer — `XSS_SECURITY` → "Xss Security", `ACCESS_ADMIN_CONFIGURATION` → "Configuration"

This is why a hardcoded English label in a blueprint (`label: My Field`) rides through verbatim, while a key reference (`label: PLUGIN_FOO.MY_FIELD`) gets resolved server-side and shipped to admin2 already-translated.

### Layer 3 — admin2 client: `i18n.svelte.ts t()`

When admin2 calls `i18n.t('PLUGIN_FOO.BAR')` from a Svelte component (rare for blueprint labels — those arrive pre-translated from layer 2 — common for admin2's own UI strings), the lookup is:

1. `ICU.<key>` — formatted via ICU MessageFormat (placeholders, plurals, select)
2. `<key>` — returned raw
3. Uppercase variant
4. Humanizer

The translation map served by `GET /api/v1/translations/{lang}` is the result of `flattenByLang()` with two filters applied: keys contributed only by disabled plugins are stripped, and flat `<key>` entries are dropped when an `ICU.<key>` shadow exists (admin2 wins; admin classic legacy values can't leak in).

## Where to put strings — decision matrix

| Scenario | Where the string lives | Blueprint references it as |
|---|---|---|
| New string for your plugin (Grav 2.0 only) | `your-plugin/languages/en.yaml` under `ICU.PLUGIN_<NAME>:` | `PLUGIN_<NAME>.MY_KEY` |
| New string for your plugin (Grav 1.7 + 2.0) | Same file, **two blocks**: top-level `PLUGIN_<NAME>:` (Grav 1) + `ICU.PLUGIN_<NAME>:` (admin2). Same keys, same English. | `PLUGIN_<NAME>.MY_KEY` |
| Reusing a shared admin label (Save, Delete, Enabled, Pages, etc.) | Don't ship anything; admin2 already has it under `ICU.PLUGIN_ADMIN.*` | `PLUGIN_ADMIN.SAVE` |
| ICU placeholder / plural / select | Ship under `ICU.PLUGIN_<NAME>:` only — flat lookup can't run MessageFormat | `PLUGIN_<NAME>.UNREAD` (called via `i18n.t('...', { n: count })` from JS) |
| Permission label | `permissions.yaml` references a key; translate via the same convention | `PLUGIN_<NAME>.ACCESS_FOO` |

**Don't use `PLUGIN_ADMIN.*` for plugin-private keys.** That namespace is for shared vocabulary admin2 owns. If you ship `PLUGIN_ADMIN.MY_PLUGIN_FOO`, you're squatting in the wrong namespace. Use `PLUGIN_<YOUR_PLUGIN>.MY_PLUGIN_FOO`.

**Reusing admin2's `PLUGIN_ADMIN.*` keys is encouraged** for terminology consistency — your plugin's "Save" button should say "Save" in every language admin2 supports, without you shipping any translation. Browse `grav-plugin-admin2/languages/en.yaml` to see what's already there.

## Plugin lang yaml shape

### Grav 2.0-only plugin

```yaml
ICU:
  PLUGIN_MYPLUGIN:
    TITLE: "My Plugin"
    GREETING: "Hello, {name}!"
    ITEMS_FOUND: "{n, plural, =0{No items} one{# item} other{# items}}"
```

### Dual-target (Grav 1.7 + 2.0) plugin

```yaml
PLUGIN_MYPLUGIN:
  TITLE: "My Plugin"
  GREETING: "Hello"                # Grav 1 has no ICU placeholders
  ITEMS_FOUND: "items found"

ICU:
  PLUGIN_MYPLUGIN:
    TITLE: "My Plugin"
    GREETING: "Hello, {name}!"
    ITEMS_FOUND: "{n, plural, =0{No items} one{# item} other{# items}}"
```

Grav 1's translation system ignores the top-level `ICU:` block entirely, so this single file works on both versions.

### Single-file multi-lang format (`languages.yaml`)

Grav supports `languages.yaml` with language codes as the top level, as an alternative to per-language files:

```yaml
en:
  PLUGIN_MYPLUGIN:
    TITLE: "My Plugin"
fr:
  PLUGIN_MYPLUGIN:
    TITLE: "Mon Plugin"
```

The api plugin reads both layouts. Per-language files (`languages/en.yaml`) are the more common modern pattern.

## Blueprint conventions

Blueprints reference plain dotted keys, **without** an `ICU.` prefix:

```yaml
form:
  fields:
    foo:
      type: text
      label: PLUGIN_MYPLUGIN.FOO_LABEL    # not ICU.PLUGIN_MYPLUGIN.FOO_LABEL
      help: PLUGIN_MYPLUGIN.FOO_HELP
```

The api plugin's `translateLabel()` adds the `ICU.` prefix internally during lookup. Keep blueprint references unprefixed so the same blueprint works on Grav 1 (admin classic flat lookup) and Grav 2 (admin2 ICU lookup).

Translatable props the api plugin auto-resolves: `label`, `help`, `title`, `text`, `description`, `placeholder`, `success_msg`, `error_msg`. Anything in `content:` is treated as raw HTML and **not** translated — if you need translation in a `display`-type field's content, compose it from translated parts in the controller, or accept that `content:` is a static-HTML escape hatch.

## HTML in help / text / description

Admin2 renders blueprint `help`, `text`, and `description` props through `{@html …}`, so inline HTML is allowed:

```yaml
help: "Set the cap to <code>0</code> to disable. Default <code>1048576</code> (1 MB)."
```

Trust model: blueprint YAML is server-controlled (filesystem deploy-time), same trust level as Twig templates. **Don't feed user-submitted strings into help text.** If a future flow takes user input and renders it as `field.help`, that path needs sanitizing first.

This matches admin classic, which used Twig's `|raw` filter for help.

## Disabled-plugin filter

Grav core's `flattenByLang()` reads every plugin's lang yaml regardless of whether the plugin is enabled. Without protection, a disabled plugin (most painfully, admin classic mid-migration on a Grav 2 site) would leak its strings into both the dictionary served to admin2 and the server-side blueprint label resolver.

The api plugin's `Grav\Plugin\Api\Services\DisabledPluginLangIndex` walks each plugin's lang yaml, buckets keys by enabled/disabled provenance, and exposes `disabledOnlyKeys($lang)` and `isDisabledOnly($key, $lang)`. Both `SystemController::translations()` (the dictionary endpoint) and `BlueprintController::translateLabel()` consult it. Result: disabled plugins are invisible to admin2.

A key contributed by **both** an enabled and a disabled plugin is kept — the enabled plugin owns it.

When debugging "this label is rendering wrong":
- If admin classic is installed (even disabled) and your blueprint references `PLUGIN_ADMIN.X`, admin classic's value is filtered out — admin2's `ICU.PLUGIN_ADMIN.X` wins, or the humanizer runs if neither has it.
- If your own plugin is the disabled one and only it ships the key, expect humanized output until you enable the plugin.

## The audit script

`grav-admin-next/scripts/i18n-blueprint-audit.mjs` is the regression-prevention tool. Two modes:

### Default — find missing key references

```bash
node scripts/i18n-blueprint-audit.mjs --grav-root /path/to/grav-site
```

Scans every blueprint yaml in `system/blueprints/`, `user/plugins/*/blueprints*.yaml`, and `user/themes/*/blueprints*.yaml` for `PLUGIN_ADMIN\.<KEY>` references. Diffs against keys present in `grav-plugin-admin2/languages/en.yaml`. Reports:

- **Portable** — referenced but absent from admin2; canonical English available in admin classic. Run `--emit-yaml` to print a paste-ready `ICU.PLUGIN_ADMIN:` block.
- **Orphan** — referenced but absent from both admin2 and admin classic. Net-new keys that need English authored, or dead references from removed fields.

Goal state: `0 portable, 0 orphan`. Exits non-zero on any orphan.

### `--hardcoded` — find blueprints with literal English

```bash
node scripts/i18n-blueprint-audit.mjs --grav-root /path/to/grav-site --hardcoded
```

Scans every blueprint for translatable props (`label`, `help`, `title`, `text`, `description`, `*_msg`) that hold a literal string instead of a `PLUGIN_*.KEY` reference. Each hit is a translation gap.

Goal state: `0 hits`. Exits non-zero on any hits. Suitable as a CI gate.

### Other flags

- `--include-admin` — also scan `user/plugins/{admin,admin-pro,admin-19}/`. Default is to exclude these; admin classic's own files are irrelevant to a Grav 2 install.
- `--json` — machine-readable output for tooling.
- `--emit-yaml` — print a paste-ready ICU block of portable missing keys (default mode only).

## Common pitfalls

### "My label renders as the humanized fallback"

`PLUGIN_FOO.MY_LABEL` rendering as "My Label" (titlecased last segment) means **none** of the lookup chain matched. Causes in order of likelihood:

1. **You forgot the `ICU.` prefix in the lang file.** Blueprint says `PLUGIN_FOO.MY_LABEL`, but lang file has `MY_LABEL` at the top level (Grav 1 style only). Admin2 looks for `ICU.PLUGIN_FOO.MY_LABEL` first — adds the parallel ICU block.
2. **Your plugin is disabled.** The disabled-plugin filter strips its keys. Enable the plugin, hard-refresh.
3. **Stale localStorage cache in admin2.** The i18n store caches `/translations/{lang}` with a checksum. If the checksum hasn't changed (rare but possible after partial deploys), the cached dict is reused. Clear `localStorage` and reload.
4. **Cache:** `bin/grav cache` clears Grav core's compiled language map. Necessary after edits to `languages/<lang>.yaml`.

### "My HTML in help text shows as escaped tags"

You're on an old admin2 build that didn't yet render `field.help` as HTML. Rebuild admin2 (`npm run build:plugin` in `grav-admin-next/`).

### "Both my flat and ICU values render the same — why ship both?"

For Grav 1.7 / admin classic. Admin classic walks the flat namespace only; admin2 walks ICU first. Drop the flat block when the plugin is Grav 2.0-only.

### "I added an ICU placeholder and it renders as `{name}` literally"

ICU placeholders only render when the caller passes params: `i18n.t('PLUGIN_FOO.GREETING', { name: 'Andy' })`. From a blueprint label or static call site, the value is returned raw. Don't put `{placeholder}` syntax in blueprint label/help — there's no caller to substitute.

### "I want to translate `content:` on a `display`-type field"

`content:` is treated as raw HTML and **not** translated by the api plugin's serializer. Either compose the HTML from translated parts in your own controller (return a pre-translated string), or use the field's `text:` / `description:` props for translatable prose alongside the static content.

## ICU MessageFormat — quick reference

Used in JS / Svelte calls when the value needs runtime substitution. Not used in blueprint label / help (those are static).

```yaml
ICU:
  PLUGIN_MYPLUGIN:
    UNREAD: "{n, plural, =0{No new messages} one{# new message} other{# new messages}}"
    GREETING: "Hello, {name}!"
    STATUS: "{type, select, page{Page} post{Post} other{Item}}"
    PERCENT: "{value, number, percent}"
```

```ts
i18n.t('PLUGIN_MYPLUGIN.UNREAD',  { n: 5 });            // "5 new messages"
i18n.t('PLUGIN_MYPLUGIN.GREETING', { name: 'Andy' });   // "Hello, Andy!"
```

Use `=0`, `=1` for exact matches before plural categories. Always include `other` as the catch-all.

## Migrating a Grav 1.7 plugin

1. **Audit the existing lang file.** Check `languages/en.yaml`. If it only has top-level `PLUGIN_<NAME>:`, the plugin is Grav-1-shaped and admin2 can't read its values.
2. **Decide target compatibility.** Dual-target (1.7 + 2.0) → keep the top-level block, add a parallel `ICU.PLUGIN_<NAME>:` block. Grav-2.0-only → move everything under `ICU:`.
3. **Don't change the keys.** Same key names, same English values. Translators get to keep their work.
4. **Audit blueprint references.** `grep -rE 'PLUGIN_[A-Z_]+\.[A-Z_]+' user/plugins/<myplugin>/` — these all need entries in your ICU block.
5. **Check shared-vocabulary use.** If your plugin references `PLUGIN_ADMIN.X`, admin2 already provides it under `ICU.PLUGIN_ADMIN.X`. No work needed.
6. **Run the audit.** `node grav-admin-next/scripts/i18n-blueprint-audit.mjs --grav-root <site>` should report 0 portable / 0 orphan after your migration.
7. **Hard-refresh admin2 in the browser** to invalidate the localStorage translation cache.

## When NOT to ship a translation

- The string never appears in user-facing UI (internal log messages, debug output, error stack frames). Keep these as English literals — translators don't need them.
- The string is a placeholder example (`placeholder: 'admin.super'` in `xss_whitelist`). Convention is to leave placeholders as literal hints; translating them often breaks their function as examples.
- The string is in a `content:` block of a `display`-type field. As above — `content:` is raw HTML, not translatable. Restructure if you need translation.

## References

- Official docs: <https://learn.getgrav.org/20/plugins/admin-translations>
- Audit script: `grav-admin-next/scripts/i18n-blueprint-audit.mjs`
- Service: `grav-plugin-api/classes/Api/Services/DisabledPluginLangIndex.php`
- Server resolver: `grav-plugin-api/classes/Api/Controllers/BlueprintController.php` (`translateLabel`)
- Client resolver: `grav-admin-next/src/lib/stores/i18n.svelte.ts` (`t`, `tHtml`, `tMaybe`)
- Translations endpoint: `grav-plugin-api/classes/Api/Controllers/SystemController.php` (`translations`)
- Canonical PLUGIN_ADMIN vocabulary: `grav-plugin-admin2/languages/en.yaml` under `ICU.PLUGIN_ADMIN`
