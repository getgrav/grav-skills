---
name: grav-plugin-review
description: >-
  Use when reviewing a third-party Grav 2.0 / Admin2 plugin for compatibility and security, especially a GPM (`getgrav/grav` `[add-resource]`) submission. Covers the review method (clone, map, cross-check against the real api/admin2/login plugins in the workspace), the verified API/Admin2 integration contract (which `onApi*` events exist, `AbstractApiController` shape, public-route mechanism, component-page convention, settings-panel secret-leak vector), and a findings taxonomy with concrete fixes: manifest/GPM correctness, auth granularity, secret handling, unauthenticated public writes, CSV/mail-header injection, XSS escaping, path traversal in file-serving, SVG upload, open proxies, pure-PHP SSE worker exhaustion, CSRF, message/identity spoofing, account mass-assignment, plus citizen-behavior and author-branding-in-defaults smells. Also covers how to produce a paste-ready, file:line-referenced handoff. Trigger when the user asks to review/vet/audit a Grav plugin, asks about a GPM submission, links a `grav-plugin-*` repo for review, or mentions checking a plugin for Grav 2.0 / Admin2 compatibility.
---

# Reviewing a Grav 2.0 / Admin2 plugin

Use this when vetting a third-party Grav 2.0 plugin for compatibility and security, typically a GPM `[add-resource]` submission on `getgrav/grav`. It encodes the review method, the verified Admin2/API contract to check against, and a findings taxonomy drawn from real reviews so you can be fast and concrete rather than generic.

The deliverable is usually a **paste-ready, file:line-referenced handoff** (for the maintainer or for another agent to action), not a vague prose summary. Be specific, tag severity, and credit correct practices so they don't get "fixed."

## Method

1. **Get the source.** Clone the submitted repo (`git clone --depth 1 <repo>`). Note the actual default-branch version may be ahead of the version named in the issue; check `blueprints.yaml` `version` and the latest comments for hotfix releases.
2. **Map it.** `find . -type f -not -path './.git/*' | sort` and `wc -l` the PHP/JS. Identify the entry plugin class, the `classes/`, the `admin-next/` web components, public assets, and `blueprints.yaml`.
3. **Read security-critical files first**, in this order of value: the public/engine request handler, flat-file storage/writers, the API bridge controllers (auth), anything that writes `user/accounts/*.yaml` or config, file-upload handlers, and the client JS that renders untrusted data.
4. **Cross-check against the real plugins in the workspace**, do not trust the README. They are symlinked under `user/plugins/` and live at `~/Projects/grav/grav-plugin-api`, `~/Projects/grav/grav-plugin-admin2`, `~/Projects/grav/grav-plugin-admin`. Verify event names, controller signatures, and route mechanics there (see Contract below).
5. **Write the handoff** in the output format at the end.

Keep examples generic in any public-facing writeup (do not name the submitter). Internally, be blunt about severity.

## The verified API / Admin2 contract (check the plugin against these)

These are confirmed against `grav-plugin-api`. Re-verify if the API plugin has moved on.

- **`onApi*` events that exist** (fired by the api plugin): `onApiRegisterRoutes`, `onApiCollectPublicRoutes`, `onApiSidebarItems`, `onApiPluginPageInfo`, `onApiAdminSettingsPanels`, `onApiFloatingWidgets`, `onApiMenubarItems`, `onApiContextPanels`, `onApiGenerateReports`, `onApiBlueprintResolved`, `onApiDashboardWidgets`, plus the `onApiBefore*`/`*ed` lifecycle pairs. If a plugin subscribes to an `onApi*` name not in the api plugin, it silently never fires.
- **Controller base.** `AbstractApiController` lives at `Grav\Plugin\Api\Controllers\AbstractApiController`. Constructor is `(Grav $grav, Config $config)` with promoted readonly props. The router instantiates handlers as `new $controllerClass($this->container, $this->config)`, so `$this->grav` is the container and `$this->grav['user']` is available. Route params arrive on the `route_params` request attribute (already `rawurldecode`d).
- **Permissions.** `requirePermission($request, 'api.access')` checks `api.access` first, then the named permission. Custom permissions register via `PermissionsRegisterEvent` + a `permissions.yaml` (`PermissionsReader::fromYaml`).
- **Public routes.** `onApiCollectPublicRoutes` exposes `$event['prefixes']` (matched by `str_starts_with`) and `$event['exact']` (exact match). Anything a plugin adds here is **unauthenticated**. Adding a whole prefix (e.g. `/api/v1/<plugin>`) makes every sub-route public. Auth-required routes simply must not appear here.
- **Rate limiting runs on public routes too** (the api plugin applies `RateLimitMiddleware` after the auth bypass), but it limits request rate, not per-record growth or quota burn.
- **Component plugin page.** `onApiPluginPageInfo` returns a definition with `page_type: 'component'`; admin2 loads `admin-next/pages/{slug}.js`, which must `customElements.define('grav-{slug}--page', ...)` and read `window.__GRAV_PAGE_TAG`. `page_type: 'blueprint'` renders a YAML form with `data_endpoint`/`save_endpoint`/`actions`.
- **Settings panel secret-leak vector.** `onApiAdminSettingsPanels` panels usually set `data_endpoint`/`save_endpoint` to the generic `/config/plugins/<slug>`. That endpoint returns the **raw** plugin config to the browser (gated on `api.config.read`). So any secret stored in plugin config (API tokens, shared keys) is sent to the admin client unless the field is write-only and redacted server-side. A custom controller that masks the secret is bypassed if the panel points at the generic endpoint.
- **Auth transport.** Admin2 web components send `X-API-Token` (FPM/FastCGI strips `Authorization` on some hosts). `window.__GRAV_API_TOKEN` / `__GRAV_API_SERVER_URL` / `__GRAV_API_PREFIX`.
- **No native dialogs in admin2 web components.** Use `window.__GRAV_DIALOGS.confirm()`, never `window.confirm/alert/prompt`.

