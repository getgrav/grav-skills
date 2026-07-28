#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * grav2-perms.php — translate classic `admin.*` permissions into the `api.*`
 * set Grav 2.0 actually enforces, across `user/accounts/*.yaml` and
 * `user/config/groups.yaml`, and carry each account's classic admin UI
 * language into Admin 2.0's preference store.
 *
 * Additive and idempotent:
 *   - existing `admin.*` entries are left in place (the account keeps working
 *     on classic admin during a transition)
 *   - an `api.*` value already set at the target — or at any ancestor of it —
 *     always wins, so an explicit `api.system: false` deny is never punched
 *     through by a mapped `api.system.write: true`
 *   - re-running changes nothing once applied
 *
 * Dry-run by default. Nothing is written without `--apply`.
 *
 *   php grav2-perms.php --root=/srv/site            # show the diff it would make
 *   php grav2-perms.php --root=/srv/site --apply
 *   php grav2-perms.php --apply --skip-language     # permissions only
 *
 * NOTE: files are rewritten through a YAML parse/dump round-trip, so comments
 * and hand formatting in account/group files are not preserved. Review with
 * `git diff` (or take a copy of `user/accounts/` first).
 *
 * Requires PHP 8.1+ and the site's own `vendor/` (for Symfony Yaml).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$opts = getopt('', ['root::', 'apply', 'skip-language', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "usage: php grav2-perms.php [--root=PATH] [--apply] [--skip-language]\n");
    exit(0);
}
$root     = rtrim((string) ($opts['root'] ?? getcwd()), '/');
$apply    = isset($opts['apply']);
$doLang   = !isset($opts['skip-language']);

if (!is_dir($root . '/user')) {
    fwrite(STDERR, "error: no user/ directory under {$root} — point --root at the Grav site root.\n");
    exit(1);
}
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;
if (!class_exists('Symfony\\Component\\Yaml\\Yaml')) {
    fwrite(STDERR, "error: Symfony Yaml not available. Expected {$root}/vendor/autoload.php.\n");
    exit(1);
}

const YAML = '\\Symfony\\Component\\Yaml\\Yaml';

/** Classic permissions Grav 2.0 still reads under their ORIGINAL admin.* name. */
const ADMIN_ONLY_PERMS = ['pages_twig', 'impersonate'];

function y_parse(string $path): ?array
{
    if (!is_file($path)) return null;
    try { $d = (YAML)::parseFile($path); } catch (\Throwable) { return null; }
    return is_array($d) ? $d : null;
}

function truthy(mixed $v): bool
{
    return $v === true || $v === 1 || $v === '1'
        || (is_string($v) && in_array(strtolower($v), ['true', 'yes', 'on'], true));
}

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
 * Which `api.*` permission(s) a classic `admin.<key>` becomes.
 *
 * Deliberately NOT a 1:1 rename: the API registers its own permission set and
 * only `super`, `pages` and `users` happen to land on a name that exists in
 * both. Copying the rest verbatim yields keys nothing reads, so the account
 * looks provisioned and is granted nothing. The mapping never grants MORE
 * authority than the classic permission did — where 2.0 has no equivalent at
 * the same granularity, the read side is granted and the shortfall reported.
 */
function perm_targets(string $key, array &$notes): array
{
    $map = [
        'login'       => ['api.access'],
        'super'       => ['api.super'],
        'pages'       => ['api.pages', 'api.media'],   // classic Pages covered media
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
        return [];   // 2.0 still reads these under their classic name
    }
    if ($key === 'configuration') return ['api.config'];
    if (str_starts_with($key, 'configuration.') || str_starts_with($key, 'configuration_')) {
        // 2.0 has no per-section config permission; read-only rather than
        // promoting a single-page editor to full system.yaml/security.yaml.
        $notes[] = ['code' => 'config_partial', 'key' => 'admin.' . $key];
        return ['api.config.read'];
    }
    // Third-party convention: a plugin registers both admin.<slug> and
    // api.<slug> (flex-objects does), so a verbatim twin is the right guess.
    $notes[] = ['code' => 'verbatim', 'key' => 'admin.' . $key];
    return ['api.' . $key];
}

/** Set a dot-path inside a nested map; false when a scalar blocks the path. */
function access_set_nested(array &$node, string $path, mixed $value): bool
{
    $parts = explode('.', $path);
    $last  = array_pop($parts);
    $ref   = &$node;
    foreach ($parts as $part) {
        if (!array_key_exists($part, $ref)) $ref[$part] = [];
        elseif (!is_array($ref[$part])) return false;
        $ref = &$ref[$part];
    }
    $ref[$last] = $value;
    return true;
}

