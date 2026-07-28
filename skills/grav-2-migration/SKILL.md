---
name: grav-2-migration
description: Use when migrating an existing Grav 1.7/1.8 site to Grav 2.0 by hand — the agent-driven equivalent of what the `migrate-grav` plugin's wizard does, for sites where core is swapped in place (git checkout, deploy pipeline, rsync) rather than staged in a subdirectory and promoted. Covers the whole sequence: preflight and a restore point, swapping core, bringing plugins/themes to their 2.0 releases and replacing classic `admin` with `admin2` + `api`, translating classic `admin.*` account and group permissions into the `api.*` set 2.0 actually enforces (including the `api.access` gate no 1.x permission corresponds to), reopening the `security.twig_content` gates and seeding `security.twig_sandbox` allowlists from the site's own content, `system.images.url_actions`, the GFM `tagfilter`, and verifying the result. Trigger when the user says "migrate this site to Grav 2", "upgrade my 1.7 site to 2.0", asks to do what migrate-grav does without running the plugin, or asks why Twig in content, image resizing, raw HTML, or a user's permissions broke after moving to Grav 2.0.
---

# Migrating a Grav 1.7/1.8 site to Grav 2.0 in place

The `migrate-grav` plugin stages a fresh Grav 2.0 in a subdirectory, transforms a copy of `user/` inside it, and promotes it over the webroot. That is the right tool for a plain hosted site, and if the user has no version control and no easy restore path, say so and point them at it: `bin/gpm install migrate-grav`.

This skill is the same migration for a site whose **core is managed out of band** — a git checkout, a deploy pipeline, a Docker image — where the operator swaps `system/`, `vendor/`, `bin/` and the root files themselves and leaves `user/` alone. `user/` is where all the real work is, and none of it happens automatically.

**What breaks if you swap core and stop there** — every one of these is silent, and all of them are fixable in `user/`:

| Symptom after the swap | Cause |
|---|---|
| `/admin` 404s, or admin loads but every action 403s | classic `admin` doesn't run on 2.0; `admin2` + `api` aren't installed; accounts hold `admin.*` permissions the API never reads, and nothing holds `api.access` |
| Twig in page content renders as literal `{{ … }}`, or shows a sandbox placeholder | `security.twig_content.process_enabled` defaults to **off** in 2.0, and the sandbox allowlists don't include the site's custom functions/filters/methods |
| `?cropResize=…` image URLs stop transforming | `system.images.url_actions` defaults to **off** |
| `<style>` / `<iframe>` blocks render as visible source | 2.0 enables the GFM `tagfilter` |
| A user's admin UI language resets to the site default | admin2 reads `admin_next.preferences.adminLanguage`, not the classic top-level `language` |
| Admin is at `/admin` again despite a custom route | admin2 reads `admin2.yaml`, not `admin.yaml` |

## Two scripts do the mechanical parts

Both live in this skill's own `scripts/` directory (`<skill>` below is wherever this skill is installed — e.g. `~/.claude/skills/grav-2-migration`). They're standalone: no Grav bootstrap, they just need the site's `vendor/` for Symfony Yaml, so either can be copied to a server and run there.

```bash
# read-only survey — run it first, and again after every step
php <skill>/scripts/grav2-scan.php --root=/path/to/site
php <skill>/scripts/grav2-scan.php --root=/path/to/site --json     # machine-readable
php <skill>/scripts/grav2-scan.php --root=/path/to/site --offline  # no GPM/registry lookups

# accounts + groups permission translation — dry-run by default
php <skill>/scripts/grav2-perms.php --root=/path/to/site
php <skill>/scripts/grav2-perms.php --root=/path/to/site --apply
```

`grav2-scan.php` never writes. It reports the six decision areas below and prints the **exact** lists to write into config. Run it on 2.0 core when you can: it then reads core's own sandbox defaults and `Utils::isDangerousFunction()` instead of falling back to built-in copies, and it refuses to print sandbox allowlists it can't build as a full union.

Everything else is a small number of targeted edits you make yourself with Edit — don't write a bespoke YAML rewriter.

## Order, and why it is this order

