---
name: grav-api-admin-next-integration
description: >-
  Use when integrating a Grav 2.0 plugin with Admin Next (the new SvelteKit SPA admin) and the API plugin. Covers all integration points: API endpoints (`onApiRegisterRoutes`), sidebar items, menubar/toolbar buttons, floating widgets, plugin pages (blueprint and component modes), custom field types, custom reports, blueprint modifications, permissions, languages, and config exposure to web components. Trigger when migrating an admin-classic plugin to Admin Next, when adding any Admin Next UI to a plugin, when working under `user/plugins/<plugin>/admin-next/` or on event handlers prefixed `onApi*`, or when the user mentions admin-next, custom field type, sidebar nav, floating widget, plugin page, custom report, or any of the `onApi*` events. Use the lighter `grav-api-integration` skill instead if the plugin only needs API endpoints with no admin UI.
---

# Grav Plugin Admin-Next & API Integration

Integrate a Grav CMS plugin with admin-next (SvelteKit SPA) and the API plugin. This covers migrating existing admin-classic integrations and building new ones.

## Instructions

Follow these steps in order. Analyze the plugin first, then implement only the integration types it needs.

---

## Step 1: Analyze the Plugin

Read the plugin's main PHP file, `getSubscribedEvents()`, classes, blueprints, templates, and admin/ directories. Identify what features currently exist in admin-classic and map them to admin-next equivalents:

| Admin-Classic Feature | Admin-Next Integration |
|---|---|
| `onAdminMenu` / admin sidebar entries | `onApiSidebarItems` — Section B |
| Quick-tray buttons / toolbar actions | `onApiMenubarItems` + `onApiMenubarAction` — Section C |
| `onAdminGenerateReports` | `onApiGenerateReports` — Section G |
| Admin templates/pages with forms | `onApiPluginPageInfo` (blueprint mode) — Section E |
| Admin pages with custom JS UI | `onApiPluginPageInfo` (component mode) — Section E |
| Custom Twig field types | `admin-next/fields/` web components — Section F |
| Floating panels / modals | `onApiFloatingWidgets` — Section D |
| Context-aware side panels (page revisions, etc.) | `onApiContextPanels` — panel web components in `admin-next/panels/` |
| Blueprint manipulation / field injection | `onApiBlueprintResolved` — Section H |
| Data CRUD operations | `onApiRegisterRoutes` — Section A |
| Custom permissions | `permissions.yaml` + `PermissionsRegisterEvent` — Section I |
| `window.MyPluginConfig` inline injection | `/my-plugin/config` endpoint + client fetch — Section K |
| `config-default@:` in existing blueprint fields | Works unchanged in admin-next — resolved by Grav core — Section K Step 4 Option A |

**Always** perform a config audit (Section K) during analysis — identify every setting in the plugin's `blueprints.yaml` that affects admin UI, not just the features themselves. Check existing blueprints for `config-default@` before deciding whether a `/config` endpoint is needed.

## Step 2: Determine Integration Types

Check each type against the plugin's needs. A plugin typically uses 2-6 types. Common combinations:
- **Dashboard plugin**: Sidebar + Plugin Page + Routes + Reports
- **Editor/field plugin**: Custom Fields + Blueprint Modifications + Routes
- **Utility plugin**: Menubar Items + Routes
- **Assistant plugin**: Floating Widget + Routes

---

## Section A: API Endpoints (`onApiRegisterRoutes`)

### Event Subscription
```php
public static function getSubscribedEvents(): array
{
    return [
        'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
    ];
}
```

### Route Registration
```php
public function onApiRegisterRoutes(Event $event): void
{
    $routes = $event['routes'];
    $controller = \Grav\Plugin\MyPlugin\Api\MyApiController::class;

    // Static routes MUST come before parameterized routes (FastRoute constraint)
    $routes->get('/my-plugin/items', [$controller, 'index']);
    $routes->post('/my-plugin/items', [$controller, 'create']);
    $routes->get('/my-plugin/items/{id}', [$controller, 'show']);
    $routes->patch('/my-plugin/items/{id}', [$controller, 'update']);
    $routes->delete('/my-plugin/items/{id}', [$controller, 'delete']);

    // Route grouping
    $routes->group('/my-plugin', function ($group) use ($controller) {
        $group->get('/config', [$controller, 'config']);
        $group->post('/action', [$controller, 'doAction']);
    });
}
```

### Controller Pattern
Create `classes/Api/MyApiController.php` extending `AbstractApiController`:

```php
namespace Grav\Plugin\MyPlugin\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Exceptions\{NotFoundException, ValidationException};
use Psr\Http\Message\{ServerRequestInterface, ResponseInterface};

class MyApiController extends AbstractApiController
{
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');
        $pagination = $this->getPagination($request);
        $sorting = $this->getSorting($request, ['title', 'date']);
        $data = []; // ... fetch data
        return ApiResponse::paginated($data, $total, $pagination['page'], $pagination['per_page'], $baseUrl);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['title']);
        // ... create resource
        return ApiResponse::created($data, "/api/v1/my-plugin/items/{$id}");
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');
        $id = $this->getRouteParam($request, 'id');
        // ... fetch resource or throw NotFoundException
        return $this->respondWithEtag($data);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');
        $id = $this->getRouteParam($request, 'id');
        $this->validateEtag($request, $currentHash); // Optimistic concurrency
        $body = $this->getRequestBody($request);
        // ... update resource
        return $this->respondWithInvalidation($data, ['my-plugin:update']);
    }
}
```

### AbstractApiController Key Methods
- `$this->requirePermission($request, 'api.resource.action')` — throws `ForbiddenException`
- `$this->getUser($request)` — returns authenticated `UserInterface`
- `$this->getRequestBody($request)` — parsed JSON body as array
- `$this->getRouteParam($request, 'name')` — URL path parameter
- `$this->requireFields($body, ['field1', 'field2'])` — throws `ValidationException`
- `$this->getPagination($request)` — returns `['page', 'per_page', 'offset', 'limit']`
- `$this->getSorting($request, $allowedFields)` — returns `['sort', 'order']`
- `$this->getFilters($request, $allowedFilters)` — returns filters array
- `$this->validateEtag($request, $hash)` — throws `ConflictException` on mismatch
- `$this->respondWithEtag($data, $status)` — response with ETag header
- `$this->respondWithInvalidation($data, $tags)` — response with `X-Invalidates` header
- `$this->fireAdminEvent($name, $data)` — fires admin-compatible event

### Response Helpers
- `ApiResponse::create($data)` — 200 with `{"data": ...}`
- `ApiResponse::ok($data)` — alias for create
- `ApiResponse::created($data, $location)` — 201 with Location header
- `ApiResponse::noContent()` — 204 empty
- `ApiResponse::paginated($data, $total, $page, $perPage, $baseUrl)` — with meta/links

