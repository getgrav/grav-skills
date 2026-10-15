<?php
/**
 * Checks that a Grav plugin ships self-contained: GPM just extracts the zip,
 * no `composer install` ever runs on a site.
 *
 *   php check-vendor.php <plugin-repo> [<git-ref>=HEAD]
 *
 * Works on `git archive <ref>`, so it sees exactly what a release ships
 * (a global gitignore that swallows `log/` shows up here, not on disk).
 *
 * FAIL when:
 *  - a non-dev package from composer.lock is not in vendor/ or vendor-prefixed/
 *  - a require-dev package is committed
 *  - vendor/composer/installed.php says 'dev' => true
 *  - an autoloader entry (psr-4, psr-0, classmap, files) points at a missing path
 *  - composer.json has require/autoload but vendor/autoload.php is not shipped
 *  - the archive carries development files no site ever runs: tests/ or test/,
 *    phpunit.xml(.dist), .phpunit.cache / .phpunit.result.cache, .github/.
 *    The fix is a .gitattributes with export-ignore lines (see SKILL.md), which
 *    only affects archives made after it is committed.
 *
 * WARN (look, don't block) when the archive carries paths that are usually
 * development-only but that some plugins read at run time: docs/, tools/, bin/,
 * package.json, package-lock.json, *-src/, node_modules/, .gitignore,
 * crowdin.yml. Presentation loads its node_modules/, for example, so each of
 * these is decided per repo.
 *
 * A locked package that another plugin ships for this one is allowed when
 * composer.json names it under extra.grav-release.provided-by and
 * blueprints.yaml lists that plugin under dependencies.
 * Exit code 0 = OK, 1 = FAIL, 2 = nothing to check.
 */

$repo = realpath($argv[1] ?? '.') ?: exit("no such repo\n");
$ref = $argv[2] ?? 'HEAD';
$name = basename($repo);

$tmp = sys_get_temp_dir() . '/check-vendor-' . getmypid() . '-' . md5($repo . $ref);
@mkdir($tmp, 0777, true);
$tmp = realpath($tmp);
$cmd = sprintf('git -C %s archive %s | tar -x -C %s 2>&1', escapeshellarg($repo), escapeshellarg($ref), escapeshellarg($tmp));
exec($cmd, $out, $rc);
if ($rc !== 0) {
    echo "SKIP $name: git archive $ref failed\n";
    exit(2);
}

$fail = [];
$warn = [];

// Development files in the archive. GPM installs the archive of the tag, so
// whatever git archive would export is what every site downloads.
$top = array_map('basename', array_merge(glob("$tmp/*") ?: [], glob("$tmp/.[!.]*") ?: []));
$devOnly = array_values(array_intersect($top, ['tests', 'test', 'phpunit.xml', 'phpunit.xml.dist', '.phpunit.cache', '.phpunit.result.cache', '.github']));
if ($devOnly) {
    $fail[] = 'development files ship in the archive: ' . implode(', ', $devOnly)
        . ' (add export-ignore lines to .gitattributes before tagging)';
}
$maybeDev = array_values(array_filter($top, fn ($f) => in_array($f, ['docs', 'tools', 'bin', 'package.json', 'package-lock.json', 'node_modules', '.gitignore', 'crowdin.yml'], true)
    || (str_ends_with($f, '-src') && is_dir("$tmp/$f"))));
if ($maybeDev) {
    $warn[] = 'usually development-only, check whether the plugin reads them at run time and export-ignore them if not: ' . implode(', ', $maybeDev);
}

if (!is_file("$tmp/composer.json")) {
    exec('rm -rf ' . escapeshellarg($tmp));
    foreach ($warn as $w) {
        echo "WARN $name@$ref: $w\n";
    }
    if ($fail) {
        foreach ($fail as $f) {
            echo "FAIL $name@$ref: $f\n";
        }
        exit(1);
    }
    echo "OK   $name@$ref (no composer.json, so no vendor to check)\n";
    exit(0);
}

$root = json_decode(file_get_contents("$tmp/composer.json"), true) ?: [];
$replaced = array_map('strtolower', array_keys($root['replace'] ?? []));

// A package another plugin ships for this one, named in composer.json as
// extra.grav-release.provided-by: {"vendor/package": "<plugin slug>"}. For a
// build-time dependency that `replace` can't cover, e.g. db-kit, which Strauss
// must see to rename into KahunaCart's copy. Only counts when blueprints.yaml
// lists that plugin under dependencies.
$dependencies = [];
if (is_file("$tmp/blueprints.yaml")) {
    $inDeps = false;
    foreach (file("$tmp/blueprints.yaml") as $line) {
        if (preg_match('/^dependencies:\s*$/', $line)) {
            $inDeps = true;
            continue;
        }
        if ($inDeps && preg_match('/^\S/', $line)) {
            break;
        }
        if ($inDeps && (preg_match('/\bname:\s*[\'"]?([\w-]+)/', $line, $m) || preg_match('/^\s*-\s*[\'"]?([\w-]+)[\'"]?\s*$/', $line, $m))) {
            $dependencies[] = strtolower($m[1]);
        }
    }
}
$providedBy = [];
foreach ($root['extra']['grav-release']['provided-by'] ?? [] as $package => $plugin) {
    $package = strtolower($package);
    $plugin = strtolower((string) $plugin);
    if (in_array($plugin, $dependencies, true)) {
        $providedBy[$package] = $plugin;
    } else {
        $fail[] = "composer.json says $package is provided by $plugin, but blueprints.yaml doesn't list $plugin under dependencies";
    }
}