1. **Preflight and restore point** — nothing below is reversible without one.
2. **Swap core to 2.0** — GPM can only resolve 2.0 releases when it's running on 2.0, and the sandbox allowlists can only be written correctly when core's 2.0 defaults are on disk to merge with.
3. **Plugins & themes** — install `admin2` + `api`, update everything else, decide what to disable.
4. **Accounts & groups** — permissions, then admin language.
5. **Content-driven config** — Twig gates, sandbox allowlists, image URL actions, tagfilter, dead keys.
6. **Verify** — boot, log in as a non-super user, check the reports.

Steps 3–5 are independent of each other; do them in order anyway so the verify step has everything in place at once.

---

## Step 0 — preflight and a restore point

```bash
php -v                                      # Grav 2.0 requires PHP 8.3+ (GRAV_PHP_MIN)
git -C /path/to/site status --short         # clean? is user/ even tracked?
php <skill>/scripts/grav2-scan.php --root=/path/to/site
```

Read the scan's **BLOCKERS** section before touching anything. Then make a restore point and *say what it is*:

- **`user/` tracked in git** — a clean tree is the restore point. Confirm it, don't assume it.
- **`user/` untracked** (common — `.gitignore`d on deploy-managed sites): take a copy first. `bin/grav backup` (1.7 has it too) writes a zip to `backup/`, or `cp -a user user.pre-2.0`. Do this even when core is in git, because everything below edits `user/`.

Do not start if PHP is below 8.3. Grav 2.0 will not boot, and you'll be debugging the wrong thing.

## Step 1 — swap core to 2.0

Core is `system/`, `vendor/`, `bin/`, and the root files (`index.php`, `composer.json`, `.htaccess`, …). `user/` is **not** core — leave it exactly as it is.

Whatever mechanism the site uses (git checkout of the 2.0 tag, deploy, unzipping the release over the top), afterwards:

```bash
grep GRAV_VERSION system/defines.php        # confirm 2.0.x
ls vendor/autoload.php                      # a git checkout has no vendor/ until you build it
bin/grav composer -i                        # only if vendor/ came from git rather than a release zip
bin/grav clearcache
```

Then re-run the scanner. On 2.0 core it now reads the real sandbox baseline, and the environment section stops warning about it.

The site will be broken at this point (no working admin). That's expected — keep going.

## Step 2 — plugins & themes

### 2a. Classic admin is replaced, not upgraded

`admin` does not run on Grav 2.0. It's superseded by **`admin2`**, which requires **`api`**.

```bash
bin/gpm index                               # confirm GPM can reach the catalog on 2.0
bin/gpm install admin2 api -y
rm -rf user/plugins/admin                   # only AFTER admin2 + api are installed
```

Keep `user/config/plugins/admin.yaml` — it's harmless, and it's where the custom route lives. But admin2 reads its **own** config, so a non-default route has to be copied across:

```yaml
# user/config/plugins/admin2.yaml
enabled: true
route: /backend        # normalized to a leading slash, no trailing slash
```

Only carry a route that differs from `/admin`; that's already admin2's default. Nothing else in `admin.yaml` has an admin2 equivalent — its config surface is just `enabled` and `route`.

### 2b. Bring everything else to its 2.0 release

```bash
bin/gpm update -p -y      # plugins
bin/gpm update -t -y      # themes
```

Two things to handle around this:

- **Symlinked plugins/themes are dev clones.** GPM will unlink the symlink and extract a zip in its place. Exclude them (`bin/gpm update -p -y <slug> <slug> …` with the symlinked slugs left out) and update those repos yourself. The scanner flags them `[symlink]`.
- **Required plugins have floors.** `flex-objects` must be **1.4.0+** on Grav 2.0 — anything lower and admin2 breaks. If GPM won't bring it up (offline, blocked host), install the release directly from `trilbymedia/grav-plugin-flex-objects` (not `getgrav/` — that path 404s).

### 2c. Decide what to do with what's left

The scanner resolves each package's verdict in this priority order — the same one migrate-grav uses:

1. **curated registry** (`https://getgrav.org/gpm/compatibility/v1/_all`) — authoritative, and where supersedes live
2. **`compatibility.grav` in the blueprint** — the plugin author's own declaration
3. **inference from `dependencies.grav`** — `>=2.0` implies 2.0-compatible
4. **default: assume 1.7-only**

