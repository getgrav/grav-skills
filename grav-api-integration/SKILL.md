---
name: grav-api-integration
description: Use when adding REST API endpoints to a Grav 2.0 plugin via the first-party Grav API plugin. Covers controller setup extending AbstractApiController, route registration via `onApiRegisterRoutes`, permission checks, response helpers, exceptions, and curl-based testing. Trigger when working under `user/plugins/<plugin>/` on code that exposes plugin functionality as HTTP endpoints, or when the user mentions Grav API, AbstractApiController, ApiResponse, or onApiRegisterRoutes. Use the broader `grav-api-admin-next-integration` skill instead if the plugin also needs custom Admin Next UI (sidebar, menubar, fields, pages, widgets, panels, reports).
---

# Grav Plugin API Integration

Add REST API endpoints to a Grav plugin using the Grav API plugin's extensibility system.

## Instructions

You are adding API integration to a Grav CMS plugin. Follow this exact pattern, which has been tested and proven with the Email and License Manager plugins.

### Step 1: Analyze the Plugin

Read the plugin's main PHP file and classes directory to understand:
- What data/functionality the plugin manages
- What operations make sense as API endpoints (CRUD, actions, queries)
- What Grav services or classes are used internally
- The plugin's namespace and autoloading setup

### Step 2: Create the API Controller

Create a new file `classes/{PluginName}ApiController.php` that:
- Lives in the plugin's existing namespace (e.g., `Grav\Plugin\Email\`)
- Extends `Grav\Plugin\Api\Controllers\AbstractApiController`
- Has one public method per API endpoint
- Each method accepts `ServerRequestInterface $request` and returns `ResponseInterface`

**Key patterns:**
- Use `$this->requirePermission($request, 'api.system.read')` for read endpoints
- Use `$this->requirePermission($request, 'api.system.write')` for write endpoints
- Use `$this->getRequestBody($request)` to parse JSON body
- Use `$this->requireFields($body, ['field1', 'field2'])` to validate required fields
- Use `$this->getRouteParam($request, 'name')` for URL parameters
- Use `$this->getPagination($request)` for paginated listings
- Use `$this->fireEvent('onPluginAction', [...])` to fire events
- Return `ApiResponse::create($data)` for 200 responses
- Return `ApiResponse::created($data, $location)` for 201 responses
- Return `ApiResponse::noContent()` for 204 responses
- Return `ApiResponse::paginated(...)` for paginated lists
- Throw `NotFoundException`, `ValidationException`, `ForbiddenException`, `ApiException` for errors
- Mask/redact any sensitive data (secrets, keys, passwords) in responses

### Step 3: Register Routes

Add to the plugin's `getSubscribedEvents()`:
```php
'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
```

Add the handler method:
```php
public function onApiRegisterRoutes(Event $event): void
{
    $routes = $event['routes'];
    $controller = \Grav\Plugin\PluginName\PluginApiController::class;

    $routes->get('/resource', [$controller, 'index']);
    // ... more routes
}
```

### Step 4: Ensure Autoloading

If the plugin doesn't already have an `autoload()` method, add one:
```php
public function autoload(): \Composer\Autoload\ClassLoader
{
    return require __DIR__ . '/vendor/autoload.php';
}
```

Then run `composer dump-autoload` in the plugin directory.

### Step 5: Create API Documentation

Create an `api-docs/` directory with:

1. **`chapter.md`** — Overview page with Helios `chapter` template
2. **Endpoint pages** — One directory per endpoint, each containing `api-endpoint.md` with the Helios `api-endpoint` template frontmatter:
   ```yaml
   api:
     method: POST
     path: /resource
     description: 'What it does'
     parameters: [{name, type, required, description}]
     request_example: '{"json": "example"}'
     response_example: '{"data": {...}}'
     response_codes: [{code, description}]
   ```
3. **Postman collection** — `grav-{plugin}-api.postman_collection.json` using Postman v2.1 format with explicit headers (`X-API-Key: {{api_key}}`, `X-Grav-Environment: {{grav_environment}}`, `Content-Type: application/json`) and standard variables (`{{base_url}}`, `{{api_prefix}}`)

### Step 6: Update Plugin README

Add an "## REST API Integration" section to the plugin's README.md documenting:
- Available endpoints with curl examples
- Required permissions
- Link to api-docs/ for full documentation

### Step 7: Test

Clear the route cache (`cache/api/route.cache`) and test every endpoint with curl:
```bash
curl -sk "https://localhost/grav-api/api/v1/your-endpoint" \
  -H "X-API-Key: YOUR_KEY" \
  -H "X-Grav-Environment: localhost"
```

## Important Notes

- The API plugin uses Grav's route attribute (not raw PSR-7 URI) for subdirectory safety
- `$user->authorize()` does NOT work outside admin context — use `$user->get('access.permission.name')` via the `isSuperAdmin()` and `hasPermission()` helpers on AbstractApiController
- `UserCollection` iteration doesn't work in API context — scan account files directly if needed
- Always clear route cache after adding new routes
- Plugins must have their vendor autoloader loaded for their classes to be found