## Findings taxonomy

Each item: what to look for, and the fix. Severity is a starting point, adjust to context.

### Manifest / GPM (gate listing)

- **`compatibility.grav` lies.** A block of `['1.7','2.0']` while the code uses PHP 8 functions (`str_contains`, `str_starts_with`, `str_ends_with`, `match`, enums, readonly promoted props) cannot run on Grav 1.7 / PHP 7.x. Set `compatibility.grav: ['2.0']`.
- **Dependencies contradict the pitch.** README/issue claims `grav >=2.0.0` + `admin2` + `api` but `blueprints.yaml` lists only `grav >=1.7.0` (or omits admin2/api). GPM reads `blueprints.yaml`, so fix it there.
- **Duplicate YAML keys** in `blueprints.yaml` (e.g. `bugs:`/`docs:` twice) silently take the last value. Dedupe.
- **Version drift.** A status endpoint or twig var hardcodes an old version (`0.1.0-alpha`) while `blueprints.yaml` is further along.
- **Secret fields typed `text`.** API tokens / keys as `type: text` should be `type: password`, and must be redacted on every read path (see settings-panel vector above).

### Admin2 / API integration correctness

- **`class_exists`-gated event subscription.** `getSubscribedEvents()` that only subscribes to `onApi*` events when `class_exists(ApiRouteCollector::class)` is fragile: it depends on plugin load order at registration time. Prefer subscribing unconditionally and guarding inside handlers (the events only fire when the api plugin is present anyway).
- **`isAdmin()`-gated subscription in `onPluginsInitialized`.** A plugin that does `if ($this->isAdmin()) { $this->enable(['onGetPageTemplates' => ...]) }` (or `onGetPageBlueprints`) registers nothing under Admin2. The classic admin set `$grav['admin']` early so `isAdmin()` was true at init; the api plugin's `AdminProxy` sets it only at route **dispatch**, after `onPluginsInitialized` has run, so the gated `enable()` never fires and the plugin's page template/blueprint is **silently missing from the Add Page dropdown**. Tell: the handler is a context-free `$types->register(...)`. Fix: subscribe statically in `getSubscribedEvents()`; if a handler truly needs admin context, check `isAdmin()` *inside* the handler (dispatch time), not at subscription time. A theme that ships a same-named template file masks the bug, so don't rely on the dropdown looking right on one site.
- **No autoloader.** Hand-rolled `require_once` everywhere works but is brittle; a proper `autoload()` returning a PSR-4 `ClassLoader` is the idiom (and fixes "Class not found" boot failures on case-sensitive Linux/cPanel).
- **`exit;` in the page lifecycle.** Frontend fallback handlers that `exit` bypass Grav's shutdown/caching. Acceptable for virtual routes by design, but note it, and watch for contradictions (a hard `api` dependency plus a "no api" fallback path that can never run).
- **Bridge identity.** If an engine reads `$this->grav['user']` but the bridge controller never passes the api-authenticated user through, authenticated actions may silently run as anonymous. Confirm the user is wired (some plugins use a `setApiUser()` pattern).
- **Component conventions.** Verify tag name `grav-{slug}--page`, file at `admin-next/pages/{slug}.js`, and `page_type`.

### Security