How strict to be with rule 4 is a judgment call — put it to the user rather than deciding silently:

| Posture | Treat "assumed 1.7-only" as | Use when |
|---|---|---|
| strict | incompatible → disable it | production cutover; you want a site that boots |
| permissive | compatible, unless the curated registry says otherwise | staging pass; most plugins that never declared 2.0 still work |
| test | compatible, always (supersedes still apply) | you're finding out what actually breaks |

**To disable rather than delete**, set `enabled: false` in `user/config/plugins/<slug>.yaml` (create it if absent, preserving any existing config). Deleting the directory throws away config the user may want back.

**Never disable these**, whatever the verdict says — disabling one is how a migration locks the operator out:

```
flex-objects  form  login  email  problems
```

If one of them still reads incompatible after the update pass, leave it enabled and report it as something to fix by hand.

### 2d. Gate: don't call the migration done until all of these hold

```
api           installed + enabled
admin2        installed + enabled
flex-objects  installed + enabled + >= 1.4.0
login         installed + enabled
```

The scanner checks exactly this and prints it under **BLOCKERS**.

## Step 3 — accounts & groups

This is where migrations quietly fail. Grav 2.0's API registers its **own** permission set; only `super`, `pages` and `users` happen to land on a name that exists in both namespaces. Copying `admin.*` to `api.*` verbatim produces keys nothing reads, so an account looks fully provisioned and is granted nothing.

```bash
php <skill>/scripts/grav2-perms.php --root=/path/to/site           # review
php <skill>/scripts/grav2-perms.php --root=/path/to/site --apply
git diff user/accounts user/config/groups.yaml                     # always review the diff
```

The script is additive and idempotent: `admin.*` entries stay (so the account still works on classic admin during a transition), and an `api.*` value the operator already set — at the target or at any ancestor of it — always wins. It rewrites through a YAML round-trip, so comments in account files are not preserved; that's why the copy in Step 0 matters.

### The mapping

| classic | becomes | note |
|---|---|---|
| `admin.login` | `api.access` | |
| `admin.super` | `api.super` | |
| `admin.pages` | `api.pages`, `api.media` | classic Pages covered media management; 2.0 splits it |
| `admin.users` | `api.users` | |
| `admin.cache` | `api.system.write` | cache clearing |
| `admin.tools` | `api.system.read` | |
| `admin.statistics` | `api.reports.read` | |
| `admin.plugins` / `admin.themes` | `api.gpm` | both collapse to one; a positive grant wins |
| `admin.maintenance` | `api.system.backup`, `api.gpm` | backups are separately gated in 2.0 |
| `admin.configuration` | `api.config` | whole namespace — they already had every section |
| `admin.configuration.<section>` | `api.config.read` | **read-only**: 2.0 has no per-section config permission, and promoting it would hand a page-config editor `system.yaml` and `security.yaml` |
| `admin.pages_twig`, `admin.impersonate` | *(unchanged)* | 2.0 still reads these under their classic name |
| anything else | `api.<same-name>` | third-party convention (flex-objects registers both); verify the plugin's 2.0 release actually registers the twin |

**`api.access` is the rule that matters.** Every endpoint checks it before the specific permission, and no 1.x permission corresponds to it — so any positive grant implies it. An account or group that comes out without `api.access` (or `api.super`) can log into Admin 2.0 and then 403 on every single action. The script warns about each one; treat that warning as a blocker, not a note.

**Groups count as much as accounts.** `user/config/groups.yaml` carries the permissions on most multi-user sites and the API resolves group access exactly like account access. Migrating accounts but not groups locks out everyone whose access came from membership.

**Admin UI language.** Classic admin stored it at the top-level `language:` key; admin2 reads `admin_next.preferences.adminLanguage`. The script copies it across and leaves the top-level key alone (Grav core still uses it). Pass `--skip-language` to do permissions only.

## Step 4 — content-driven config

Everything here is driven by what the site's own content actually does. Take the values from the scanner's sections 4–6; don't guess, and don't turn things on that the scan says aren't needed.

### 4a. Twig in page content

Grav 2.0 changed three things at once: the `security.twig_content` gate is **off** by default, a sandbox restricts what content Twig may call, and `undefined_functions` — 1.x's blanket "allow anything" escape hatch — is **gone**, so an unlisted function or filter is now a hard failure.