### Exceptions
- `ValidationException($detail, $errors)` — 422, `$errors` = `[['field' => 'name', 'message' => '...'], ...]`
- `NotFoundException($detail)` — 404
- `ForbiddenException($detail)` — 403
- `UnauthorizedException($detail)` — 401
- `ConflictException($detail)` — 409 (ETag mismatch)

---

## Section B: Sidebar Navigation (`onApiSidebarItems`)

Adds a menu item to the admin-next sidebar. Route convention: `/plugin/{slug}`.

### Event Subscription
```php
'onApiSidebarItems' => ['onApiSidebarItems', 0],
```

### Handler
```php
public function onApiSidebarItems(Event $event): void
{
    $items = $event['items'] ?? [];
    $items[] = [
        'id'       => 'my-plugin',          // unique identifier
        'plugin'   => 'my-plugin',          // plugin slug
        'label'    => 'My Plugin',          // sidebar display name
        'icon'     => 'fa-puzzle-piece',    // Font Awesome icon (with fa- prefix)
        'route'    => '/plugin/my-plugin',  // admin-next route
        'priority' => 5,                    // sort order (higher = earlier)
        // 'badge'         => '3',          // optional STATIC badge text/count
        // 'badgeEndpoint' => '/my-plugin/badge', // optional DYNAMIC badge: returns { count: N }
        // 'authorize'     => 'api.system.read',  // optional perm gate (string or any-of array), stripped before reaching client
    ];
    $event['items'] = $items;
}
```

### Dynamic sidebar badges

`badge` is static — it only changes on a full sidebar reload. For a count that
refreshes on its own, add `badgeEndpoint` (same mechanism context panels use).
The endpoint returns `{ count: N }`:

```php
// Route: GET /my-plugin/badge (register in onApiRegisterRoutes)
public function badge(ServerRequestInterface $request): ResponseInterface
{
    return ApiResponse::create(['count' => $this->pendingCount()]);
}
```

Admin-next fetches it when the sidebar loads and re-fetches on
content/config/plugin/theme invalidations; the live count overrides the static
`badge`. A web component (plugin page, widget) can also push an immediate update
without a round-trip:

```javascript
window.dispatchEvent(new CustomEvent('grav:sidebar:badge', {
    detail: { id: 'my-plugin', count: 42 },   // id = the sidebar item id
}));
```

---

## Section C: Menubar/Toolbar Items (`onApiMenubarItems` + `onApiMenubarAction`)

Adds action buttons to the admin-next header toolbar. Requires TWO event handlers.

### Event Subscriptions
```php
'onApiMenubarItems'  => ['onApiMenubarItems', 0],
'onApiMenubarAction' => ['onApiMenubarAction', 0],
```

### Registration Handler
```php
public function onApiMenubarItems(Event $event): void
{
    // Optional: gate on plugin config
    if (!$this->config->get('plugins.my-plugin.enable_quicktray')) {
        return;
    }

    $items = $event['items'] ?? [];
    $items[] = [
        'id'      => 'my-plugin-action',    // unique identifier
        'plugin'  => 'my-plugin',           // plugin slug
        'label'   => 'Run My Action',       // tooltip text
        'icon'    => 'fa-bolt',             // Font Awesome icon
        'action'  => 'run',                 // action key (passed to action handler)
        'confirm' => 'Run this action?',    // optional confirmation dialog text
    ];
    $event['items'] = $items;
}
```

### Action Handler
```php
public function onApiMenubarAction(Event $event): void
{
    // REQUIRED: guard clause — only handle your plugin's actions
    if ($event['plugin'] !== 'my-plugin') {
        return;
    }

    if ($event['action'] === 'run') {
        // ... execute the action
        $event['result'] = [
            'status'  => 'success',         // 'success' or 'error'
            'message' => 'Action completed', // toast message shown to user
        ];
    }
}
```

---

## Section D: Floating Widgets (`onApiFloatingWidgets`)

Bottom-right FAB buttons that open sliding panels. Used for AI chat, translation tools, etc. The UI is a web component loaded from `admin-next/widgets/{slug}.js`.

### Event Subscription
```php
'onApiFloatingWidgets' => ['onApiFloatingWidgets', 0],
```

### Registration Handler
```php
public function onApiFloatingWidgets(Event $event): void
{
    // Optional: config/permission gating
    $user = $event['user'] ?? null;
    if (!$user || !($user->get('access.admin.super') || $user->get('access.admin.my-plugin'))) {
        return;
    }

    $widgets = $event['widgets'] ?? [];
    $widgets[] = [
        'id'                => 'my-plugin-widget',    // unique identifier
        'plugin'            => 'my-plugin',            // plugin slug
        'label'             => 'My Widget',            // FAB tooltip
        'icon'              => 'sparkles',             // Lucide icon name (NOT Font Awesome)
        'gradient'          => 'linear-gradient(135deg, #6366f1, #8b5cf6)', // FAB/header gradient
        'priority'          => 10,                     // sort order
        'width'             => 400,                    // panel width in pixels
        'height'            => 500,                    // panel height in pixels
        'useStandardHeader' => false,                  // false = widget provides its own header
        'showFab'           => true,                   // show FAB button (false hides it)
        'autoLoad'          => false,                  // true = load script on page init (for field injection)
    ];
    $event['widgets'] = $widgets;
}
```

### Widget Web Component (`admin-next/widgets/my-plugin.js`)
```javascript
const TAG = window.__GRAV_WIDGET_TAG;

class MyPluginWidget extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
    }

    connectedCallback() {
        this._render();
    }

    // ─── API Helpers ─────────────────────────────────
    _apiUrl(path) {
        return (window.__GRAV_API_SERVER_URL || '') +
               (window.__GRAV_API_PREFIX || '/api/v1') + path;
    }

    _headers(json = false) {
        const h = {};
        const token = window.__GRAV_API_TOKEN;
        // Use X-API-Token (not Authorization: Bearer). FastCGI / PHP-FPM / CGI
        // setups can silently strip the Authorization header before it reaches
        // PHP — notably MAMP's mod_fastcgi. Any X-* header passes through
        // cleanly. The server also accepts Authorization: Bearer as a
        // fallback for standards-compliant clients.
        if (token) h['X-API-Token'] = token;
        if (json) h['Content-Type'] = 'application/json';
        return h;
    }

    async _api(method, path, body) {
        const opts = { method, headers: this._headers(!!body) };
        if (body) opts.body = JSON.stringify(body);
        const resp = await fetch(this._apiUrl(path), opts);
        const json = await resp.json();
        return json.data || json;
    }

    // ─── Close ───────────────────────────────────────
    _close() {
        this.dispatchEvent(new CustomEvent('close'));
    }

    _render() {
        this.shadowRoot.innerHTML = `
            <style>
                :host { display: flex; flex-direction: column; height: 100%; }
                /* Use CSS custom properties for theming:
                   var(--foreground), var(--background), var(--border),
                   var(--muted), var(--muted-foreground), var(--primary),
                   var(--accent), var(--popover) */
            </style>
            <div class="header">
                <span>My Widget</span>
                <button id="close-btn">&times;</button>
            </div>
            <div class="content">...</div>
        `;
        this.shadowRoot.getElementById('close-btn')
            ?.addEventListener('click', () => this._close());
    }
}

customElements.define(TAG, MyPluginWidget);
```