/**
 * @return array{0: array, 1: int, 2: bool}  [new access map, keys added, holds api.access]
 */
function mirror_access(array $access, array &$notes): array
{
    $flat     = flatten_access($access);
    $classic  = [];
    $existing = [];
    foreach ($flat as $k => $v) {
        if (str_starts_with($k, 'admin.')) $classic[substr($k, 6)] = $v;
        elseif ($k === 'api' || str_starts_with($k, 'api.')) $existing[$k] = $v;
    }

    $hasAccessNow = truthy($existing['api.access'] ?? null) || truthy($existing['api.super'] ?? null);
    if (!$classic) return [$access, 0, $hasAccessNow];

    $proposed = [];
    foreach ($classic as $key => $value) {
        foreach (perm_targets((string) $key, $notes) as $target) {
            if (!array_key_exists($target, $proposed) || (!truthy($proposed[$target]) && truthy($value))) {
                $proposed[$target] = $value;
            }
        }
    }
    // Every API endpoint checks api.access before the specific permission, and
    // no classic permission corresponds to it — without this, a mirrored
    // non-super account can log in and then 403 on everything.
    if (!isset($proposed['api.access'])) {
        foreach ($proposed as $value) {
            if (truthy($value)) { $proposed['api.access'] = true; break; }
        }
    }

    $writable = [];
    foreach ($proposed as $target => $value) {
        $probe = $target;
        $conflict = null;
        while (true) {
            if (array_key_exists($probe, $existing)) { $conflict = $probe; break; }
            $pos = strrpos($probe, '.');
            if ($pos === false) break;
            $probe = substr($probe, 0, $pos);
        }
        if ($conflict !== null) {
            if ($conflict !== $target) $notes[] = ['code' => 'preset', 'key' => $target . ' (covered by ' . $conflict . ')'];
            continue;
        }
        $writable[$target] = $value;
    }
    if (!$writable) return [$access, 0, $hasAccessNow];

    // Write back in whichever shape the source used, so the file still reads
    // the way its author wrote it.
    $nested = isset($access['admin']) && is_array($access['admin']);
    $added  = 0;
    foreach ($writable as $target => $value) {
        $path = substr($target, 4);
        if ($nested) {
            $api = (isset($access['api']) && is_array($access['api'])) ? $access['api'] : [];
            if (access_set_nested($api, $path, $value)) {
                $access['api'] = $api;
                $added++;
                continue;
            }
            // A scalar sits where a sub-map is needed — fall back to the dotted
            // spelling, which Grav flattens to exactly the same key.
        }
        $access[$target] = $value;
        $added++;
    }

    $flatNew   = flatten_access($access);
    $hasAccess = truthy($flatNew['api.access'] ?? null) || truthy($flatNew['api.super'] ?? null);

    return [$access, $added, $hasAccess];
}

function dump_yaml(array $data): string
{
    return (YAML)::dump($data, 6, 2);
}

// ─── run ────────────────────────────────────────────────────────────────────

$userDir   = $root . '/user';
$notes     = [];
$noAccess  = [];
$totalKeys = 0;
$touched   = 0;
$langs     = 0;

echo ($apply ? "APPLYING" : "DRY RUN (pass --apply to write)") . " — {$root}\n\n";