**Open the gate** when the source site used content Twig — any page with `process: twig: true`, or the site-wide opt-ins `pages.process.twig` / `pages.frontmatter.process_twig` in `system.yaml` **or in any `user/env/<host>/config/system.yaml` override** (env-scoped opt-ins are the most-missed case):

```yaml
# user/config/security.yaml
twig_content:
  process_enabled: true      # the master gate
  editor_enabled: false      # leave OFF — grant admin.pages_twig to specific users instead
  config_access: true        # only if content actually uses `config.` — the scan reports which pages
```

**Two layers, both needed, for a raw PHP function.** `system.twig.safe_functions` / `safe_filters` registers the function so it's callable by name at all; `security.twig_sandbox.allowed_functions` / `allowed_filters` lets *sandboxed page content* call it. A theme template only needs the first. Content needs both. `safe_functions` still exists in 2.0 (hardened) — preserve the site's existing entries and merge, never replace.

Classify every token the scan found in Twig-enabled page bodies:

| Category | Action |
|---|---|
| already in core's allowlist | nothing |
| a real PHP function (`function_exists`) | add to **both** `safe_functions`/`safe_filters` and the sandbox allowlist |
| a Twig built-in not in 2.0's defaults — `raw` is the common one | add to the sandbox allowlist |
| plugin-provided (not a PHP function) | add to the sandbox allowlist **and** report it: the durable fix is the providing plugin registering it via the `onBuildTwigSandboxPolicy` event |
| `Utils::isDangerousFunction()` — `system`, `exec`, `preg_replace`, … | **never** allowlist; report it, the content has to be reworked |
| sandbox denylist — `include()`, `source`, `template_from_string`, `constant`, `evaluate`, `read_file`, `redirect_me`, `http_response_code`, `svg_image` | **never** allowlist; report it (note this is the `include()` *function*; the `{% include %}` *tag* is allowed) |

**Object methods** (`{{ page.media['x.jpg'].cropResize(300,200).html()|raw }}`) seed `security.twig_sandbox.allowed_methods`. Grav 2.0 allow-lists the whole documented media chain on `Grav\Common\Page\Medium\Medium` — the sandbox matches by `instanceof`, so that covers every media type. Only methods 2.0's defaults don't already permit need adding. A method the scan can't map to a class (`{{ thing.render() }}` on a plugin object) can't be resolved statically; list it for the user to allowlist under the right class by hand.

> **The trap that silently breaks the sandbox.** The `twig_sandbox` lists have no blueprint field definitions, so Grav merges them **by numeric index**: the flat lists (`allowed_functions` / `allowed_filters` / `allowed_tags`) are replaced wholesale and the per-class lists (`allowed_methods` / `allowed_properties`) merge by position. A partial list in `user/config/security.yaml` therefore **drops core's defaults**. Always write the FULL union — core's list plus your additions, in core's original order. The scanner prints exactly that, and refuses to print anything when it can't read core's defaults.

**Strip the dead 1.x keys** from `user/config/system.yaml`:

- `twig.undefined_functions` and `twig.undefined_filters` — removed in 2.0 (they were the precedence flaw behind GHSA-9wg2-prc3-vx89)
- `pages.process.twig` — once the gate is on, 2.0 derives the per-page default from `security.twig_content.process_enabled`. Strip it from every file that carried it (including env overrides) so the gate is the single source of truth. Leaving it set isn't broken, just two settings doing one job.

### 4b. URL-based image actions

Grav 1.7 applied `image.jpg?cropResize=300,200` straight from the query string. 2.0 moved that behind `system.images.url_actions` (off by default), because those actions run with arguments an unauthenticated visitor controls.

The **normal** path is unaffected: a Twig/Markdown media call (`page.media['x'].cropResize(300,200)`) or a Markdown image whose file is the page's own co-located media resolves through the media object into a hashed cache URL with no query string. Only references Grav can't resolve to page media keep a literal `?action=` URL — absolute/rooted paths, `theme://` / `image://` stream paths, files that aren't co-located, and anything hand-written in a theme template.

Turn it on only when the scan finds those:

```yaml
# user/config/system.yaml
images:
  url_actions: true
```

