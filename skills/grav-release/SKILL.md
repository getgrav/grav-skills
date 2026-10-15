---
name: grav-release
description: Use when cutting a release of a Grav plugin (Team Grav / Trilby Media, e.g. `grav-plugin-*`) or Grav core itself. Covers the two release shapes — full git-flow when the work is on `develop`, and tag-only when the work is on a dedicated release branch (like flex-objects `1.4.0` or an older Grav maintenance line like `1.7`) — plus the prerelease decision (`testing: true` in blueprints, or an `-rc.`/`-beta.` version, or Grav core's `system/defines.php`), the bare-numeric tag/title rule (never `v`-prefixed), sourcing release notes from the top CHANGELOG block, and post-release verification. Trigger when the user says "release <plugin>", "cut a release", "tag and release", "get <plugin> out", "do a prerelease", or asks to run a git-flow release / `gh release` for a Grav plugin or Grav core.
---

# Releasing a Grav plugin (or Grav core)

Cut a release of a Grav plugin or Grav core the way Andy does it. There are **two shapes**, decided by which branch the release work sits on. The version is **already set** in `blueprints.yaml` and the `CHANGELOG.md` before you start — your job is to verify it, tag it, and publish, not to invent a version.

Releases always happen in the **source repo** (`~/Projects/grav/grav-plugin-<name>` or the Grav core repo), never in a symlinked site copy under `user/plugins/`.

## The one rule that bites: bare numeric, never `v`

Tags and release titles are **bare numeric**: `3.4.5`, `2.0.0-rc.1`, `1.4.0-rc.8`. **Never** `v`-prefixed. This applies to all three of:

1. the git tag — `git tag 3.4.5`
2. the `gh release create <tag>` argument — `gh release create 3.4.5`
3. the **`--title`** — `--title "3.4.5"`, NOT `--title "v3.4.5"`

A single `v`-prefixed tag/release breaks `git tag --sort=-v:refname` and GitHub's `releases/latest`, so GPM and the gpm CLI stop seeing it as latest. CHANGELOG headings like `# v3.4.5` are fine — that's just doc text. Only the tag, the release tag arg, and the title must be bare.

## Step 0 — preflight (always)

From inside the source repo:

```bash
cd ~/Projects/grav/grav-plugin-<name>
git branch --show-current          # which branch? decides the shape (see below)
git status --short                 # MUST be clean (or only the release-prep edits)
grep -E '^version' blueprints.yaml # the version being released
head -3 CHANGELOG.md               # top heading must match that version
git tag | grep -x <version>        # MUST be absent — tag must not already exist
git rev-parse --abbrev-ref @{u} 2>/dev/null && git log --oneline @{u}..HEAD  # unpushed commits?
gh auth status                     # logged in, correct account
git remote -v                      # confirm the repo (trilbymedia/* or getgrav/*)
```

Checklist before proceeding:

- **Working tree clean.** If the version bump / changelog aren't committed yet and it's a git-flow release, you'll commit them on the release branch (Path A). For tag-only (Path B) they should already be committed and pushed.
- **`blueprints.yaml` `version:` == top `CHANGELOG.md` heading.** They should already agree. If they don't, stop and ask — don't guess which is right.
- **CHANGELOG date is today.** The `## MM/DD/YYYY` line under the top heading should be today's date, not whenever the block was first drafted. Fix it if stale.
- **Tag does not already exist.**
- **Plugin is self-contained — run the checker, it must print `OK`.** GPM runs no `composer install`; the zip is the install. `php ~/.claude/skills/grav-release/scripts/check-vendor.php . HEAD` (after the release commit; before it, `git add -A` and pass `$(git write-tree)` as the ref). It archives the ref and fails on: a locked non-dev package not shipped, any `require-dev` package committed, `installed.php` `'dev' => true`, an autoloader entry pointing at missing library code, or `vendor/autoload.php` required but not shipped. Fixes: rebuild with `composer install --no-dev`; a build-time dependency that another required plugin ships at runtime and `replace` can't cover (kahunacart-newsletters' db-kit, which Strauss must see to rename into KahunaCart's copy) goes in composer.json `"extra": {"grav-release": {"provided-by": {"getgrav/grav-db-kit": "kahunacart"}}}`, and the checker accepts it only when blueprints.yaml lists that plugin under `dependencies`; anything Grav core (or a required plugin) provides goes in composer `"replace": {"psr/log": "*"}`, never Strauss exclusions or `suggest` (exception: a dependency that needs a different major than core ships, like grav-plugin-github's psr/log 1.x); Andy's global `~/.gitignore` ignores `log/`, so a bundled `.../log/` dir needs a negation in the repo's `.gitignore`. Never tag a plugin that fails this check.
- **No development files in the zip — the same checker enforces it.** It fails when the archive carries `tests/` or `test/`, `phpunit.xml(.dist)`, a PHPUnit cache or `.github/`, and warns on paths that are usually development-only but that some plugins read at run time. The fix is the repo's `.gitattributes` (see "Keep development files out of the zip" below), added **in this release's commit**, so every plugin gets fixed on its next release rather than in a sweep of commits nobody tags.
- **The suite passes on the commit you tag.** Check that CI is green for develop's HEAD (`gh run list --limit 1`), or run the repo's whole suite locally, one suite at a time across all sessions. Run it again after the version bump when nothing else changed. A bump alone can trip a test that reads `blueprints.yaml`, as in KahunaCart 1.3.9, where a test wanted every paywall template stamped with the current version. A handful of filtered tests is not enough.
- **Never invent a version.** The version is whatever blueprints/changelog already say (typically last tag + 1). Don't bump it yourself per the changelog rules.