$needsVendor = !empty($root['autoload'])
    || array_filter(array_keys($root['require'] ?? []), fn ($p) => str_contains($p, '/'));

// composer.lock: from the ref if committed, else the working copy
$lockFile = is_file("$tmp/composer.lock") ? "$tmp/composer.lock" : (is_file("$repo/composer.lock") ? "$repo/composer.lock" : null);
$lock = $lockFile ? json_decode(file_get_contents($lockFile), true) : null;

if ($needsVendor && !is_file("$tmp/vendor/autoload.php")) {
    $requiresIt = false;
    foreach (glob("$tmp/*.php") as $php) {
        $requiresIt = $requiresIt || str_contains(file_get_contents($php), 'vendor/autoload.php');
    }
    if ($requiresIt) {
        $fail[] = 'vendor/autoload.php is required by the plugin but not shipped';
    } else {
        $warn[] = 'composer.json has require/autoload but no vendor/autoload.php ships (the plugin never loads it)';
    }
}

if ($lock) {
    foreach ($lock['packages'] ?? [] as $p) {
        if (($p['type'] ?? '') === 'metapackage') {
            continue;
        }
        $n = strtolower($p['name']);
        if (in_array($n, $replaced, true)) {
            $warn[] = "composer.lock still lists $n, which composer.json replaces (stale lock; the next composer update drops it)";
            continue;
        }
        if (isset($providedBy[$n])) {
            if (is_dir("$tmp/vendor/$n") || is_dir("$tmp/vendor-prefixed/$n")) {
                $warn[] = "$n is provided by {$providedBy[$n]} but a copy ships here too";
            }
            continue;
        }
        if (!is_dir("$tmp/vendor/$n") && !is_dir("$tmp/vendor-prefixed/$n")) {
            $fail[] = "locked package $n {$p['version']} is not shipped"
                . (str_starts_with($n, 'psr/') ? ' (if Grav core provides it, put it in composer "replace")' : '');
        }
    }
    $dev = [];
    foreach ($lock['packages-dev'] ?? [] as $p) {
        $n = strtolower($p['name']);
        if (is_dir("$tmp/vendor/$n") || is_dir("$tmp/vendor-prefixed/$n")) {
            $dev[] = $n;
        }
    }
    if ($dev) {
        $fail[] = count($dev) . ' dev package(s) committed, e.g. ' . implode(', ', array_slice($dev, 0, 4)) . ' (rebuild with composer install --no-dev)';
    }
} elseif ($needsVendor) {
    $warn[] = 'no composer.lock, so the locked packages could not be checked';
}

foreach (['vendor', 'vendor-prefixed'] as $v) {
    $installed = "$tmp/$v/composer/installed.php";
    if (is_file($installed)) {
        $data = @include $installed;
        if (!empty($data['root']['dev']) && !empty($lock['packages-dev'])) {
            $fail[] = "$v/composer/installed.php has 'dev' => true (rebuild with composer install --no-dev)";
        }
    }
    foreach (['autoload_psr4.php', 'autoload_namespaces.php', 'autoload_classmap.php', 'autoload_files.php'] as $f) {
        $path = "$tmp/$v/composer/$f";
        if (!is_file($path)) {
            continue;
        }
        $map = (function ($path) { return include $path; })($path);
        $missing = [];
        $minor = [];
        foreach ($map as $key => $dirs) {
            foreach ((array) $dirs as $d) {
                if (file_exists($d)) {
                    continue;
                }
                $rel = str_starts_with($d, $tmp . '/') ? substr($d, strlen($tmp) + 1) : $d;
                $label = (is_string($key) && !preg_match('/^[0-9a-f]{32}$/', $key) ? "$key => " : '') . $rel;
                // A shipped library's code that is missing breaks the plugin. A missing
                // own-source dir, test dir, InstalledVersions, or a dev path repo (../) only warns.
                $harmless = !preg_match('#^vendor(-prefixed)?/#', $rel)
                    || str_ends_with($rel, 'composer/InstalledVersions.php')
                    || preg_match('#/tests?(/|$)#i', $rel);
                if ($harmless) {
                    $minor[] = $label;
                } else {
                    $missing[] = $label;
                }
            }
        }
        if ($missing) {
            $fail[] = "$v/composer/$f points at " . count($missing) . ' missing path(s): ' . implode(', ', array_slice($missing, 0, 5)) . (count($missing) > 5 ? ', …' : '');
        }
        if ($minor) {
            $warn[] = "$v/composer/$f points at " . count($minor) . ' missing non-library path(s): ' . implode(', ', array_slice($minor, 0, 3));
        }
    }
}

exec('rm -rf ' . escapeshellarg($tmp));

foreach ($warn as $w) {
    echo "WARN $name@$ref: $w\n";
}
if ($fail) {
    foreach ($fail as $f) {
        echo "FAIL $name@$ref: $f\n";
    }
    exit(1);
}
echo "OK   $name@$ref\n";
exit(0);