---

## Section E: Plugin Pages (`onApiPluginPageInfo`)

Full-page plugin UI in admin-next. Two rendering modes:
- **Blueprint mode**: Form rendered from YAML blueprint (for settings/data pages)
- **Component mode**: Web component for complex custom UI (for dashboards, etc.)

### Event Subscription
```php
'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
```

### Blueprint Mode Handler
```php
public function onApiPluginPageInfo(Event $event): void
{
    if ($event['plugin'] !== 'my-plugin') {
        return;
    }

    $event['definition'] = [
        'id'             => 'my-plugin',
        'plugin'         => 'my-plugin',
        'title'          => 'My Plugin Settings',
        'icon'           => 'fa-cog',
        'page_type'      => 'blueprint',           // renders YAML blueprint as form
        'blueprint'      => 'my-plugin',            // blueprint name to resolve
        'data_endpoint'  => '/my-plugin/data',      // GET endpoint for form data
        'save_endpoint'  => '/my-plugin/save',      // PATCH endpoint for saving
        'actions'        => [                        // optional action buttons
            [
                'id'       => 'export',
                'label'    => 'Export',
                'icon'     => 'fa-download',
                'download' => true,                 // triggers file download
                'endpoint' => '/my-plugin/export',
            ],
            [
                'id'       => 'import',
                'label'    => 'Import',
                'icon'     => 'fa-upload',
                'upload'   => true,                 // triggers file upload dialog
                'endpoint' => '/my-plugin/import',
            ],
            [
                'id'      => 'save',
                'label'   => 'Save',
                'icon'    => 'fa-check',
                'primary' => true,                  // highlighted as primary action
            ],
        ],
    ];
}
```

### Customizing the save toast (blueprint mode)

After a blueprint-mode save, Admin Next shows a generic "saved" toast. The
`save_endpoint` can override it by returning a `toast` hint in its response — no
client code required.

**Success** — return the hint in the `ApiResponse` body (top-level `toast`
object, or a bare `message` string):
```php
public function save(ServerRequestInterface $request): ResponseInterface
{
    // ... persist the data ...
    return ApiResponse::create([
        'toast' => [
            'message'  => 'Settings saved — re-indexing in the background.',
            'type'     => 'success',   // success | error | info | warning
            'duration' => 8000,        // ms; 0 (or dismissible:true) = until closed
        ],
    ]);
}
```
A save endpoint that returns no `toast`/`message` key keeps the default toast,
so existing endpoints are unaffected.

**Error** — pass the hint as the 5th argument to `ErrorResponse::create()`. The
`detail` string is still used as the message when no hint `message` is given,
so a longer-lived error toast is just:
```php
return ErrorResponse::create(422, 'Validation failed', $detail, [], [
    'duration'    => 0,      // stays until the user dismisses it
    'dismissible' => true,
]);
```

Same hint shape works anywhere a toast is shown from a server response.

### Component Mode Handler
```php
$event['definition'] = [
    'id'        => 'my-plugin',
    'plugin'    => 'my-plugin',
    'title'     => 'My Plugin Dashboard',
    'icon'      => 'fa-chart-line',
    'page_type' => 'component',  // loads web component from admin-next/pages/
];
```

Component web component goes in `admin-next/pages/my-plugin.js`, tag name `grav-{slug}--page`, receives `window.__GRAV_PAGE_TAG`.

#### Toolbar communication: `page-action` / `page-state`

A component-mode page has no blueprint form, so admin-next can't track its dirty state. The component and the header toolbar (the `actions` array) talk through two DOM events on the component's own element:

- **`page-action`** (toolbar → component): admin-next dispatches this when a toolbar action your component owns is clicked — any action with no `endpoint`, **including the `primary` action**. `detail = { id, label }`.
- **`page-state`** (component → toolbar): your component dispatches this to report `{ dirty, valid, busy }` (all optional, merged on each dispatch). It drives the `primary` button:
    - `dirty` — the `primary` button stays **disabled until the component reports `dirty: true`** at least once.
    - `valid` — set `false` to keep `primary` disabled while input is invalid.
    - `busy` — set `true` to show a spinner on `primary` while saving.

```javascript
class MyPluginPage extends HTMLElement {
    connectedCallback() {
        this.attachShadow({ mode: 'open' });
        // Run our save when the primary (or any endpoint-less) action fires
        this.addEventListener('page-action', (e) => {
            if (e.detail?.id === 'save') this._save();
        });
        this._render();
    }

    _onChange() {
        // Enable the primary Save button
        this.dispatchEvent(new CustomEvent('page-state', { detail: { dirty: true } }));
    }

    async _save() {
        this.dispatchEvent(new CustomEvent('page-state', { detail: { busy: true } }));
        try {
            await this._persist();
            window.__GRAV_TOAST?.success('Saved');
            this.dispatchEvent(new CustomEvent('page-state', { detail: { dirty: false, busy: false } }));
        } catch (err) {
            window.__GRAV_TOAST?.error('Save failed');
            this.dispatchEvent(new CustomEvent('page-state', { detail: { busy: false } }));
        }
    }
}
```

A `primary: true` action on a component page is **always disabled** until the component emits `page-state` with `dirty: true` — a component that never dispatches `page-state` can't use the primary button (define a non-primary action instead, which is always enabled and also fires `page-action`).

For non-blocking feedback use `window.__GRAV_TOAST.{success,error,info,warning}(msg, opts)` (`opts` is forwarded to the toaster, e.g. `{ duration: 6000 }`).

**Never** use native `confirm()`/`alert()`/`prompt()` in component-mode pages — use `window.__GRAV_DIALOGS.confirm()` instead. See Section F → "UI Dialogs" for the API.

### Filesystem Discovery (alternative to event)
Place files directly without implementing the event:
- `admin-next/pages/my-plugin.yaml` — blueprint definition (blueprint mode)
- `admin-next/pages/my-plugin.js` — web component (component mode)

