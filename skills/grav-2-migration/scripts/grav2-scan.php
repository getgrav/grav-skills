#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * grav2-scan.php — read-only survey of a Grav site being migrated from 1.7/1.8 to 2.0.
 *
 * Reports everything the migration has to decide, and NEVER writes anything:
 *
 *   1. Environment      — PHP/Grav version, writability, which core config is in place
 *   2. Plugins & themes — installed version, 2.0 verdict, GPM's latest 2.0 release,
 *                         supersedes (admin → admin2 + api), required-plugin floors
 *   3. Accounts/groups  — classic `admin.*` grants and the `api.*` set they map to
 *   4. Twig in content  — which gates to open, and the exact allowlists to write
 *   5. Image URL actions— query-string transforms that need `system.images.url_actions`
 *   6. Raw HTML tags    — markup the 2.0 GFM tagfilter would escape
 *
 * Run it against the site root (the directory holding `user/`), before and after
 * swapping core to 2.0. Run it on 2.0 core when you can: the allowlist baseline
 * and the dangerous-function check are then read from core itself rather than
 * from this script's fallbacks.
 *
 *   php grav2-scan.php                  # scan the current directory
 *   php grav2-scan.php --root=/srv/site
 *   php grav2-scan.php --json           # machine-readable
 *   php grav2-scan.php --offline        # skip the GPM / compat-registry lookups
 *
 * Requires PHP 8.1+ and the site's own `vendor/` (for Symfony Yaml).
 */

// Loading a 1.7 install's vendor/ under PHP 8.3+ emits a wall of deprecations
// that has nothing to do with the scan.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// ─── options ────────────────────────────────────────────────────────────────

$opts    = getopt('', ['root::', 'json', 'offline', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "usage: php grav2-scan.php [--root=PATH] [--json] [--offline]\n");
    exit(0);
}
$root    = rtrim((string) ($opts['root'] ?? getcwd()), '/');
$asJson  = isset($opts['json']);
$offline = isset($opts['offline']);

if (!is_dir($root . '/user')) {
    fwrite(STDERR, "error: no user/ directory under {$root} — point --root at the Grav site root.\n");
    exit(1);
}

$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
if (!class_exists('Symfony\\Component\\Yaml\\Yaml')) {
    fwrite(STDERR, "error: Symfony Yaml not available. Expected {$root}/vendor/autoload.php.\n");
    exit(1);
}

const YAML = '\\Symfony\\Component\\Yaml\\Yaml';

$userDir   = $root . '/user';
$pagesDir  = $userDir . '/pages';
$report    = [];

// ─── shared helpers ─────────────────────────────────────────────────────────

function y_parse(string $path): ?array
{
    if (!is_file($path)) return null;
    try {
        $d = (YAML)::parseFile($path);
    } catch (\Throwable) {
        return null;
    }
    return is_array($d) ? $d : null;
}

function truthy(mixed $v): bool
{
    return $v === true || $v === 1 || $v === '1'
        || (is_string($v) && in_array(strtolower($v), ['true', 'yes', 'on'], true));
}

/** Split a page file into [frontmatter, body]. */
function split_frontmatter(string $raw): array
{
    if (!str_starts_with(ltrim($raw), '---')) return ['', $raw];
    $raw = ltrim($raw);
    $end = strpos($raw, "\n---", 3);
    if ($end === false) return ['', $raw];
    $fm   = substr($raw, 4, $end - 4);
    $rest = substr($raw, $end + 4);
    return [$fm, ltrim($rest, "\r\n")];
}

/** @return list<string> */
function token_list(mixed $val): array
{
    if (!is_array($val)) return [];
    $out = [];
    foreach ($val as $v) {
        if (is_string($v) && $v !== '') $out[] = $v;
    }
    return array_values(array_unique($out));
}

/** Every .md file under a directory. @return list<string> */
function md_files(string $dir): array
{
    if (!is_dir($dir)) return [];
    $out = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isDir() && strtolower($f->getExtension()) === 'md') $out[] = $f->getPathname();
    }
    sort($out);
    return $out;
}

function rel_to(string $path, string $base): string
{
    return ltrim(str_replace($base, '', $path), '/\\');
}