## Keep development files out of the zip

GPM installs the archive of the tag (GitHub's zipball, which is `git archive`), so whatever the archive exports is what every site downloads. A no-dev `vendor/` keeps PHPUnit out, but the plugin's own `tests/`, `phpunit.xml`, `.github/` and the like still ship unless the repo says otherwise. A CI job that runs `composer install --no-dev` doesn't help: nothing a workflow builds reaches GPM.

The fix is a `.gitattributes` at the repo root. Every plugin gets the standard block:

```
# Development files stay out of the release zip (GPM installs git archive of the tag)
/tests                 export-ignore
/test                  export-ignore
/phpunit.xml           export-ignore
/phpunit.xml.dist      export-ignore
/.phpunit.cache        export-ignore
/.phpunit.result.cache export-ignore
/.github               export-ignore
/.gitignore            export-ignore
/.gitattributes        export-ignore
```

Then look at whatever the checker still warns about and add a line for each path the plugin **never reads at run time**. These are decided per repo, never by default:

- `/docs`, `/tools`, `/bin`: usually generator scripts and developer docs, but check first (grep the plugin's PHP, CLI and templates for the path; some plugins serve docs or feed them to MCP/llms output).
- `/package.json`, `/package-lock.json`, and source folders that are built into shipped assets (`/desk-src`, `/admin-src`, …): export-ignore them when the built output is committed.
- `/node_modules`: keep it when the plugin loads from it (grav-plugin-presentation loads reveal.js, axios and js-base64 from there).
- Anything the plugin reads at run time stays, whatever its name: mailroom's `host/` recipes and `seed/`, KahunaCart's `classes/Testing` harness and scaffold templates under `classes/`.
- Repo-specific extras such as core's `/crowdin.yml`.

Rules:

1. **Add it in the release commit, before the tag.** `export-ignore` only affects archives made after the commit, so a tag cut before it still ships everything. On Path A the `.gitattributes` goes into the `release/X.Y.Z` commit with the version bump; on Path B it has to be committed and pushed on the line branch first.
2. **Don't sweep.** A repo without the file gets it on its own next release, not in a batch of commits that would sit untagged (DryDock flags every repo with commits after its last tag).
3. **Tests still run from a clone.** `export-ignore` only affects archives; the files stay in the repo.
4. **Re-run the checker** after adding it. It must print `OK`, and every remaining `WARN` about development paths should be something the plugin really reads.

## Decide the shape

- **On `develop`?** → **Path A: git-flow.** (standard stable plugins: tntsearch, etc.)
- **On a dedicated release branch** (e.g. flex-objects `1.4.0`, an older Grav maintenance line like `1.7`)? → **Path B: tag-only.** No merges. (Grav core's current 2.0.x stable is the exception — it's git-flow on `master`; see Grav core specifics.)

## Decide prerelease vs full release

Mark the GitHub release as `--prerelease` if **any** of:

- `blueprints.yaml` has `testing: true`
- the version contains `-rc.` or `-beta.` (e.g. `1.4.0-rc.8`, `2.0.0-beta.5`)
- **Grav core:** the `GRAV_VERSION` in `system/defines.php` is an rc/beta (core has no `blueprints.yaml`)

Otherwise it's a normal (latest) release — omit `--prerelease`.

### Stable but NOT latest (backport / old-line security fix)

A **full, stable** release can still need to stay off the `releases/latest` pointer — e.g. a security backport to an older maintenance line (`1.7.53.4` while `2.x` is current). This is **not** a prerelease (`GRAV_VERSION`/version is stable, not `-rc.`/`-beta.`), so don't use `--prerelease` — the notes wouldn't match and GPM's `?stable=1` feed would wrongly drop it. Instead pass **`--latest=false`** to `gh release create`, which publishes a normal, non-draft, stable release without moving `releases/latest`. GitHub also won't auto-promote it when a higher-semver tag (`2.2.0`) already exists, but `--latest=false` makes it explicit and safe regardless. Verify afterward with `gh api repos/getgrav/grav/releases/latest -q .tag_name` — it should still return the newest line's tag, not the backport.

## Commit rules

Per Andy's global git rules: **never add `Co-Authored-By:` or any AI-assistant coauthor trailer.** Commits are solely his.

---

## Path A — git-flow (work is on `develop`)

The version-prep edits (code fix, `blueprints.yaml` bump, `CHANGELOG.md` block) may be uncommitted on `develop` or already committed. This produces the canonical graph: a `release/X.Y.Z` branch merged `--no-ff` into `master`, tagged there, then the tag merged back into `develop`.

```bash
cd ~/Projects/grav/grav-plugin-<name>

# carries any uncommitted release-prep edits onto the release branch
git checkout -b release/<version>
git add -A
git commit -m "<short description of the release changes>"   # e.g. "PHP 8.4 deprecations"

# merge into master and tag THERE (master holds the stable line)
git checkout master
git merge --no-ff release/<version> -m "Merge branch 'release/<version>'"
git tag <version>

# back-merge the tag into develop so develop carries the release commit
git checkout develop
git merge --no-ff <version> -m "Merge tag '<version>' into develop"
git branch -d release/<version>

# push everything
git push origin master develop
git push origin <version>
```

Merge-commit message style matches existing history: `Merge branch 'release/X.Y.Z'` and `Merge tag 'X.Y.Z' into develop`.

Then create the GitHub release (Path A/B share this — see "Create the GitHub release").

---

## Path B — tag-only (work is on a release branch like `1.4.0` / `1.7`)

These lines (e.g. flex-objects `1.4.0`, an older Grav maintenance line like `1.7`) are **not** git-flow. The branch is the release line. The changelog + version are already committed and the branch is already pushed. You just tag the branch HEAD and publish — **no master/develop merges.**

```bash
cd ~/Projects/grav/grav-plugin-<name>     # or the Grav core repo
git status --short                         # clean
git log --oneline @{u}..HEAD               # should be empty — branch already pushed
git tag <version>                          # e.g. 1.4.0-rc.8
git push origin <version>
```

Then create the GitHub release with `--target <branch>` (the release branch name).

---

## Create the GitHub release (both paths)

Release notes come from the **top block of `CHANGELOG.md`** (the block for this version). Convert its bullets into the release body, grouped by `#new` / `#improved` / `#bugfix`.

```bash
gh release create <version> \
  --repo <org>/grav-plugin-<name> \
  --title "<version>" \              # bare numeric — NO v
  --target <branch> \               # master for Path A (incl. Grav core 2.0.x); the release branch (1.4.0 / 1.7) for Path B
  [--prerelease] \                  # include per the prerelease decision above
  --notes "### New
- ...

### Bugfix
- ..."
```

`--target` matters: Path A targets `master`; Path B targets the release branch.

## Verify after

```bash
gh release view <version> --repo <org>/grav-plugin-<name> \
  --json tagName,name,isPrerelease,isDraft,targetCommitish
```

Confirm: `name` is bare numeric, `isDraft:false`, and `isPrerelease` matches the decision. For git-flow, also confirm the graph: `master` at `Merge branch 'release/X.Y.Z'` (tagged), `develop` at `Merge tag 'X.Y.Z' into develop`.

## Grav core specifics

- Version lives in `system/defines.php` as `GRAV_VERSION`, not `blueprints.yaml`.
- **The current stable line (2.0.x) ships git-flow on `master` — Path A**, exactly like a plugin (release branch off `develop` → merge `--no-ff` into `master` → tag there → back-merge the tag into `develop`). Confirm by checking how the previous tag was cut: `git log --oneline master..develop` shows the prior `Merge branch 'release/X'` / `Merge tag 'X' into develop` pair when it's git-flow.
- **`2.0` is NOT the release branch** — it's the old beta/rc channel branch and now sits stale, well behind `develop`. Ignore it for 2.0.x stable releases; target `master`.
- **Older maintenance lines release Path B (tag-only) from their own line branch.** "Release 1.7" means tag the HEAD of the `1.7` branch and publish with `--target 1.7` — no `master`/`develop` merges. The branch name matches the line (`1.7`, `1.6`, …).
- Prerelease is decided by whether `GRAV_VERSION` is an rc/beta.
- A plugin can depend on a specific core version (its `blueprints.yaml` `dependencies`/`grav:` constraint) — if a plugin RC needs an unreleased core RC, the core has to ship first or the dependency won't resolve in GPM.

## Notes / gotchas

- The plugin under `user/plugins/<name>` in a test site may be a **symlink to the source repo** (release there) or a **GPM-installed copy** (read-only — not releasable; find its real repo under `~/Projects/grav/`).
- A prerelease never becomes GPM/GitHub "latest" — that's the point of `--prerelease` for the `-rc.`/`-beta.`/`testing:true` lines, so it stays off the `?stable=1` feed.
- If `git push` is blocked by the sandbox, retry the push with the sandbox disabled — pushing is an explicitly authorized step of a release.