---

## Section F: Custom Field Types (`admin-next/fields/`)

Custom form fields auto-discovered by the API when plugin details are fetched. Each `.js` file in `admin-next/fields/` becomes a field type (filename = type name).

> **Themes provide custom fields the same way** — put the file at `your-theme/admin-next/fields/{type}.js`. The API reports each field type's provider *kind* (`plugins` vs `themes`) so admin-next fetches the script from the right `/gpm/{kind}/{slug}/field/{type}` route. No blueprint difference; a theme-provided type works in any blueprint. (Requires grav-plugin-api ≥ 1.0.0-rc.15 / admin2 ≥ 2.0.0-rc.15 — older builds always used the `plugins` route and 404'd on theme fields.)

### Blueprint Usage
In a blueprint YAML, reference the custom field type:
```yaml
fields:
    my_setting:
        type: my-field-type    # matches filename: admin-next/fields/my-field-type.js
        label: My Setting
        # any extra properties accessible via this._field in the web component
```

### Custom Field Web Component (`admin-next/fields/my-field-type.js`)
```javascript
const TAG = window.__GRAV_FIELD_TAG;

class MyFieldType extends HTMLElement {
    constructor() {
        super();
        this._value = null;
        this._field = null;
    }

    // REQUIRED: Property setters/getters — admin-next sets these
    set field(v) { this._field = v; this._render(); }
    get field() { return this._field; }

    set value(v) {
        if (v !== this._value) {
            this._value = v;
            this._render();
        }
    }
    get value() { return this._value; }

    connectedCallback() {
        this._render();
    }

    // REQUIRED for editable fields: dispatch 'change' event with new value
    _select(newValue) {
        this._value = newValue;
        this._render();
        this.dispatchEvent(new CustomEvent('change', {
            detail: newValue,
            bubbles: true,
        }));
    }

    _render() {
        // Use this._field for blueprint properties (label, options, etc.)
        // Use this._value for current value
        // Can use Shadow DOM or light DOM
        this.innerHTML = `...`;
    }

    // API communication via injected globals
    async _fetchData() {
        const baseUrl = window.__GRAV_API_SERVER_URL + (window.__GRAV_API_PREFIX || '/api/v1');
        const token = window.__GRAV_API_TOKEN;
        const headers = {};
        // X-API-Token, not Authorization: Bearer — see top of file for why.
        if (token) headers['X-API-Token'] = token;
        const resp = await fetch(`${baseUrl}/my-plugin/data`, { headers });
        return (await resp.json()).data;
    }
}

customElements.define(TAG, MyFieldType);
```

### Key Rules
- Tag name is auto-assigned via `window.__GRAV_FIELD_TAG` — always use it
- Property `field` receives the full blueprint field definition object
- Property `value` receives the current saved value and must be gettable
- Dispatch `new CustomEvent('change', { detail: newValue, bubbles: true })` when value changes
- Read-only display fields don't need to dispatch events
- API globals: `__GRAV_API_SERVER_URL`, `__GRAV_API_PREFIX`, `__GRAV_API_TOKEN`
- Dialog global: `__GRAV_DIALOGS` (see "UI Dialogs" below) — **never** call native `confirm()`/`alert()`/`prompt()`

### UI Dialogs — Never Use Native `confirm()`/`alert()`/`prompt()`

**Do not** call `window.confirm()`, `window.alert()`, or `window.prompt()` in any admin-next web component (fields, pages, panels, floating widgets). Native browser dialogs break the admin-next visual language, are jarring, and the user strongly dislikes them.

Admin-next exposes `window.__GRAV_DIALOGS` — a global wrapper around the internal `ConfirmModal` component — that any plugin web component can use.

**Signature:**
```typescript
window.__GRAV_DIALOGS.confirm({
    title?: string;           // default: "Are you sure?"
    message: string;          // required body text
    confirmLabel?: string;    // default: "Confirm"
    cancelLabel?: string;     // default: "Cancel"
    variant?: 'destructive' | 'default';  // destructive adds warning icon + red confirm button
}): Promise<boolean>
```

Resolves `true` on confirm, `false` on cancel (including Escape key and backdrop click).

**Usage example** (from a plugin web component):
```javascript
async _runDestructiveAction() {
    const ok = await window.__GRAV_DIALOGS?.confirm({
        title: 'Clear all index data?',
        message: 'This will delete every indexed document. This cannot be undone.',
        confirmLabel: 'Clear indexes',
        variant: 'destructive',
    });
    if (!ok) return;
    // ... proceed with destructive action
}
```

**Rules:**
- Always use optional chaining (`?.`) so the component degrades gracefully if loaded outside admin-next.
- Use `variant: 'destructive'` for delete/clear/reset/overwrite actions.
- Keep `message` short — one or two sentences. Put the noun in `title` ("Delete page?") and consequences in `message` ("All revisions will be lost.").
- Do not use for non-blocking status (success/failure feedback) — use `toast` instead. The dialog API is only for blocking user confirmation.
- The same API is available inside Svelte code as `import { dialogs } from '$lib/stores/dialogs.svelte'` then `await dialogs.confirm({...})`.

---

## Section G: Custom Reports (`onApiGenerateReports`)

Reports shown in the admin-next Tools → Reports page. Can include an optional web component for custom rendering.

### Event Subscription
```php
'onApiGenerateReports' => ['onApiGenerateReports', 0],
```

### Handler
```php
public function onApiGenerateReports(Event $event): void
{
    // Optional: gate on config
    if (!$this->config->get('plugins.my-plugin.enable_report')) {
        return;
    }

    // ... compute report data
    $reports = $event['reports'];
    $reports[] = [
        'id'        => 'my-plugin',                  // unique identifier
        'title'     => 'My Plugin Report',            // report title
        'provider'  => 'my-plugin',                   // plugin slug
        'component' => 'my-plugin-report',            // web component name (optional)
        'status'    => $hasIssues ? 'warning' : 'success', // 'error', 'warning', or 'success'
        'message'   => 'Summary of findings...',      // summary text
        'items'     => $reportData,                   // structured data for the component
    ];
    $event['reports'] = $reports;
}
```