/** GET a URL through whichever transport the host allows. Returns null on failure. */
function http_get_json(string $url, int $timeout = 6): ?array
{
    $raw = null;
    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $ctx = stream_context_create(['http' => [
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'header'        => "User-Agent: grav2-scan/1.0\r\n",
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
    } elseif (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'grav2-scan/1.0',
        ]);
        $raw = curl_exec($ch);
    }
    if (!is_string($raw) || $raw === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

// ─── 1. environment ─────────────────────────────────────────────────────────

$gravVersion = null;
$defines = $root . '/system/defines.php';
if (is_file($defines) && preg_match('/GRAV_VERSION[\'"]?\s*,\s*[\'"]([^\'"]+)/', (string) @file_get_contents($defines), $m)) {
    $gravVersion = $m[1];
}
$coreSecurity = $root . '/system/config/security.yaml';
$coreIs20     = $gravVersion !== null && version_compare($gravVersion, '2.0', '>=');

$report['environment'] = [
    'root'            => $root,
    'php'             => PHP_VERSION,
    'php_ok'          => version_compare(PHP_VERSION, '8.3.0', '>='),
    'grav_version'    => $gravVersion,
    'core_is_2x'      => $coreIs20,
    'vendor_autoload' => is_file($autoload),
    'user_writable'   => is_writable($userDir),
    'has_backup_dir'  => is_dir($root . '/backup'),
];

// ─── 2. plugins & themes ────────────────────────────────────────────────────

// Canonical supersedes / floors. Mirrors migrate-grav's baseline registry; the
// live registry (below) wins per-slug when reachable.
$baselineRegistry = [
    'plugins' => [
        'admin'        => ['grav' => ['1.7'], 'replaced_by' => 'admin2', 'notes' => 'Replaced by Admin 2.0'],
        'admin2'       => ['grav' => ['2.0'], 'github_repo' => 'getgrav/grav-plugin-admin2', 'requires' => ['api']],
        'api'          => ['grav' => ['2.0'], 'github_repo' => 'getgrav/grav-plugin-api'],
        'flex-objects' => ['grav' => ['2.0'], 'minimum_version' => '1.4.0', 'github_repo' => 'trilbymedia/grav-plugin-flex-objects', 'notes' => 'Requires v1.4.0+ for Grav 2.0'],
        'form'         => ['grav' => ['2.0'], 'github_repo' => 'getgrav/grav-plugin-form'],
        'login'        => ['grav' => ['2.0'], 'github_repo' => 'getgrav/grav-plugin-login'],
        'email'        => ['grav' => ['2.0'], 'github_repo' => 'getgrav/grav-plugin-email'],
        'problems'     => ['grav' => ['2.0'], 'github_repo' => 'getgrav/grav-plugin-problems'],
    ],
    'themes' => [],
];
// Never disabled by a compat verdict — disabling one is how a migration locks
// the operator out of admin.
const PROTECTED_PLUGINS = ['flex-objects', 'form', 'login', 'email', 'problems'];
// Must be present AND enabled before the site is considered migrated.
const CRITICAL_PLUGINS  = ['api', 'admin2', 'flex-objects', 'login'];

$registry = $baselineRegistry;
$gpmIndex = ['plugins' => null, 'themes' => null];
$netNotes = [];

if (!$offline) {
    $remote = http_get_json('https://getgrav.org/gpm/compatibility/v1/_all', 4);
    if ($remote === null) {
        $netNotes[] = 'compat registry unreachable — using the built-in baseline only';
    } else {
        foreach (['plugins', 'themes'] as $kind) {
            foreach ($baselineRegistry[$kind] as $slug => $entry) {
                if (!isset($remote[$kind][$slug])) $remote[$kind][$slug] = $entry;
            }
            $registry[$kind] = $remote[$kind] ?? $baselineRegistry[$kind];
        }
    }
    foreach (['plugins', 'themes'] as $kind) {
        $url = 'https://getgrav.org/downloads/' . $kind . '.json?'
             . http_build_query(['v' => '2.0.0', 'php' => PHP_VERSION, 'testing' => 1]);
        $idx = http_get_json($url, 8);
        if ($idx === null) {
            $netNotes[] = "GPM {$kind} index unreachable — 'latest 2.0 release' column unavailable";
            continue;
        }
        $gpmIndex[$kind] = $idx;
    }
}

/** Port of Grav's Local\Package::inferCompatibility. @return list<string> */
function infer_compat(array $dependencies): array
{
    foreach ($dependencies as $dep) {
        if (!is_array($dep) || ($dep['name'] ?? '') !== 'grav') continue;
        if (!preg_match('/(\d+\.\d+(?:\.\d+)?)/', (string) ($dep['version'] ?? ''), $m)) continue;
        if (version_compare($m[1], '2.0', '>=')) return ['2.0'];
        if (version_compare($m[1], '1.8', '>=')) return ['1.8'];
        return ['1.7'];
    }
    return ['1.7'];
}

/**
 * Resolve one package's 2.0 verdict: curated registry → blueprint
 * `compatibility.grav` → inference from `dependencies.grav` → assume 1.7-only.
 */
function resolve_compat(string $slug, string $installed, array $bp, array $curated): array
{
    if (isset($curated[$slug]) && is_array($curated[$slug])) {
        $entry = $curated[$slug];
        $gravs = (array) ($entry['grav'] ?? []);
        $min   = (string) ($entry['minimum_version'] ?? '');
        $repl  = $entry['replaced_by'] ?? null;
        if (!in_array('2.0', array_map('strval', $gravs), true)) {
            return ['status' => 'incompatible', 'source' => 'curated', 'replaced_by' => $repl,
                    'min_version' => $min ?: null,
                    'reason' => $repl ? "Deprecated on 2.0 — use {$repl}" : (string) ($entry['notes'] ?? '1.x-only')];
        }
        if ($min !== '' && $installed !== '' && version_compare($installed, $min, '<')) {
            return ['status' => 'needs_update', 'source' => 'curated', 'replaced_by' => null, 'min_version' => $min,
                    'reason' => "Requires v{$min}+ for 2.0 (installed {$installed})"];
        }
        return ['status' => 'compatible', 'source' => 'curated', 'replaced_by' => null, 'min_version' => $min ?: null,
                'reason' => (string) ($entry['notes'] ?? 'Curated 2.0-compatible')];
    }
    $bpCompat = $bp['compatibility']['grav'] ?? null;
    if (is_array($bpCompat)) {
        $ok = in_array('2.0', array_map('strval', $bpCompat), true);
        return ['status' => $ok ? 'compatible' : 'incompatible', 'source' => 'blueprint',
                'replaced_by' => null, 'min_version' => null,
                'reason' => $ok ? 'Blueprint declares 2.0 support' : 'Blueprint lists only ' . implode(',', $bpCompat)];
    }
    if (in_array('2.0', infer_compat((array) ($bp['dependencies'] ?? [])), true)) {
        return ['status' => 'compatible', 'source' => 'inferred', 'replaced_by' => null, 'min_version' => null,
                'reason' => 'Inferred from dependencies.grav >= 2.0'];
    }
    return ['status' => 'incompatible', 'source' => 'default', 'replaced_by' => null, 'min_version' => null,
            'reason' => 'Assumed 1.7-only (no explicit 2.0 compatibility)'];
}

$packages = ['plugins' => [], 'themes' => []];
foreach (['plugins', 'themes'] as $kind) {
    $dir = $userDir . '/' . $kind;
    if (!is_dir($dir)) continue;
    foreach (scandir($dir) ?: [] as $slug) {
        if ($slug === '.' || $slug === '..' || $slug[0] === '.') continue;
        $path = $dir . '/' . $slug;
        if (!is_dir($path)) continue;

        $bp        = y_parse($path . '/blueprints.yaml') ?? [];
        $installed = (string) ($bp['version'] ?? '');
        $verdict   = resolve_compat($slug, $installed, $bp, $registry[$kind] ?? []);

        $cfg     = $userDir . '/config/' . $kind . '/' . $slug . '.yaml';
        $enabled = true;
        if (is_file($cfg) && preg_match('/^enabled:\s*(false|0)\s*$/m', (string) @file_get_contents($cfg))) {
            $enabled = false;
        }

        $latest = null;
        if (isset($gpmIndex[$kind][$slug])) {
            $latest = (string) ($gpmIndex[$kind][$slug]['version'] ?? '') ?: null;
        }

        $packages[$kind][$slug] = [
            'installed'   => $installed,
            'latest_2x'   => $latest,
            'update'      => ($latest && $installed && version_compare($latest, $installed, '>')) ? $latest : null,
            'enabled'     => $enabled,
            'symlink'     => is_link($path),
            'status'      => $verdict['status'],
            'reason'      => $verdict['reason'],
            'source'      => $verdict['source'],
            'replaced_by' => $verdict['replaced_by'],
            'min_version' => $verdict['min_version'],
            'protected'   => $kind === 'plugins' && in_array($slug, PROTECTED_PLUGINS, true),
            'critical'    => $kind === 'plugins' && in_array($slug, CRITICAL_PLUGINS, true),
        ];
    }
    ksort($packages[$kind]);
}
$report['packages']      = $packages;
$report['network_notes'] = $netNotes;

// The 1.7 admin route has to be carried into admin2's own config.
$adminCfg   = y_parse($userDir . '/config/plugins/admin.yaml') ?? [];
$adminRoute = is_string($adminCfg['route'] ?? null) ? '/' . trim(trim((string) $adminCfg['route']), '/') : null;
$report['admin_route'] = ($adminRoute !== null && $adminRoute !== '/admin' && $adminRoute !== '/') ? $adminRoute : null;

// ─── 3. accounts & groups ───────────────────────────────────────────────────

/** Classic permissions Grav 2.0 still reads under their ORIGINAL admin.* name. */
const ADMIN_ONLY_PERMS = ['pages_twig', 'impersonate'];

/** Classic `admin.<key>` → the registered `api.*` permission(s) it becomes. */
function perm_targets(string $key, array &$notes): array
{
    $map = [
        'login'       => ['api.access'],
        'super'       => ['api.super'],
        'pages'       => ['api.pages', 'api.media'],
        'users'       => ['api.users'],
        'cache'       => ['api.system.write'],
        'tools'       => ['api.system.read'],
        'statistics'  => ['api.reports.read'],
        'plugins'     => ['api.gpm'],
        'themes'      => ['api.gpm'],
        'maintenance' => ['api.system.backup', 'api.gpm'],
    ];
    if (isset($map[$key])) return $map[$key];
    if (in_array($key, ADMIN_ONLY_PERMS, true)) {
        $notes[] = ['code' => 'admin_only', 'key' => 'admin.' . $key];
        return [];
    }
    if ($key === 'configuration') return ['api.config'];
    if (str_starts_with($key, 'configuration.') || str_starts_with($key, 'configuration_')) {
        $notes[] = ['code' => 'config_partial', 'key' => 'admin.' . $key];
        return ['api.config.read'];
    }
    $notes[] = ['code' => 'verbatim', 'key' => 'admin.' . $key];
    return ['api.' . $key];
}

/** Flatten a nested access map to dot notation, as Grav's ACL does. */
function flatten_access(array $node, string $prefix = ''): array
{
    $out = [];
    foreach ($node as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) $out += flatten_access($v, $key);
        else $out[$key] = $v;
    }
    return $out;
}

/**
 * Work out the api.* grants one `access:` map should gain. Additive: existing
 * admin.* entries stay, and an api.* key already set at the target or any
 * ancestor of it always wins.
 *
 * @return array{add: array<string,mixed>, notes: list<array{code:string,key:string}>, has_access: bool}
 */
function plan_access(array $access): array
{
    $flat     = flatten_access($access);
    $classic  = [];
    $existing = [];
    foreach ($flat as $k => $v) {
        if (str_starts_with($k, 'admin.')) $classic[substr($k, 6)] = $v;
        elseif ($k === 'api' || str_starts_with($k, 'api.')) $existing[$k] = $v;
    }

    $notes    = [];
    $proposed = [];
    foreach ($classic as $key => $value) {
        foreach (perm_targets((string) $key, $notes) as $target) {
            if (!array_key_exists($target, $proposed) || (!truthy($proposed[$target]) && truthy($value))) {
                $proposed[$target] = $value;
            }
        }
    }
    // api.access is the gate every endpoint checks first, and no classic
    // permission corresponds to it. Any positive grant implies it.
    if (!isset($proposed['api.access'])) {
        foreach ($proposed as $value) {
            if (truthy($value)) { $proposed['api.access'] = true; break; }
        }
    }

    $add = [];
    foreach ($proposed as $target => $value) {
        $probe = $target;
        $covered = false;
        while (true) {
            if (array_key_exists($probe, $existing)) { $covered = true; break; }
            $pos = strrpos($probe, '.');
            if ($pos === false) break;
            $probe = substr($probe, 0, $pos);
        }
        if ($covered) {
            if ($probe !== $target) $notes[] = ['code' => 'preset', 'key' => $target . ' (covered by ' . $probe . ')'];
            continue;
        }
        $add[$target] = $value;
    }

    $result = array_merge($existing, $add);
    $hasAccess = truthy($result['api.access'] ?? null) || truthy($result['api.super'] ?? null);

    return ['add' => $add, 'notes' => $notes, 'has_access' => $hasAccess];
}

$accounts = [];
$acctDir  = $userDir . '/accounts';
if (is_dir($acctDir)) {
    foreach (scandir($acctDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') continue;
        if (!preg_match('/\.yaml$/i', $entry)) continue;
        $data = y_parse($acctDir . '/' . $entry);
        if ($data === null) { $accounts[$entry] = ['error' => 'unparseable']; continue; }

        $plan = is_array($data['access'] ?? null) ? plan_access($data['access']) : ['add' => [], 'notes' => [], 'has_access' => false];
        $lang = is_string($data['language'] ?? null) ? trim((string) $data['language']) : '';
        $accounts[$entry] = [
            'add'          => $plan['add'],
            'notes'        => $plan['notes'],
            'has_access'   => $plan['has_access'],
            'language'     => $lang !== '' ? substr($lang, 0, 32) : null,
            'language_set' => is_string($data['admin_next']['preferences']['adminLanguage'] ?? null)
                              && $data['admin_next']['preferences']['adminLanguage'] !== '',
        ];
    }
    ksort($accounts);
}

$groups     = [];
$groupsFile = $userDir . '/config/groups.yaml';
$groupsData = y_parse($groupsFile);
if ($groupsData !== null) {
    foreach ($groupsData as $name => $group) {
        if (!is_array($group) || !is_array($group['access'] ?? null)) continue;
        $plan = plan_access($group['access']);
        $groups[(string) $name] = ['add' => $plan['add'], 'notes' => $plan['notes'], 'has_access' => $plan['has_access']];
    }
}
$report['accounts']      = $accounts;
$report['groups']        = $groups;
$report['groups_file']   = is_file($groupsFile);

// ─── 4. Twig in content ─────────────────────────────────────────────────────

/**
 * Names Grav 2.0 deliberately keeps out of the content sandbox — they enable
 * SSTI or arbitrary file access from editor content. Never auto-allowlist them.
 */
/**
 * Twig's own built-ins. A name from this list that isn't in core's allowlist
 * isn't plugin-provided — 2.0 simply doesn't permit it in sandboxed content
 * yet, and `|raw` (very common in 1.x page content) is the one that bites.
 */
const TWIG_BUILTINS = [
    'raw', 'spaceless', 'escape', 'e', 'default', 'batch', 'filter', 'map', 'reduce',
    'attribute', 'block', 'constant', 'cycle', 'dump', 'include', 'max', 'min',
    'parent', 'random', 'range', 'source', 'template_from_string',
];

const SANDBOX_DENYLIST = [
    'include', 'source', 'template_from_string', 'constant',
    'evaluate', 'evaluate_twig', 'svg_image', 'read_file',
    'redirect_me', 'http_response_code',
];

function is_dangerous_function(string $name): bool
{
    $utils = '\\Grav\\Common\\Utils';
    if (method_exists($utils, 'isDangerousFunction')) {
        return (bool) $utils::isDangerousFunction($name);
    }
    static $bad = [
        'exec', 'passthru', 'system', 'shell_exec', 'popen', 'proc_open', 'pcntl_exec',
        'assert', 'preg_replace', 'create_function', 'include', 'include_once',
        'require', 'require_once', 'eval', 'call_user_func', 'call_user_func_array',
        'extract', 'parse_str', 'putenv', 'ini_set', 'mail', 'header', 'unserialize',
        'fopen', 'file_put_contents', 'file_get_contents', 'fwrite', 'unlink',
        'phpinfo', 'getenv',
    ];
    return in_array(strtolower($name), $bad, true);
}

/** @return array{functions:list<string>,filters:list<string>,methods:list<string>} */
function extract_twig_tokens(string $body): array
{
    if ($body === '' || (!str_contains($body, '{{') && !str_contains($body, '{%'))) {
        return ['functions' => [], 'filters' => [], 'methods' => []];
    }
    // Prefer core's own extractor so suggestions match Admin's "scan content".
    $security = '\\Grav\\Common\\Security';
    if (method_exists($security, 'extractTwigTokens')) {
        $t = (array) $security::extractTwigTokens($body);
        return [
            'functions' => array_values($t['functions'] ?? []),
            'filters'   => array_values($t['filters'] ?? []),
            'methods'   => array_values($t['methods'] ?? []),
        ];
    }
    $macros = [];
    if (preg_match_all('/\{%-?\s*macro\s+([a-zA-Z_]\w*)\s*\(/', $body, $mm)) {
        foreach ($mm[1] as $n) $macros[strtolower($n)] = true;
    }
    if (preg_match_all('/\{%-?\s*from\b[^%]*\bimport\b([^%]*)%\}/', $body, $im)) {
        foreach ($im[1] as $clause) {
            if (preg_match_all('/[a-zA-Z_]\w*/', $clause, $names)) {
                foreach ($names[0] as $n) if (strtolower($n) !== 'as') $macros[strtolower($n)] = true;
            }
        }
    }
    $functions = $filters = $methods = [];
    if (preg_match_all('/\{\{.*?\}\}|\{%.*?%\}/s', $body, $regions)) {
        foreach ($regions[0] as $region) {
            if (preg_match_all('/(?<![\w.|])([a-zA-Z_]\w*)\s*\(/', $region, $fm)) {
                foreach ($fm[1] as $fn) if (!isset($macros[strtolower($fn)])) $functions[$fn] = true;
            }
            if (preg_match_all('/\|\s*([a-zA-Z_]\w*)/', $region, $flm)) {
                foreach ($flm[1] as $fl) $filters[$fl] = true;
            }
            if (preg_match_all('/\.([a-zA-Z_]\w*)\s*\(/', $region, $mm2)) {
                foreach ($mm2[1] as $m) $methods[$m] = true;
            }
        }
    }
    return ['functions' => array_keys($functions), 'filters' => array_keys($filters), 'methods' => array_keys($methods)];
}

/**
 * Media methods all resolve on the concrete base class — the sandbox matches by
 * instanceof, so allowing a method on Medium allows it on ImageMedium et al.
 */
const MEDIUM_CLASS = 'Grav\\Common\\Page\\Medium\\Medium';
const MEDIA_METHODS = [
    'url', 'html', 'link', 'lightbox', 'display', 'thumbnail', 'parsedownElement',
    'lazy', 'srcset', 'sizes', 'autoSizes', 'sizesViewports',
    'resize', 'forceResize', 'cropResize', 'crop', 'cropZoom', 'cropResizeZoom',
    'quality', 'format', 'negate', 'brightness', 'contrast', 'grayscale',
    'rotate', 'flip', 'fixOrientation', 'gaussianBlur', 'sharp', 'emboss',
    'sepia', 'sepiaColor', 'edge', 'colorize', 'pixelate', 'merge',
    'enableResponsiveImages', 'derivatives', 'watermark', 'fixDirRotation',
];

/** Core's default sandbox lists, read from the 2.0 core config when present. */
function sandbox_baseline(string $coreSecurity): array
{
    $out = ['functions' => [], 'filters' => [], 'methods' => [], 'available' => false];
    $cfg = y_parse($coreSecurity);
    if ($cfg === null) return $out;
    // "Available" means core actually ships the 2.0 sandbox defaults — a 1.7
    // security.yaml parses fine but has no twig_sandbox block at all, and
    // treating that as a baseline would produce a partial (destructive) list.
    $out['available'] = !empty($cfg['twig_sandbox']['allowed_functions']);
    $out['functions'] = token_list($cfg['twig_sandbox']['allowed_functions'] ?? []);
    $out['filters']   = token_list($cfg['twig_sandbox']['allowed_filters'] ?? []);
    foreach ((array) ($cfg['twig_sandbox']['allowed_methods'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        $class = (string) ($entry['class'] ?? '');
        if ($class === '') continue;
        $raw = $entry['methods'] ?? '';
        $parts = is_string($raw) ? array_filter(array_map('trim', explode(',', $raw))) : token_list($raw);
        $out['methods'][$class] = array_values(array_unique(array_map('strtolower', $parts)));
    }
    return $out;
}

$twig = [
    'system_process_twig'       => false,
    'system_process_twig_files' => [],
    'frontmatter_twig'          => false,
    'undefined_functions_was_on'=> false,
    'safe_functions'            => [],
    'safe_filters'              => [],
    'pages_with_twig'           => [],
    'config_pages'              => [],
    'gate_needed'               => false,
    'config_access_needed'      => false,
];

$systemYaml  = $userDir . '/config/system.yaml';
$systemFiles = array_values(array_filter(array_merge(
    [$systemYaml],
    glob($userDir . '/env/*/config/system.yaml') ?: []
), 'is_file'));

foreach ($systemFiles as $sysFile) {
    $sys = y_parse($sysFile);
    if ($sys === null) continue;
    if (truthy($sys['pages']['process']['twig'] ?? null)) {
        $twig['system_process_twig'] = true;
        $twig['system_process_twig_files'][] = rel_to($sysFile, $root . '/');
    }
    if (truthy($sys['pages']['frontmatter']['process_twig'] ?? null)) $twig['frontmatter_twig'] = true;
    $twig['safe_functions'] = array_values(array_unique(array_merge($twig['safe_functions'], token_list($sys['twig']['safe_functions'] ?? []))));
    $twig['safe_filters']   = array_values(array_unique(array_merge($twig['safe_filters'],   token_list($sys['twig']['safe_filters'] ?? []))));
    $undef = $sys['twig']['undefined_functions'] ?? null;
    if (($sysFile === $systemYaml && $undef === null) || truthy($undef)) {
        $twig['undefined_functions_was_on'] = true;
    }
}

$globalTwig  = $twig['system_process_twig'] || $twig['frontmatter_twig'];
$funcPages   = $filterPages = $methodPages = [];
$pageFiles   = md_files($pagesDir);
$pagesRoot   = realpath($pagesDir) ?: '';

foreach ($pageFiles as $file) {
    $raw = (string) @file_get_contents($file);
    if ($raw === '') continue;
    [$fm, $body] = split_frontmatter($raw);
    if ($fm === '') continue;
    try { $parsed = (YAML)::parse($fm); } catch (\Throwable) { continue; }
    if (!is_array($parsed)) continue;

    $rel      = rel_to($file, $pagesRoot);
    $pageTwig = truthy($parsed['process']['twig'] ?? null);
    if ($pageTwig) $twig['pages_with_twig'][] = $rel;
    if (!$pageTwig && !$globalTwig) continue;

    if (preg_match('/\{\{[^}]*\bconfig\b|\{%[^%]*\bconfig\b/', $body) === 1) $twig['config_pages'][] = $rel;

    $tokens = extract_twig_tokens($body);
    foreach ($tokens['functions'] as $fn) $funcPages[$fn][]   = $rel;
    foreach ($tokens['filters']   as $fl) $filterPages[$fl][] = $rel;
    foreach ($tokens['methods']   as $m)  $methodPages[$m][]  = $rel;
}

$twig['gate_needed']          = $twig['pages_with_twig'] !== [] || $globalTwig;
$twig['config_access_needed'] = $twig['config_pages'] !== [];

$baseline = sandbox_baseline($coreSecurity);
$coreFns  = array_flip($baseline['functions']);
$coreFls  = array_flip($baseline['filters']);
$deny     = array_flip(SANDBOX_DENYLIST);

$twig['sandbox_functions_add'] = [];
$twig['sandbox_filters_add']   = [];
$twig['safe_functions_add']    = [];
$twig['safe_filters_add']      = [];
$twig['plugin_provided']       = [];
$twig['twig_builtins']         = [];
$twig['blocked_dangerous']     = [];
$twig['blocked_by_design']     = [];
$twig['token_pages']           = [];

foreach ($funcPages as $fn => $pages) {
    $fn = (string) $fn;
    $twig['token_pages'][$fn] = $pages[0] ?? null;
    if (isset($coreFns[$fn])) continue;
    if (is_dangerous_function($fn))          { $twig['blocked_dangerous'][] = $fn; continue; }
    if (isset($deny[strtolower($fn)]))       { $twig['blocked_by_design'][] = $fn; continue; }
    $twig['sandbox_functions_add'][] = $fn;
    if (function_exists($fn))                                  $twig['safe_functions_add'][] = $fn;
    elseif (in_array(strtolower($fn), TWIG_BUILTINS, true))    $twig['twig_builtins'][]      = $fn;
    else                                                       $twig['plugin_provided'][]    = $fn;
}
foreach ($filterPages as $fl => $pages) {
    $fl = (string) $fl;
    $twig['token_pages'][$fl] = $pages[0] ?? null;
    if (isset($coreFls[$fl])) continue;
    if (is_dangerous_function($fl)) { $twig['blocked_dangerous'][] = $fl; continue; }
    $twig['sandbox_filters_add'][] = $fl;
    if (function_exists($fl))                                  $twig['safe_filters_add'][] = $fl;
    elseif (in_array(strtolower($fl), TWIG_BUILTINS, true))    $twig['twig_builtins'][]    = $fl;
    else                                                       $twig['plugin_provided'][]  = $fl;
}

$mediaMap = [];
foreach (MEDIA_METHODS as $m) $mediaMap[strtolower($m)] = $m;
$twig['methods_add']        = [];   // class => [method]
$twig['methods_unresolved'] = [];
foreach ($methodPages as $m => $pages) {
    $m  = (string) $m;
    $lc = strtolower($m);
    if (!isset($mediaMap[$lc]) || !$baseline['available']) { $twig['methods_unresolved'][] = $m; continue; }
    if (in_array($lc, $baseline['methods'][MEDIUM_CLASS] ?? [], true)) continue;  // core already allows it
    $twig['methods_add'][MEDIUM_CLASS][] = $mediaMap[$lc];
}

foreach (['sandbox_functions_add', 'sandbox_filters_add', 'safe_functions_add', 'safe_filters_add',
          'plugin_provided', 'twig_builtins', 'blocked_dangerous', 'blocked_by_design', 'methods_unresolved'] as $k) {
    $twig[$k] = array_values(array_unique($twig[$k]));
    sort($twig[$k]);
}

// The exact lists to write. Flat sandbox lists are replaced wholesale and the
// per-class lists merge by position, so both must be written FULL (core
// defaults ∪ additions) or core's own entries are lost.
$twig['write'] = [
    'security.twig_content.process_enabled' => $twig['gate_needed'],
    'security.twig_content.config_access'   => $twig['config_access_needed'],
    'system.twig.safe_functions'            => array_values(array_unique(array_merge($twig['safe_functions'], $twig['safe_functions_add']))),
    'system.twig.safe_filters'              => array_values(array_unique(array_merge($twig['safe_filters'], $twig['safe_filters_add']))),
    // Only emitted when core's defaults are readable — see the index-merge trap.
    'security.twig_sandbox.allowed_functions' => ($baseline['available'] && $twig['sandbox_functions_add'])
        ? array_values(array_unique(array_merge($baseline['functions'], $twig['sandbox_functions_add']))) : [],
    'security.twig_sandbox.allowed_filters'   => ($baseline['available'] && $twig['sandbox_filters_add'])
        ? array_values(array_unique(array_merge($baseline['filters'], $twig['sandbox_filters_add']))) : [],
];
if ($twig['methods_add'] && $baseline['available']) {
    $merged = $baseline['methods'];
    foreach ($twig['methods_add'] as $class => $names) {
        $merged[$class] = array_values(array_unique(array_merge($merged[$class] ?? [], array_map('strtolower', $names))));
    }
    $rows = [];
    foreach ($merged as $class => $list) $rows[] = ['class' => $class, 'methods' => implode(', ', $list)];
    $twig['write']['security.twig_sandbox.allowed_methods'] = $rows;
}
$twig['baseline_available'] = $baseline['available'];
$report['twig'] = $twig;

// ─── 5. URL-based image actions ─────────────────────────────────────────────

const IMAGE_URL_ACTIONS = [
    'resize', 'forceResize', 'cropResize', 'crop', 'zoomCrop',
    'negate', 'brightness', 'contrast', 'grayscale', 'emboss',
    'smooth', 'sharp', 'edge', 'colorize', 'sepia', 'enableProgressive',
    'rotate', 'flip', 'fixOrientation', 'gaussianBlur', 'format', 'create', 'fill', 'merge',
];
const IMAGE_RESIZE_ARGS = [
    'resize' => [0, 1], 'forceResize' => [0, 1], 'cropResize' => [0, 1],
    'crop' => [0, 1, 2, 3], 'zoomCrop' => [0, 1],
];

/** A reference the browser fetches from another host never hits Grav's handler. */
function media_ref_is_external(string $path): bool
{
    if (str_starts_with($path, '//')) return true;
    return (bool) preg_match('~^(?:https?|ftp|ftps|wss?)://~i', $path);
}

/** A co-located page-media reference is resolved through the media object — no toggle needed. */
function media_ref_is_page_media(string $path, string $pageDir, string $pagesRoot): bool
{
    if (preg_match('~^[a-zA-Z][a-zA-Z0-9+.\-]*://~', $path)) return false;
    if ($path === '' || $path[0] === '/' || $path[0] === '\\') return false;
    $real = realpath($pageDir . '/' . $path);
    if ($real === false || !is_file($real)) return false;
    $rootReal = realpath($pagesRoot);
    return $rootReal !== false && str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR);
}

$imgRe  = '~([^\s"\'`()<>\[\]]+?\.(?:jpe?g|png|gif|webp|avif|bmp))\?([^\s"\'`()<>]+)~i';
$actSet = array_flip(IMAGE_URL_ACTIONS);
$sysCfg = y_parse($systemYaml) ?? [];
$maxPixels = is_numeric($sysCfg['images']['max_pixels'] ?? null) ? (int) $sysCfg['images']['max_pixels'] : 25000000;

$images = [
    'page_hits' => [], 'template_hits' => [], 'actions' => [], 'oversized' => [],
    'max_pixels' => $maxPixels,
    'already_on' => truthy($sysCfg['images']['url_actions'] ?? null),
];

$collectActions = function (string $text, string $label) use ($imgRe, $actSet, $maxPixels, &$images): array {
    if (!str_contains($text, '?') || !preg_match_all($imgRe, $text, $matches, PREG_SET_ORDER)) return [];
    $found = [];
    foreach ($matches as $m) {
        if (media_ref_is_external($m[1])) continue;
        foreach (explode('&', str_replace('&amp;', '&', $m[2])) as $pair) {
            if ($pair === '') continue;
            [$key, $val] = array_pad(explode('=', $pair, 2), 2, '');
            if (!isset($actSet[$key])) continue;           // strict, case-sensitive, as core is
            $found[$key] = true;
            if ($maxPixels > 0 && isset(IMAGE_RESIZE_ARGS[$key])) {
                $args = explode(',', $val);
                $pos  = IMAGE_RESIZE_ARGS[$key];
                $w = $args[$pos[count($pos) - 2]] ?? null;
                $h = $args[$pos[count($pos) - 1]] ?? null;
                if (is_numeric($w) && is_numeric($h) && (int) $w > 0 && (int) $h > 0 && ((int) $w * (int) $h) > $maxPixels) {
                    $images['oversized'][] = $label . ': ' . $key . '=' . $val;
                }
            }
        }
    }
    return array_keys($found);
};

foreach ($pageFiles as $file) {
    $raw = (string) @file_get_contents($file);
    if ($raw === '' || !str_contains($raw, '?')) continue;
    $rel = rel_to($file, $pagesRoot);
    $hits = [];
    if (preg_match_all($imgRe, $raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            if (media_ref_is_page_media($m[1], dirname($file), $pagesRoot)) continue;
            foreach ($collectActions($m[0], $rel) as $a) $hits[$a] = true;
        }
    }
    if ($hits) $images['page_hits'][$rel] = array_keys($hits);
}

$themesRoot = realpath($userDir . '/themes') ?: '';
if ($themesRoot !== '') {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themesRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        /** @var SplFileInfo $f */
        if ($f->isDir() || !in_array(strtolower($f->getExtension()), ['twig', 'html', 'htm'], true)) continue;
        $raw = (string) @file_get_contents($f->getPathname());
        if ($raw === '' || !str_contains($raw, '?')) continue;
        $rel  = rel_to($f->getPathname(), $themesRoot);
        $acts = $collectActions($raw, $rel);
        if ($acts) $images['template_hits'][$rel] = $acts;
    }
}

$all = [];
foreach ($images['page_hits'] as $a)     foreach ($a as $x) $all[$x] = true;
foreach ($images['template_hits'] as $a) foreach ($a as $x) $all[$x] = true;
$images['actions']   = array_keys($all);
$images['oversized'] = array_values(array_unique($images['oversized']));
$images['needed']    = $images['page_hits'] !== [] || $images['template_hits'] !== [];
$report['images']    = $images;

// ─── 6. raw HTML tags the 2.0 GFM tagfilter escapes ─────────────────────────

$tagRe      = '/<(title|textarea|style|xmp|iframe|noembed|noframes|script|plaintext)\b/i';
$activeTags = ['script' => true, 'iframe' => true, 'noembed' => true, 'noframes' => true];
$remoteRe   = '~\[(?:plugin:)?(?:page|content)-inject[^\n]{0,200}?remote://~i';

$rawHtml = [
    'page_hits' => [], 'tags' => [], 'active_tags' => [], 'remote_pages' => [],
    'already_off' => ($sysCfg['pages']['markdown']['gfm']['tagfilter'] ?? null) === false,
];

foreach ($pageFiles as $file) {
    $raw = (string) @file_get_contents($file);
    if ($raw === '') continue;
    [, $body] = split_frontmatter($raw);
    if ($body === '') continue;
    // Fenced blocks and code spans are escaped by Parsedown anyway.
    $body = preg_replace('/^[ \t]*(```|~~~).*?^[ \t]*\1[ \t]*$/ms', '', $body) ?? $body;
    $body = preg_replace('/`[^`\n]*`/', '', $body) ?? $body;
    $rel  = rel_to($file, $pagesRoot);

    if (str_contains($body, 'remote://') && preg_match($remoteRe, $body)) $rawHtml['remote_pages'][] = $rel;
    if (!str_contains($body, '<') || !preg_match_all($tagRe, $body, $matches)) continue;

    $tags = [];
    foreach ($matches[1] as $t) $tags[strtolower($t)] = true;
    $rawHtml['page_hits'][$rel] = array_keys($tags);
}
$allTags = [];
foreach ($rawHtml['page_hits'] as $tags) foreach ($tags as $t) $allTags[$t] = true;
$rawHtml['tags']        = array_keys($allTags);
$rawHtml['active_tags'] = array_keys(array_intersect_key($allTags, $activeTags));
$rawHtml['needed']      = $rawHtml['page_hits'] !== [];
$report['raw_html']     = $rawHtml;

// ─── output ─────────────────────────────────────────────────────────────────

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

function h(string $title): void { echo "\n" . str_repeat('=', 72) . "\n{$title}\n" . str_repeat('=', 72) . "\n"; }
function li(string $s): void { echo "  - {$s}\n"; }
function yesno(bool $b): string { return $b ? 'yes' : 'no'; }
function listing(array $v, int $limit = 30): string
{
    if (!$v) return '(none)';
    $shown = array_slice($v, 0, $limit);
    return implode(', ', $shown) . (count($v) > $limit ? ' … +' . (count($v) - $limit) . ' more' : '');
}

$e = $report['environment'];
h('1. ENVIRONMENT');
li("root: {$e['root']}");
li('PHP: ' . $e['php'] . ($e['php_ok'] ? ' (ok for 2.0)' : '  ** Grav 2.0 needs PHP 8.3+ **'));
li('Grav core: ' . ($e['grav_version'] ?? 'unknown') . ($e['core_is_2x'] ? ' (2.x in place)' : ' (still 1.x — swap core before applying config changes)'));
li('vendor/autoload.php: ' . yesno($e['vendor_autoload']));
li('user/ writable: ' . yesno($e['user_writable']));
if (!$report['twig']['baseline_available']) {
    li("** system/config/security.yaml doesn't carry the 2.0 twig_sandbox defaults — allowlists can't be written as a full union yet. Re-run once core is 2.0. **");
}
foreach ($report['network_notes'] as $n) li('note: ' . $n);

h('2. PLUGINS & THEMES');
foreach (['plugins', 'themes'] as $kind) {
    echo "\n{$kind}:\n";
    if (!$report['packages'][$kind]) { li('(none installed)'); continue; }
    foreach ($report['packages'][$kind] as $slug => $p) {
        $flags = [];
        if ($p['symlink'])     $flags[] = 'symlink';
        if (!$p['enabled'])    $flags[] = 'disabled';
        if ($p['protected'])   $flags[] = 'protected';
        if ($p['critical'])    $flags[] = 'critical';
        if ($p['replaced_by']) $flags[] = 'replaced_by=' . $p['replaced_by'];
        if ($p['update'])      $flags[] = 'update→' . $p['update'];
        printf("  %-28s %-12s %-13s %s%s\n",
            $slug,
            $p['installed'] !== '' ? $p['installed'] : '?',
            $p['status'],
            $p['reason'],
            $flags ? '  [' . implode(' ', $flags) . ']' : '');
    }
}
$blockers = [];
foreach (CRITICAL_PLUGINS as $slug) {
    $p = $report['packages']['plugins'][$slug] ?? null;
    if ($slug === 'admin2' || $slug === 'api') {
        if (isset($report['packages']['plugins']['admin']) && $p === null) {
            $blockers[] = "'{$slug}' is not installed but the site uses the classic admin — install it or you lose admin access";
        }
        continue;
    }
    if ($p === null) continue;
    if (!$p['enabled']) $blockers[] = "'{$slug}' is disabled — a working admin needs it";
    if ($p['min_version'] && $p['installed'] && version_compare($p['installed'], $p['min_version'], '<')) {
        $blockers[] = "'{$slug}' is at v{$p['installed']} but 2.0 needs v{$p['min_version']}+";
    }
}
if ($blockers) { echo "\nBLOCKERS:\n"; foreach ($blockers as $b) li($b); }
if ($report['admin_route']) {
    echo "\n";
    li("custom admin route '{$report['admin_route']}' — copy it to user/config/plugins/admin2.yaml as `route: {$report['admin_route']}`");
}

h('3. ACCOUNTS & GROUPS');
if (!$report['accounts']) li('(no user/accounts/)');
foreach ($report['accounts'] as $file => $a) {
    if (isset($a['error'])) { li("{$file}: {$a['error']}"); continue; }
    $add = $a['add'] ? implode(', ', array_map(static fn($k, $v) => $k . ': ' . var_export($v, true), array_keys($a['add']), $a['add'])) : '(nothing to add)';
    echo "  {$file}\n";
    li("  add → {$add}");
    // Only a concern for an account that HAD admin grants: a frontend-only
    // account is supposed to come out with no api.* access at all.
    if ($a['add'] && !$a['has_access']) li('  ** ends up WITHOUT api.access — this account would 403 on every Admin 2.0 action **');
    if ($a['language'] && !$a['language_set']) li("  carry admin language '{$a['language']}' → admin_next.preferences.adminLanguage");
    foreach ($a['notes'] as $n) li("  note[{$n['code']}]: {$n['key']}");
}
echo "\ngroups (user/config/groups.yaml): " . ($report['groups_file'] ? 'present' : 'absent') . "\n";
foreach ($report['groups'] as $name => $g) {
    $add = $g['add'] ? implode(', ', array_keys($g['add'])) : '(nothing to add)';
    echo "  {$name}\n";
    li("  add → {$add}");
    if ($g['add'] && !$g['has_access']) li('  ** group grants no api.access — members relying on it cannot use Admin 2.0 **');
    foreach ($g['notes'] as $n) li("  note[{$n['code']}]: {$n['key']}");
}

$t = $report['twig'];
h('4. TWIG IN CONTENT');
li('pages with `process: twig: true`: ' . count($t['pages_with_twig']) . ($t['pages_with_twig'] ? ' (' . listing($t['pages_with_twig'], 8) . ')' : ''));
li('system.yaml pages.process.twig on: ' . yesno($t['system_process_twig']) . ($t['system_process_twig_files'] ? ' in ' . listing($t['system_process_twig_files']) : ''));
li('system.yaml pages.frontmatter.process_twig on: ' . yesno($t['frontmatter_twig']));
li('1.x twig.undefined_functions was on: ' . yesno($t['undefined_functions_was_on']) . ' (removed in 2.0 — unlisted names now hard-fail)');
li('existing safe_functions: ' . listing($t['safe_functions']));
li('existing safe_filters: ' . listing($t['safe_filters']));
echo "\nDECISIONS:\n";
li('security.twig_content.process_enabled → ' . ($t['gate_needed'] ? 'TRUE' : 'leave false'));
li('security.twig_content.config_access → ' . ($t['config_access_needed'] ? 'TRUE (' . listing($t['config_pages'], 6) . ')' : 'leave false'));
li('security.twig_content.editor_enabled → leave FALSE (opt in deliberately after migrating)');
li('strip from system.yaml: twig.undefined_functions, twig.undefined_filters' . ($t['system_process_twig'] ? ', pages.process.twig' : ''));
if ($t['safe_functions_add']) li('add to system.twig.safe_functions: ' . listing($t['safe_functions_add']));
if ($t['safe_filters_add'])   li('add to system.twig.safe_filters: ' . listing($t['safe_filters_add']));
if ($t['sandbox_functions_add']) li('add to security.twig_sandbox.allowed_functions: ' . listing($t['sandbox_functions_add']));
if ($t['sandbox_filters_add'])   li('add to security.twig_sandbox.allowed_filters: ' . listing($t['sandbox_filters_add']));
foreach ($t['methods_add'] as $class => $names) li("add to security.twig_sandbox.allowed_methods under {$class}: " . listing($names));
if ($t['twig_builtins'])      li("TWIG BUILT-INS not in 2.0's default allowlist (allowlisting them is the fix — `|raw` in content is the usual one): " . listing($t['twig_builtins']));
if ($t['plugin_provided'])    li('PLUGIN-PROVIDED (allowlist them, but the plugin must also register them — ideally via onBuildTwigSandboxPolicy): ' . listing($t['plugin_provided']));
if ($t['methods_unresolved']) li('UNRESOLVED methods (map to the owning class by hand, or they fail): ' . listing($t['methods_unresolved']));
if ($t['blocked_dangerous'])  li('REFUSED (Utils::isDangerousFunction — never allowlist, rework the content): ' . listing($t['blocked_dangerous']));
if ($t['blocked_by_design'])  li('REFUSED (sandbox denylist — never allowlist, rework the content): ' . listing($t['blocked_by_design']));
$hasSandboxWrite = $t['sandbox_functions_add'] || $t['sandbox_filters_add'] || $t['methods_add'];

if ($hasSandboxWrite && !$t['baseline_available']) {
    echo "\n** Not printing the sandbox lists: core's 2.0 defaults aren't on disk yet (no twig_sandbox block in\n"
       . "   system/config/security.yaml), so the union would be a PARTIAL list and would wipe core's own\n"
       . "   entries. Swap core to 2.0, then re-run. **\n";
} elseif ($hasSandboxWrite) {
    echo "\nWrite these as FULL lists (core defaults + additions) — a partial list drops core's own entries:\n";
    foreach (['security.twig_sandbox.allowed_functions', 'security.twig_sandbox.allowed_filters'] as $k) {
        if (empty($t['write'][$k])) continue;
        echo "\n  {$k} (" . count($t['write'][$k]) . " entries):\n";
        foreach ($t['write'][$k] as $v) echo "    - {$v}\n";
    }
    if (!empty($t['write']['security.twig_sandbox.allowed_methods'])) {
        echo "\n  security.twig_sandbox.allowed_methods:\n";
        foreach ($t['write']['security.twig_sandbox.allowed_methods'] as $row) {
            echo "    - class: '{$row['class']}'\n      methods: '{$row['methods']}'\n";
        }
    }
}

$i = $report['images'];
h('5. URL-BASED IMAGE ACTIONS (system.images.url_actions)');
li('already on: ' . yesno($i['already_on']));
li('needed: ' . yesno($i['needed']));
if ($i['needed']) {
    li('actions used: ' . listing($i['actions']));
    foreach ($i['page_hits'] as $p => $a)     li("page {$p}: " . implode(', ', $a));
    foreach ($i['template_hits'] as $p => $a) li("template {$p}: " . implode(', ', $a));
    if ($i['oversized']) li('OVER max_pixels (' . number_format($i['max_pixels']) . ') — refused even with the toggle on: ' . listing($i['oversized']));
}

$r = $report['raw_html'];
h('6. RAW HTML TAGS (pages.markdown.gfm.tagfilter)');
li('already off: ' . yesno($r['already_off']));
li('needed: ' . yesno($r['needed']));
if ($r['needed']) {
    li('tags found: ' . listing($r['tags']));
    if ($r['active_tags']) li('ACTIVE tags (run code / embed third-party documents — review before disabling the filter): ' . listing($r['active_tags']));
    foreach ($r['page_hits'] as $p => $tags) li("page {$p}: " . implode(', ', $tags));
}
if ($r['remote_pages']) li('remote:// injections that could not be scanned: ' . listing($r['remote_pages']));

echo "\n";