$acctDir = $userDir . '/accounts';
foreach (is_dir($acctDir) ? (scandir($acctDir) ?: []) : [] as $entry) {
    if ($entry === '.' || $entry === '..' || $entry[0] === '.') continue;
    if (!preg_match('/\.yaml$/i', $entry)) continue;

    $path = $acctDir . '/' . $entry;
    $data = y_parse($path);
    if ($data === null) { echo "  ! accounts/{$entry}: unparseable, skipped\n"; continue; }

    $added = 0;
    $changed = false;
    if (is_array($data['access'] ?? null)) {
        [$access, $added, $hasAccess] = mirror_access($data['access'], $notes);
        if ($added > 0) {
            $data['access'] = $access;
            $changed = true;
            if (!$hasAccess) $noAccess[] = 'accounts/' . $entry;
        }
    }

    // Classic admin stored the per-user UI language at the top-level
    // `language:`; Admin 2.0 reads admin_next.preferences.adminLanguage. The
    // top-level key is left alone — Grav core still uses it.
    $langAdded = false;
    if ($doLang && is_string($data['language'] ?? null) && trim((string) $data['language']) !== '') {
        $prefs = $data['admin_next']['preferences'] ?? [];
        $prefs = is_array($prefs) ? $prefs : [];
        if (!is_string($prefs['adminLanguage'] ?? null) || $prefs['adminLanguage'] === '') {
            $prefs['adminLanguage'] = substr(trim((string) $data['language']), 0, 32);
            $adminNext = is_array($data['admin_next'] ?? null) ? $data['admin_next'] : [];
            $adminNext['preferences'] = $prefs;
            $data['admin_next'] = $adminNext;
            $changed = $langAdded = true;
            $langs++;
        }
    }

    if (!$changed) { echo "  = accounts/{$entry}: nothing to do\n"; continue; }

    $bits = [];
    if ($added)     $bits[] = "+{$added} api.* key(s)";
    if ($langAdded) $bits[] = 'admin language → admin_next.preferences.adminLanguage';
    echo "  * accounts/{$entry}: " . implode(', ', $bits) . "\n";
    $totalKeys += $added;
    $touched++;

    if ($apply && @file_put_contents($path, dump_yaml($data)) === false) {
        echo "  ! accounts/{$entry}: WRITE FAILED\n";
    }
}

$groupsFile = $userDir . '/config/groups.yaml';
if (!is_file($groupsFile)) {
    echo "\n  = user/config/groups.yaml: absent — nothing to migrate there\n";
} else {
    echo "\n";
    $data = y_parse($groupsFile);
    if ($data === null) {
        echo "\n  ! user/config/groups.yaml: unparseable, skipped\n";
    } else {
        $added = 0;
        foreach ($data as $name => $group) {
            if (!is_array($group) || !is_array($group['access'] ?? null)) continue;
            [$access, $n, $hasAccess] = mirror_access($group['access'], $notes);
            if ($n === 0) { echo "  = groups/{$name}: nothing to do\n"; continue; }
            $data[$name]['access'] = $access;
            $added += $n;
            echo "  * groups/{$name}: +{$n} api.* key(s)\n";
            if (!$hasAccess) $noAccess[] = 'groups/' . $name;
        }
        if ($added > 0) {
            $totalKeys += $added;
            $touched++;
            if ($apply && @file_put_contents($groupsFile, dump_yaml($data)) === false) {
                echo "  ! user/config/groups.yaml: WRITE FAILED\n";
            }
        }
    }
}

// ─── summary ────────────────────────────────────────────────────────────────

$byCode = [];
foreach ($notes as $n) {
    $byCode[$n['code']][$n['key']] = true;
}

echo "\n" . str_repeat('-', 72) . "\n";
echo ($apply ? 'Wrote ' : 'Would write ') . "{$totalKeys} api.* permission(s) across {$touched} file(s)";
echo $langs ? "; carried {$langs} admin language preference(s)" : '';
echo ".\n";

if ($noAccess) {
    echo "\nWARNING: these came out WITHOUT api.access and will 403 on every Admin 2.0 action:\n";
    foreach ($noAccess as $n) echo "  - {$n}\n";
}
if (isset($byCode['config_partial'])) {
    echo "\nNOTE: per-section config permissions have no 2.0 equivalent, so these became read-only\n"
       . "      api.config.read. Grant api.config.write by hand if those users should still save config:\n";
    foreach (array_keys($byCode['config_partial']) as $k) echo "  - {$k}\n";
}
if (isset($byCode['admin_only'])) {
    echo "\nNOTE: 2.0 still reads these under their classic admin.* name — left untouched:\n";
    foreach (array_keys($byCode['admin_only']) as $k) echo "  - {$k}\n";
}
if (isset($byCode['verbatim'])) {
    echo "\nNOTE: copied verbatim as api.<same-name> — these come from third-party plugins, so check\n"
       . "      each plugin's 2.0 release actually registers an api.* twin:\n";
    foreach (array_keys($byCode['verbatim']) as $k) echo "  - {$k}\n";
}
if (isset($byCode['preset'])) {
    echo "\nNOTE: skipped — an api.* value you already set covers them:\n";
    foreach (array_keys($byCode['preset']) as $k) echo "  - {$k}\n";
}
if (!$apply) echo "\nNothing was written. Re-run with --apply.\n";