### Report Web Component (`admin-next/reports/my-plugin-report.js`)
```javascript
const TAG = window.__GRAV_REPORT_TAG;

class MyPluginReport extends HTMLElement {
    #report = null;

    set report(val) {
        this.#report = val;
        this.render();
    }

    get report() { return this.#report; }

    connectedCallback() {
        if (this.#report) this.render();
    }

    render() {
        const report = this.#report;
        if (!report) return;

        const shadow = this.shadowRoot || this.attachShadow({ mode: 'open' });
        shadow.innerHTML = '';

        const style = document.createElement('style');
        style.textContent = `
            :host { display: block; font-family: inherit; }
            /* Use CSS custom properties: var(--border), var(--foreground), etc. */
        `;
        shadow.appendChild(style);

        // Render report.items as needed
        for (const item of (report.items || [])) {
            const el = document.createElement('div');
            el.textContent = JSON.stringify(item);
            shadow.appendChild(el);
        }
    }
}

customElements.define(TAG, MyPluginReport);
```

---

## Section H: Blueprint Modifications (`onApiBlueprintResolved`)

Modify blueprint fields after resolution — used to override field types, inject attributes, or add fields.

### Event Subscription
```php
'onApiBlueprintResolved' => ['onApiBlueprintResolved', 0],
```

### Handler
```php
public function onApiBlueprintResolved(Event $event): void
{
    $user = $event['user'] ?? null;
    if (!$user) {
        return;
    }

    // Optional: permission check
    if (!($user->get('access.admin.super') || $user->get('access.admin.my-plugin'))) {
        return;
    }

    // Read, modify, write back (event doesn't support pass-by-reference)
    $fields = $event['fields'];
    $this->walkFields($fields);
    $event['fields'] = $fields;
}

private function walkFields(array &$fields): void
{
    foreach ($fields as $key => &$field) {
        // Override field type (e.g., markdown → editor-pro)
        if (($field['type'] ?? '') === 'markdown') {
            $field['type'] = 'my-custom-type';
        }

        // Inject custom attributes
        if (($field['type'] ?? '') === 'text') {
            $field['my_plugin_enabled'] = true;
        }

        // Recurse into nested fields
        if (isset($field['fields'])) {
            $this->walkFields($field['fields']);
        }
    }
}
```

---

## Section I: Permissions (`permissions.yaml`)

Define custom permissions for the plugin.

### permissions.yaml
```yaml
actions:
    admin.my-plugin:
        label: My Plugin
        actions:
            read:
                label: Read Access
            write:
                label: Write Access
```

### Event Registration (optional, for dynamic permissions)
```php
'PermissionsRegisterEvent' => ['onPermissionsRegister', 0],
```

```php
public function onPermissionsRegister(PermissionsRegisterEvent $event): void
{
    $permissions = $event->permissions;
    $actions = PermissionsReader::fromYaml("plugin://{$this->name}/permissions.yaml");
    $permissions->addActions($actions);
}
```

### Checking Permissions in API Context
```php
// CORRECT — works in API context:
$user->get('access.admin.my-plugin.read')

// WRONG — does NOT work outside admin context:
$user->authorize('admin.my-plugin.read')
```

---

## Section J: Languages/i18n

Provide translations for plugin labels, field labels, and messages.

### File Structure
```
languages/
├── en.yaml
├── fr.yaml
└── de.yaml
```

### Format (`languages/en.yaml`)
```yaml
PLUGIN_MY_PLUGIN:
    TITLE: "My Plugin"
    FIELD_LABEL: "Setting Name"
    DESCRIPTION: "Description text"
```

Translation keys used in blueprints via `PLUGIN_MY_PLUGIN.FIELD_LABEL` are automatically resolved.

---

## Section K: Plugin Configuration Integration

**Critical step — easy to miss.** Plugins almost always have admin-relevant config in their `{plugin}.yaml` / `blueprints.yaml` that must flow into admin-next UI components. The admin-next framework does **not** automatically pass plugin config to web components — only `route`, `lang`, `type` attributes. You must wire config through manually.

### Step 1: Audit admin-relevant config

Read the plugin's `blueprints.yaml` and `{plugin}.yaml`. Classify each option:

| Type | Example | Where it applies |
|---|---|---|
| **Feature toggle** | `enable_trash: true` | Gate UI visibility (buttons, panels, widgets) |
| **Default mode/preference** | `compare_mode: current` | Seed component defaults |
| **Display preference** | `show_revision_count: true` | Gate badges/indicators |
| **Scope/tracking toggle** | `track_pages: true` | Gate registration at event-handler level |
| **Backend-only** | `max_revisions_per_page: 25` | Ignore — not UI-relevant |

The goal: any setting that affects what the user sees in admin-next must be reachable by the relevant component at runtime.

### Step 2: Gate event handlers with config (server-side)

For settings that control **whether** something is registered at all (tracking toggles, feature enables), gate the registration in the event handler itself. This is the most efficient form — nothing gets sent to the client.

```php
public function onApiContextPanels(Event $event): void
{
    $contexts = [];
    // Tracking toggles decide which contexts the panel appears in
    if ($this->config->get('plugins.my-plugin.track_pages', true))   $contexts[] = 'pages';
    if ($this->config->get('plugins.my-plugin.track_config', true))  $contexts[] = 'config';
    if (empty($contexts)) {
        return; // Plugin is entirely disabled for admin-next
    }
    // ... register panel
}

public function onApiFloatingWidgets(Event $event): void
{
    if (!$this->config->get('plugins.my-plugin.enable_widget', true)) {
        return;
    }
    // ... register widget
}
```

Apply this pattern to: `onApiSidebarItems`, `onApiMenubarItems`, `onApiFloatingWidgets`, `onApiContextPanels`, `onApiGenerateReports`, `onApiPluginPageInfo`.

### Step 3: Gate server-side endpoints on display config

For config that controls derived data (badge counts, feature presence), also gate the API response. This ensures the host framework respects the setting even if a stale component isn't updated.

```php
public function badge(ServerRequestInterface $request): ResponseInterface
{
    // Respect show_revision_count — return 0 so host clears any existing badge
    if (!$this->grav['config']->get('plugins.my-plugin.show_revision_count', true)) {
        return ApiResponse::create(['count' => 0]);
    }
    // ... compute real count
}
```

### Step 4: Expose UI config to components

Choose the right vehicle based on what kind of UI consumes the config. Many plugins only need Option A; Option B is for custom components that don't go through blueprint resolution.

#### Option A: `config-default@` (blueprint-native — prefer when applicable)

If the UI is **blueprint-driven** — a plugin settings form, a page editor field that should seed from plugin-level defaults, or any form rendered from a resolved blueprint — use Grav core's `config-default@` directive. It reads a plugin config value at blueprint resolution time and uses it as the field's default. admin-next's blueprint API uses the same Grav resolution pipeline, so defaults arrive automatically with **no endpoint, no client fetch, no state seeding**.