Watch two things: mind the indentation (this key belongs at the same level as `images:`'s other direct children — a mis-indented insert here is a classic "site goes blank, YAML won't parse" bug), and note that a transform requesting more than `system.images.max_pixels` (25,000,000 default) is still refused even with the toggle on. The scan lists those separately.

### 4c. Raw HTML tags in Markdown

Grav 2.0 enables the GFM tagfilter, which escapes a fixed denylist — `title`, `textarea`, `style`, `xmp`, `iframe`, `noembed`, `noframes`, `script`, `plaintext` — so those tags render as inert text. 1.7 had no such filter, which is why a page with a `<style>` block or an `<iframe>` embed breaks *selectively* (`<div>`, `<ul>` etc. are untouched).

```yaml
# user/config/system.yaml
pages:
  markdown:
    gfm:
      tagfilter: false
```

Before flipping it, look at what was found. `style` is cosmetic; `script`, `iframe`, `noembed`, `noframes` run code or embed third-party documents. The better fix for those is moving the markup into a Twig template (templates aren't Markdown-rendered, so the filter never applies) and leaving the filter on. Present that choice rather than disabling site-wide by reflex.

Pages that inject content over `remote://` can't be scanned — their markup lives on another instance and is fetched at render time. Flag them for manual review.

### 4d. Things that only matter when staging

`system.custom_base_url` needs no attention in an in-place migration. It only breaks the *staged preview* under a subdirectory, which is why migrate-grav temporarily blanks it — there's nothing to do here.

## Step 5 — verify

```bash
bin/grav yamllinter -a          # catches any YAML you broke by hand
bin/grav clearcache
php <skill>/scripts/grav2-scan.php --root=/path/to/site   # everything should now read "already on" / "nothing to do"
```

Then, in the browser — this part can't be skipped, most of what's left only shows up at render time:

1. **Front page loads.** A blank page or 500 is almost always a `system.yaml` indentation error from step 4b. `bin/grav yamllinter -a` finds it.
2. **Log into admin** at the real route (`/admin`, or the carried custom route).
3. **Log in as a non-super user.** This is the check that catches a broken permission migration — a super user works no matter how wrong the mapping is.
4. **The "Twig in Content" report** (Admin → Tools → Reports; served by the api plugin's `ReportsController`). It lists every page still leaking raw Twig and every sandbox block, each with a one-click *Add to allowlist*, plus a *Scan content* action for anything not yet exercised. It supersedes the migration-time heuristic for whatever is left.
5. **`logs/security.log`** — sandbox violations land here as they happen.
6. **Spot-check a transformed image and any page that used raw HTML.**

Report what you changed and what still needs a human: unresolved sandbox methods, plugin-provided Twig functions whose plugin hasn't been updated, dangerous functions in content that must be reworked, disabled plugins, and any account or group without `api.access`.

## If it goes wrong

Restore from the Step 0 copy (`user.pre-2.0`, the backup zip, or `git checkout -- user/`) and put core back at the 1.7/1.8 tag. Nothing in this process is a one-way door as long as that restore point exists — which is the entire reason Step 0 comes first.

## Gotchas

- **Symlinked plugins/themes** are dev clones. GPM replaces the symlink with a zip extract. Exclude them and update the source repos.
- **`user/env/<host>/config/system.yaml`** overrides are the most-missed input: Twig opt-ins and `safe_functions` live there too on multi-environment sites. The scan reads them; don't hand-check only the top-level file.
- **Content Twig that throws a `SyntaxError` on quoted strings** is usually smartypants, not the migration: with `system.pages.markdown.extra` + smartypants and `twig_first: false`, Markdown runs before Twig and converts straight quotes inside `{{ }}` / `{% %}` to curly ones. Set `system.twig_first: true` or turn smartypants off. This bites the 1.x source site identically, so verify a page rendered before the migration too.
- **A caching plugin (`precache`, `advanced-pagecache`) can serve a stale pre-render** even with `system.cache.enabled: false`, which makes config changes look like they did nothing. `bin/grav clearcache`, or disable it while iterating.
- **Don't invent config keys.** If a 1.x setting has no 2.0 equivalent, report it rather than writing a plausible-looking key that nothing reads — that's exactly the failure mode the `admin.*` → `api.*` mapping exists to avoid.