- **Coarse authorization.** Every admin endpoint gating on bare `api.access` means any API-enabled user (not just admins) can reach PII reads and writes. Register a dedicated permission or require a higher scope for sensitive actions.
- **Secret handling.** See the settings-panel vector. A custom read endpoint that strips one secret but returns the rest of raw config (e.g. leaves moderator/shared keys in) still leaks. Redact every secret on every read; treat as write-only.
- **Unauthenticated public writes.** Public chat posts, RSVP/form submissions, etc. with only a honeypot and the global rate limiter. Watch for: no per-record cap (storage exhaustion), notification emails fired per submission (mail abuse), and writes that trigger outbound API calls.
- **CSV / formula injection.** A CSV export that quotes commas/quotes but does not neutralize a leading `=`, `+`, `-`, `@`, tab, or CR, fed by public input, executes in Excel/Sheets when an admin opens it. Prefix risky cells with `'`.
- **Mail header injection.** User-controlled `name` placed into `mail()`'s subject after only `trim()`: an embedded CRLF injects headers. Strip line breaks. Also flag hardcoded `From:` on a foreign domain (fails SPF, looks like spam).
- **XSS in client rendering.** The safe pattern is escape-first then re-apply a markdown subset; credit it when present. Flag: an `esc()` that omits `"` (or `'`) while values land in `href="..."`/`src="..."` attributes (attribute breakout); SVG uploads served same-origin (script-bearing); a markdown link builder that does not enforce an `http(s)` scheme (allows `javascript:`).
- **Path traversal in file-serving.** A public route that `readfile()`s a path built from a user/admin-settable field (e.g. a free-text "cover path") with no `realpath()` confinement, and no extension allowlist, becomes an unauthenticated arbitrary-file read (account YAML, config secrets). Confine to the intended media dir, allowlist image extensions, store only the uploaded basename.
- **File upload.** Validate by `finfo` MIME, not just client extension; do not allow `svg` in an "image" upload (or serve with `Content-Disposition: attachment`); force the destination filename so a user can only overwrite their own asset.
- **Account mass-assignment / privilege escalation.** A profile/account write that merges arbitrary request keys into `user/accounts/*.yaml` could let a member set `access.admin.super`. The safe pattern whitelists fields and binds the edit to the session user (username == session). A grant routine whose switch only ever writes `access.site.*` cannot escalate, credit that.
- **Open proxy / quota burn.** An unauthenticated server-side proxy (Giphy, etc.) lets anyone spend the site owner's API quota or use the site as a proxy. Rate-limit or require a session.
- **Pure-PHP SSE worker exhaustion.** A `handle()` that does `set_time_limit(0)` and a `sleep()` loop holds one PHP-FPM worker per connected client for the whole window; a few dozen tabs starve the pool and the whole site stalls. Often it is also dead in the supported config (the api bridge returns 501 and the client falls back to polling), making the "realtime SSE" claim false. Recommend owning the polling model, or offloading push to an external hub (Mercure, as `grav-plugin-sync-mercure` does). PHP must never hold a request open to stream.
- **CSRF.** State-changing endpoints authenticated by an ambient session cookie with no token/nonce and wildcard CORS are CSRF-able. Even when bound to the session user (cannot edit others), an attacker can force the victim to change their own data. Add a form nonce.
- **Spoofing.** A public message endpoint that honors a client-supplied `type: system`/`form` lets guests forge official-looking notices; restrict privileged message types to mod/internal paths. Check for a reserved-name guard that stops guests impersonating members/mods, credit it when present.
- **Secrets in URLs.** A shared key passed as a query param (`?modKey=...`, GET) lands in access logs, history, and Referer. Move to a POST body or header. Credit `hash_equals()` for key comparison when present.

### Citizen behavior

- **Global config rewrite.** A plugin that overwrites another plugin's config (e.g. `plugins.login.redirect_after_login`, messenger groups) on every request, affecting the whole site, is surprising. Scope it or respect existing config.
- **Route shadowing.** Very-early (`priority 100000`) interception of a public route means it can never be a real Grav page, and bypasses Grav caching/headers. By design for virtual routes, but note it.

### Brand / fit (maintainer decision, not a code bug)

- **Author defaults baked in.** Notify emails defaulting to the author's address, hardcoded `From:`/footer/upsell URLs on the author's domains, demo data referencing the author's brand, theme/category names tied to the author, optional wire-ins to the author's other unlisted plugins. For a "general-purpose" listing, the generic version must be the default. Flag this separately as a decision for the maintainer.

## Output format

Produce a handoff the maintainer can read and another agent can action:

1. **One-line verdict** (listable / fix-first / not as-is) and the headline blockers.
2. **Credit correct practices explicitly** in a "do not fix these" list so a downstream agent does not undo them.
3. **Findings grouped by category, severity-tagged**, each with a `file:line` reference and a concrete fix. Order security before manifest before quality.
4. **Brand/fit as a separate section** framed as a maintainer decision.
5. Wrap the whole thing in a fenced code block so terminal markdown renderers do not mangle it (the user often pastes it verbatim).

Scale effort to the ask: a quick "any red flags?" gets the top 3, a "thorough audit" gets the full taxonomy with the good-practices credits.