```yaml
# blueprints/pages/partials/downloads.yaml
form:
  fields:
    header.downloads.layout:
      type: select
      label: Layout
      config-default@: plugins.downloads-pro.defaults.layout   # reads plugins/downloads-pro.yaml
      options:
        list: List
        grid: Grid
        card: Card

    header.downloads.include_all:
      type: toggle
      label: Include All Media
      config-default@: plugins.downloads-pro.defaults.include_all
      validate:
        type: bool
```

Implemented in Grav core: `system/src/Grav/Common/Data/BlueprintSchema.php` → `dynamicConfig()` handler resolves `config-default@` against `Grav::instance()['config']->get(...)` at blueprint parse time. Works wherever a blueprint is resolved (admin-classic, admin-next, CLI).

**Use this when**:
- The plugin has a settings form/page whose fields should seed from the plugin's yaml defaults
- Page editor fields need per-plugin-default values (the `config-default@` directive in a page blueprint reads from a plugin's yaml whenever the blueprint is resolved)
- You just need "if the admin changes the plugin setting, new forms pick up the new default"

**Do NOT use this for**:
- Custom web components that render config-driven UI (widgets, context panels, custom page components, custom field components)
- Runtime preferences that affect non-blueprint UI behavior (toolbar filter strings, default comparison modes, feature-toggle-gated headers)
- Computed values (counts, status flags, current state)

#### Option B: `/config` endpoint (for custom components)

For runtime preferences that custom web components need, expose a dedicated config endpoint. Return **only the UI-relevant subset** — do not dump the entire plugin config.

```php
// Route: GET /api/v1/my-plugin/config
public function config(ServerRequestInterface $request): ResponseInterface
{
    $this->requirePermission($request, 'api.access');
    $cfg = $this->grav['config'];

    return ApiResponse::create([
        'enable_feature_x'    => (bool) $cfg->get('plugins.my-plugin.enable_feature_x', true),
        'default_mode'        => (string) $cfg->get('plugins.my-plugin.default_mode', 'auto'),
        'show_indicator'      => (bool) $cfg->get('plugins.my-plugin.show_indicator', true),
        // Computed values are fine here
        'item_count'          => $this->getItemCount(),
    ]);
}
```

Register it in `onApiRegisterRoutes`:
```php
$routes->get('/my-plugin/config', [$controller, 'config']);
```

**Mixed case**: A plugin can use both — `config-default@` for its settings form, and a `/config` endpoint for custom components elsewhere in the same plugin. They do not conflict.

### Step 5: Fetch and apply config in web components

Components should fetch config during `connectedCallback` **before** rendering config-dependent UI. Store defaults so the first render doesn't crash if the fetch fails.

```javascript
class MyPanel extends HTMLElement {
    constructor() {
        super();
        // Sensible defaults — used before config loads and as fallback
        this._config = {
            enable_feature_x: true,
            default_mode: 'auto',
            show_indicator: true,
            item_count: 0,
        };
        // ... other state
    }

    connectedCallback() {
        this._render(); // Initial render with defaults
        // Fetch config, then any config-dependent data
        this._fetchConfig().then(() => {
            if (this._route) this._fetchData();
        });
    }

    async _fetchConfig() {
        try {
            const cfg = await this._api('GET', '/my-plugin/config');
            this._config = { ...this._config, ...cfg };
            // Seed state from config
            this._mode = this._config.default_mode;
        } catch (e) {
            console.warn('[MyPlugin] Failed to load config:', e.message);
            // Non-fatal — keep defaults
        }
        this._render();
    }

    // Gate badge emission on display config
    _emitBadge(count) {
        const effective = this._config.show_indicator ? count : 0;
        this.dispatchEvent(new CustomEvent('badge', { detail: { count: effective } }));
    }

    // Gate UI elements on feature toggles
    _renderHeader() {
        const featureBtn = this._config.enable_feature_x
            ? `<button class="feature-btn">Feature X</button>`
            : '';
        // ...
    }
}
```

### Step 6: Audit points — common mistakes

When integrating a plugin, verify EACH of these:

- [ ] Every config option in `blueprints.yaml` has been classified (UI / backend / scope-gate)
- [ ] Scope-gating config (e.g., `track_*`) is checked in the registration event handler itself
- [ ] For blueprint-driven UI, `config-default@:` is used in the blueprint fields to pull plugin defaults (Option A) — **check this first before building a `/config` endpoint**
- [ ] Feature toggles are checked both server-side (gate registration + endpoints) AND client-side (gate UI elements)
- [ ] Default modes/preferences for custom components are fetched via `/config` endpoint and used to seed component state
- [ ] Display preferences (show badge, show count, etc.) are respected in both the badge endpoint AND `_emitBadge` / equivalent
- [ ] The `/config` endpoint returns **only UI-relevant fields** — not the full plugin config (never API keys, secrets, or backend-only internals)
- [ ] Component has sensible defaults that match the plugin's YAML defaults, so first render works before fetch resolves
- [ ] Config fetch failure is non-fatal — log and continue with defaults

### Example: mapping classic admin's injected config

Admin-classic plugins typically inject config into `window` via inline JS during `onAssetsInitialized`:

```php
// admin-classic pattern — DO NOT copy to admin-next
$this->grav['assets']->addInlineJs("
    window.MyPluginConfig = {
        enableFeatureX: {$enable},
        defaultMode: '{$mode}'
    };
");
```

In admin-next, replace this with the `/config` endpoint pattern. Every `window.MyPluginConfig.foo` in the classic JS corresponds to a field in the `/config` response, fetched once per component mount.

**Note about `config-default@`**: If a classic admin plugin already uses `config-default@:` directives in its blueprint files, **those continue to work unchanged in admin-next** — the resolution happens in core `BlueprintSchema::dynamicConfig()`, which runs for every blueprint fetch regardless of which admin consumes it. You do not need to re-implement them as a `/config` endpoint. If the admin-next UI is mostly blueprint-driven (e.g. a plugin settings page, page editor forms), the classic blueprint defaults are probably already flowing through correctly. Verify by opening the form and checking that the field defaults match the plugin yaml values.

---

## Web Component Common Patterns

### Injected Globals (available in all component types)
```javascript
window.__GRAV_API_SERVER_URL  // e.g., "https://example.com/grav-api"
window.__GRAV_API_PREFIX      // e.g., "/api/v1"
window.__GRAV_API_TOKEN       // JWT access token (or null) — send as X-API-Token header
```

### Type-Specific Tag Globals
```javascript
window.__GRAV_FIELD_TAG    // Custom fields: "grav-{plugin}--{fieldType}"
window.__GRAV_WIDGET_TAG   // Floating widgets: "grav-{plugin}--widget"
window.__GRAV_PAGE_TAG     // Plugin pages: "grav-{plugin}--page"
window.__GRAV_REPORT_TAG   // Reports: "grav-{plugin}--{reportName}"
```

### API Helper Pattern (reuse across all component types)
```javascript
_apiUrl(path) {
    return (window.__GRAV_API_SERVER_URL || '') +
           (window.__GRAV_API_PREFIX || '/api/v1') + path;
}

_headers(json = false) {
    const h = {};
    const token = window.__GRAV_API_TOKEN;
    // Send the JWT as X-API-Token, NOT Authorization: Bearer. FastCGI / CGI /
    // PHP-FPM setups (notably MAMP's mod_fastcgi) silently strip the
    // Authorization header before it reaches PHP, breaking auth on those
    // hosts. Any X-* custom header passes through cleanly. The Grav API
    // server also accepts Authorization: Bearer as a fallback for standards-
    // compliant external clients — use X-API-Token here for portability.
    if (token) h['X-API-Token'] = token;
    if (json) h['Content-Type'] = 'application/json';
    return h;
}

async _api(method, path, body) {
    const opts = { method, headers: this._headers(!!body) };
    if (body) opts.body = JSON.stringify(body);
    const resp = await fetch(this._apiUrl(path), opts);
    return (await resp.json()).data;
}
```

### CSS Theming (admin-next CSS custom properties)
```css
var(--foreground)          /* text color */
var(--background)          /* background */
var(--border)              /* border color */
var(--muted)               /* muted background */
var(--muted-foreground)    /* muted text */
var(--primary)             /* primary/accent color */
var(--accent)              /* hover/active background */
var(--popover)             /* popover/dropdown background */
```

### Shadow DOM vs Light DOM
- **Shadow DOM** (`attachShadow({ mode: 'open' })`): Use for complex widgets/pages where style isolation matters. Requires all CSS inline or in `<style>` tags.
- **Light DOM** (direct `this.innerHTML`): Use for simple fields that benefit from inheriting admin-next styles. Simpler but can leak styles.

### Direction-aware components (RTL support)

Admin-next runs in both LTR and RTL — the active direction follows the user's `adminLanguage` (Arabic, Hebrew, Persian, Urdu, and anything else `LanguageCodes::isRtl()` flags). Plugin web components should honor it.

#### The contract

```javascript
window.__GRAV_I18N.dir              // 'ltr' | 'rtl' — read-only snapshot
window.__GRAV_I18N.subscribe(fn)    // fires when locale (and so direction) changes
                                    // returns an unsubscribe function
```

`<html dir>` is also set, so anything participating in normal CSS cascade picks it up for free.

#### Reading direction in a component

```javascript
class MyField extends HTMLElement {
    _getDir() {
        if (window.__GRAV_I18N && window.__GRAV_I18N.dir) {
            return window.__GRAV_I18N.dir;
        }
        return document.documentElement.getAttribute('dir') === 'rtl' ? 'rtl' : 'ltr';
    }

    connectedCallback() {
        this._render();
        // Live-update on language switch — admin-next supports changing
        // language without a hard reload.
        if (window.__GRAV_I18N && typeof window.__GRAV_I18N.subscribe === 'function') {
            this._i18nUnsub = window.__GRAV_I18N.subscribe(() => this._applyDir());
        }
    }

    disconnectedCallback() {
        if (this._i18nUnsub) { try { this._i18nUnsub(); } catch (e) {} }
    }
}
```

#### CSS conventions

Use logical CSS properties throughout — they compile to the right physical side in both directions, so you write one rule instead of two:

| Physical (don't use)        | Logical (use)                |
|-----------------------------|------------------------------|
| `padding-left` / `right`    | `padding-inline-start` / `end`|
| `margin-left` / `right`     | `margin-inline-start` / `end` |
| `border-left` / `right`     | `border-inline-start` / `end` |
| `left:` / `right:`          | `inset-inline-start` / `end`  |
| `text-align: left` / `right`| `text-align: start` / `end`   |

Tailwind v4 ships matching utilities: `ms-*` / `me-*`, `ps-*` / `pe-*`, `border-s` / `border-e`, `text-start` / `text-end`, `start-0` / `end-0`, `rounded-s-*` / `rounded-e-*`. Prefer these over the `rtl:` variant where possible — one rule per side beats two.

#### When physical + `rtl:` is the right tool

Some properties have no logical equivalent:
- CSS transforms (`-translate-x-full` etc.) — pair with `rtl:translate-x-full`.
- `@keyframes` blocks — variants don't reach inside; duplicate the keyframe with a `[dir="rtl"]` parent selector and a mirrored translateX sign.

Watch for specificity surprises: `[dir="rtl"]` attribute selector (0,1,0) outranks media-query rules (0,0,0). If you pair `rtl:` with a breakpoint, scope the RTL rule with `max-lg:` (or similar) so the responsive rule wins at the right breakpoint.

#### Code editors stay LTR

If your component embeds a code/source-style editor (CodeMirror, Monaco, etc.), pin its container `dir="ltr"` regardless of the admin direction. Source code, markdown, YAML, and JSON are always left-to-right.

#### Directional icons

Lucide/heroicon chevrons and arrows don't auto-flip. Either:
- Pick the right icon at render time based on `_getDir()`, or
- Apply the `.flip-rtl` utility class (admin-next ships this — applies `transform: scaleX(-1)` in RTL only). Use sparingly; explicit icon swaps read better.

Don't flip vertical chevrons (`chevron-up` / `chevron-down`) — they never change direction.

#### Reference implementation

`grav-plugin-editor-pro`'s TipTap field is the canonical example:
- `getEditorDir()` helper (`admin/assets/editor-pro.js`) — reads `__GRAV_I18N.dir`, falls back to `<html dir>`.
- `editorProps.attributes.dir` plumbs it into the ProseMirror DOM at editor creation.
- An `_i18nUnsub` subscription in `onCreate` re-applies `dir` live; the matching teardown sits in `onDestroy`.

---

## File Structure Convention

```
my-plugin/
├── admin-next/
│   ├── fields/
│   │   ├── my-field-type.js         # Custom field (Section F)
│   │   └── another-field.js
│   ├── widgets/
│   │   └── my-plugin.js            # Floating widget (Section D)
│   ├── pages/
│   │   └── my-plugin.js            # Plugin page component (Section E)
│   └── reports/
│       └── my-plugin-report.js     # Report component (Section G)
├── classes/
│   └── Api/
│       └── MyApiController.php     # API controller (Section A)
├── blueprints/
│   └── my-plugin/
│       └── settings.yaml           # Plugin page blueprint (Section E)
├── languages/
│   └── en.yaml                     # Translations (Section J)
├── permissions.yaml                # Permissions (Section I)
├── my-plugin.php                   # Main plugin class
└── my-plugin.yaml                  # Plugin configuration defaults
```

---

## Detecting admin context: never gate event subscription on `isAdmin()` in `onPluginsInitialized`

In Grav 2.0, admin-next saves go through the **API plugin**, not admin-classic. The API plugin only registers `$grav['admin']` (a lightweight `AdminProxy`) **during request dispatch** (`onRequestHandlerInit` → `ApiRouter`), which runs *after* `onPluginsInitialized` has finished. As a result, `$this->isAdmin()` (i.e. `Utils::isAdminPlugin()` / `isset($grav['admin'])`) is **`false` at `onPluginsInitialized` time on the API path**, even though the request is an admin-scoped write.

This breaks the common Grav 1.x pattern of gating event subscription on `isAdmin()`:

```php
// ❌ WRONG — silently no-ops under admin-next / API saves.
// On the API path $grav['admin'] isn't registered yet at this point, so the
// admin/Flex write hooks below are NEVER subscribed and your index/cache/etc.
// never updates when content is saved through admin-next.
public function onPluginsInitialized(): void
{
    if ($this->isAdmin()) {
        $this->enable([
            'onAdminAfterSave'        => ['onObjectSave', 0],
            'onFlexObjectAfterSave'   => ['onObjectSave', 0],
        ]);
    }
}
```

**Right way — subscribe write events unconditionally (config-gated only):**

```php
// ✅ CORRECT — these events only FIRE during admin/API write operations, so
// subscribing on the frontend too is harmless. Gate on config, not isAdmin().
public function onPluginsInitialized(): void
{
    if ($this->config->get('plugins.my-plugin.enable_index_events', true)) {
        $this->enable([
            'onAdminAfterSave'        => ['onObjectSave', 0],
            'onAdminAfterDelete'      => ['onObjectDelete', 0],
            'onAdminAfterSaveAs'      => ['onObjectMove', 0],   // move/rename
            'onFlexObjectAfterSave'   => ['onObjectSave', 0],
            'onFlexObjectAfterDelete' => ['onObjectDelete', 0],
        ]);
    }

    // isAdmin()-gated blocks are fine ONLY for admin-classic-specific UI
    // (admin menu, classic Twig templates, admin-classic routes) — never for
    // data-write hooks that must also fire for admin-next/API saves.
}
```

Notes:
- `isAdmin()` is still reliable **inside a handler that runs during request dispatch** (by then the proxy is registered) and for genuinely admin-classic-only UI. The pitfall is specifically gating subscription at `onPluginsInitialized` time.
- Note the Grav 2.0 event names: Flex fires the `*After*` variants (`onFlexObjectAfterSave` / `onFlexObjectAfterDelete`), not bare `onFlexObjectSave` / `onFlexObjectDelete`.
- Plugins must be re-released with `compatibility: '2.0'` to install on Grav 2.0 anyway — that re-release is the moment to convert any `isAdmin()`-at-init gating to the pattern above.

## Event Subscription Summary

All admin-next events to subscribe to (include only what's needed):

```php
public static function getSubscribedEvents(): array
{
    return [
        'onPluginsInitialized'    => [
            ['autoload', 100001],
            ['onPluginsInitialized', 0],
        ],
        // API integration
        'onApiRegisterRoutes'     => ['onApiRegisterRoutes', 0],
        'onApiSidebarItems'       => ['onApiSidebarItems', 0],
        'onApiMenubarItems'       => ['onApiMenubarItems', 0],
        'onApiMenubarAction'      => ['onApiMenubarAction', 0],
        'onApiFloatingWidgets'    => ['onApiFloatingWidgets', 0],
        'onApiPluginPageInfo'     => ['onApiPluginPageInfo', 0],
        'onApiGenerateReports'    => ['onApiGenerateReports', 0],
        'onApiBlueprintResolved'  => ['onApiBlueprintResolved', 0],
        'PermissionsRegisterEvent' => ['onPermissionsRegister', 0],
    ];
}
```

---

## Important Notes

1. **Permission checking**: `$user->authorize()` does NOT work in API context. Always use `$user->get('access.permission.name')` or the controller's `$this->requirePermission()`.
2. **Route cache**: Clear `cache/api/route.cache` after adding or changing routes.
3. **Guard clauses**: Always check `$event['plugin'] !== 'my-plugin'` in `onApiMenubarAction` and `onApiPluginPageInfo`.
4. **Autoloading**: Ensure the plugin has a `vendor/autoload.php` (run `composer dump-autoload`) and loads it via `autoload()` method.
5. **Icon conventions**: Sidebar and menubar use **Font Awesome** icons (with `fa-` prefix). Floating widgets and context panels use **Lucide** icon names (without prefix).
6. **Admin::enablePages()**: If the plugin needs to access the Grav pages object within admin context, call `Admin::enablePages()` first. From within API controllers, use `AdminProxy::enablePages()` from the API plugin.
7. **Event array pattern**: Always read with `$event['items'] ?? []`, modify, then write back with `$event['items'] = $items`.
8. **Static before parameterized**: In route registration, define static routes (e.g., `/items`) before parameterized routes (e.g., `/items/{id}`) due to FastRoute's matching order.
9. **Config integration is not automatic**: The framework does NOT pass plugin config to web components. If the plugin has admin-relevant config in its blueprints.yaml, you MUST follow Section K to expose it via a `/config` endpoint and fetch it from components. Missing this step leaves user preferences (default modes, feature toggles, display settings) unhonored in admin-next.
10. **One web component per plugin slug**: The framework maps `grav-{slug}--{kind}` tags one-to-one with a single JS file per kind (`admin-next/widgets/{slug}.js`, `admin-next/panels/{slug}.js`, etc.). If a plugin registers multiple panels/widgets of the same kind, they all share one web component — implement internal view switching rather than expecting multiple tags.
11. **Never gate event subscription on `isAdmin()` in `onPluginsInitialized`**: On the admin-next/API path `$grav['admin']` is registered during request dispatch, *after* `onPluginsInitialized`, so `isAdmin()` is `false` there and any write hooks (`onAdminAfterSave`, `onFlexObjectAfterSave`, etc.) you subscribe inside an `isAdmin()` block silently never fire for admin-next saves. Subscribe those events unconditionally (config-gated) — see "Detecting admin context" above.

## Testing

Clear route cache and test endpoints:
```bash
# Clear route cache
rm -f cache/api/route.cache

# Test API endpoint
curl -sk "https://localhost/grav-api/api/v1/my-plugin/items" \
  -H "X-API-Key: YOUR_KEY" \
  -H "X-Grav-Environment: localhost"
```
